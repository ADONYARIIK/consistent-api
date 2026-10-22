<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Commands\Concerns;

use Adonyarik\ConsistentApi\Support\ModulesFolder;
use RuntimeException;

/**
 * Reads the modules folder for the consistent:* commands.
 *
 * consistent:create and consistent:rebuild show the folder in their descriptions and write into it, so
 * both read consistentapi.modules_folder and the application namespace the same way.
 *
 * @package Adonyarik\ConsistentApi\Console\Commands\Concerns
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
trait ResolvesModulesFolder
{
    /**
     * consistentapi.modules_folder relative to app/, normalized but not validated.
     *
     * '/Domain\Modules/' becomes 'Domain/Modules'; the default is 'Modules'. Meant for descriptions, which
     * are built when Artisan starts and must not throw; handle() validates with modulesFolderError().
     *
     * @return string Folder path relative to app/
     *
     * @see ModulesFolder::normalize()
     */
    protected function modulesFolder(): string
    {
        return ModulesFolder::normalize((string) config('consistentapi.modules_folder', 'Modules'));
    }

    /**
     * Why consistentapi.modules_folder cannot be used, null when it can.
     *
     * @return string|null The error message, null for a valid folder
     *
     * @see ModulesFolder::errorFor()
     */
    protected function modulesFolderError(): ?string
    {
        return ModulesFolder::errorFor(config('consistentapi.modules_folder', 'Modules'));
    }

    /**
     * Application namespace modules are placed under, without the trailing backslash.
     *
     * Laravel reads it from the PSR-4 entry of app/ in composer.json, so an application with
     * "Acme\\": "app/" gets Acme\Modules\... instead of App\Modules\...; without such an entry it is App.
     *
     * @return string Root namespace, such as App
     */
    protected function rootNamespace(): string
    {
        // No composer.json, or no entry for app/ in it: Laravel's default
        if (! is_file($this->laravel->basePath('composer.json'))) {
            return 'App';
        }

        try {
            return rtrim($this->laravel->getNamespace(), '\\');
        } catch (RuntimeException) {
            return 'App';
        }
    }
}
