<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

use InvalidArgumentException;

/**
 * consistentapi.modules_folder, validated and normalized.
 *
 * ModuleServiceProvider, consistent:create and consistent:rebuild read the folder through it, so the
 * route loader and both commands agree on where modules live and which namespace they get. Backslashes
 * count as slashes and repeated slashes collapse, so 'Domain\Modules' and 'Domain//Modules' both become
 * Domain/Modules. Every segment must be a PHP identifier: an empty folder, '..', 'my-modules' and the
 * like are rejected, since they would put modules outside app/ or into a namespace PHP cannot parse.
 *
 * @package Adonyarik\ConsistentApi\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class ModulesFolder
{
    /**
     * Holds the normalized folder; fromConfig() validates it first.
     *
     * @param  string  $relativePath  Folder relative to app/, segments separated by slashes, such as Domain/Modules
     */
    private function __construct(public readonly string $relativePath) {}

    /**
     * Validates and normalizes a configured value.
     *
     * @param  mixed  $value  Value of consistentapi.modules_folder
     *
     * @return self The normalized folder
     *
     * @throws InvalidArgumentException When the value is not a usable folder, see errorFor()
     */
    public static function fromConfig(mixed $value): self
    {
        $error = self::errorFor($value);

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        return new self(self::normalize((string) $value));
    }

    /**
     * Why a configured value is not a usable modules folder, null when it is.
     *
     * The value must be a string that, once normalized, is not empty and consists of PHP identifiers
     * (letters, digits and underscores, not starting with a digit) separated by slashes.
     *
     * @param  mixed  $value  Value of consistentapi.modules_folder
     *
     * @return string|null The error message, null for a valid folder
     */
    public static function errorFor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'consistentapi.modules_folder must be a string, such as "Modules".';
        }

        $normalized = self::normalize($value);

        if ($normalized === '') {
            return 'consistentapi.modules_folder must name a folder under app/, such as "Modules".';
        }

        foreach (explode('/', $normalized) as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment) !== 1) {
                return sprintf(
                    'consistentapi.modules_folder "%s" is not valid: every segment must be a PHP identifier (letters, digits and _), got "%s".',
                    $value,
                    $segment,
                );
            }
        }

        return null;
    }

    /**
     * Normalizes the separators of a value without validating it.
     *
     * Backslashes become slashes, repeated slashes collapse and surrounding slashes are trimmed. Used where
     * an invalid value must not throw, such as command descriptions.
     *
     * @param  string  $value  Value of consistentapi.modules_folder
     *
     * @return string The folder with single slashes and no surrounding ones
     */
    public static function normalize(string $value): string
    {
        return trim((string) preg_replace('#/+#', '/', str_replace('\\', '/', trim($value))), '/');
    }

    /**
     * Absolute path of the folder.
     *
     * @param  string  $appPath  Absolute path of app/
     *
     * @return string app/{folder}
     */
    public function path(string $appPath): string
    {
        return rtrim($appPath, '/\\').'/'.$this->relativePath;
    }

    /**
     * Namespace of the folder.
     *
     * The application namespace followed by the folder segments: App\Modules, Acme\Domain\Modules.
     *
     * @param  string  $rootNamespace  Application namespace, with or without the trailing backslash
     *
     * @return string Namespace without a leading or trailing backslash
     */
    public function namespace(string $rootNamespace): string
    {
        return trim($rootNamespace, '\\').'\\'.str_replace('/', '\\', $this->relativePath);
    }
}
