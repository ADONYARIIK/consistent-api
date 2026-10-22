<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

use PhpParser\Node\Name;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Resolves names without replacing them and marks the ones that refer to classes.
 *
 * php-parser's NameResolver resolves class, function and constant names alike; consistent:rebuild only
 * rewrites class references, so every name resolved as a class gets the CLASS_REFERENCE attribute. The
 * nodes keep their source positions, and the resolved name is in the resolvedName attribute.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class ClassNameResolver extends NameResolver
{
    /**
     * Attribute set to true on names that refer to a class, interface, trait or enum.
     */
    public const CLASS_REFERENCE = 'consistentClassReference';

    /**
     * Resolves into attributes, leaving the nodes as written.
     */
    public function __construct()
    {
        parent::__construct(null, ['replaceNodes' => false]);
    }

    /**
     * Marks the name as a class reference, then resolves it.
     *
     * @param  Name  $name  Name in a class position: new, extends, a type, an attribute, Foo::class, ...
     *
     * @return Name The same node, with resolvedName set
     */
    protected function resolveClassName(Name $name): Name
    {
        $name->setAttribute(self::CLASS_REFERENCE, true);

        return parent::resolveClassName($name);
    }
}
