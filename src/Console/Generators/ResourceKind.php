<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Generators;

/**
 * Resource classes consistent:create can generate.
 *
 * Each case maps a --resources code to a class suffix: light → {Model}LightResource, standard →
 * {Model}Resource, admin → {Model}AdminResource. The order of the cases, from the lightest to the
 * heaviest, is the inheritance chain of the generated resources.
 *
 * @package Adonyarik\ConsistentApi\Console\Generators
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
enum ResourceKind: string
{
    // Cases go from the lightest to the heaviest resource. The order sets the inheritance chain:
    // ModuleBlueprint keeps the resources in it and takes the previous one as the parent.
    case Light = 'light';
    case Standard = 'standard';
    case Admin = 'admin';

    /**
     * Accepts the short and the full --resources code: l / light, s / standard, a / admin.
     *
     * The code is expected trimmed and lowercased, as ModuleBlueprintFactory passes it; all is handled by
     * the factory, not here.
     *
     * @param  string  $code  One --resources value
     *
     * @return self|null The kind, null for an unknown code
     */
    public static function fromCode(string $code): ?self
    {
        foreach (self::cases() as $kind) {
            if ($code === $kind->value || $code === $kind->value[0]) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Class name suffix appended to the model name.
     *
     * The standard resource has the plain Resource suffix, so it is the one a single-resource module gets
     * by default.
     *
     * @return string LightResource, Resource or AdminResource
     *
     * @see ModuleBlueprint::resourceClass()
     */
    public function classSuffix(): string
    {
        return match ($this) {
            self::Light => 'LightResource',
            self::Standard => 'Resource',
            self::Admin => 'AdminResource',
        };
    }
}
