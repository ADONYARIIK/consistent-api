<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

/**
 * Reads the class-like declaration of a PHP file from its tokens.
 *
 * FilterableSortableValidator uses it to find model classes and consistent:rebuild to find the classes
 * to move and the names a file can reference. Working on tokens instead of regular expressions keeps
 * words inside comments and docblocks ("This class represents …"), Foo::class and anonymous classes from
 * being taken for declarations.
 *
 * @package Adonyarik\ConsistentApi\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class PhpClassReader
{
    /**
     * Declaration keywords and the kind read() reports for each.
     */
    private const KINDS = [
        T_CLASS => 'class',
        T_INTERFACE => 'interface',
        T_TRAIT => 'trait',
        T_ENUM => 'enum',
    ];

    /**
     * Namespace, name and kind of the first class-like declaration in PHP source.
     *
     * The namespace is the last namespace statement before the declaration, an empty string for the
     * global namespace. Modifiers (abstract, final, readonly) and attributes before the declaration are
     * allowed; Foo::class and new class {} are not declarations.
     *
     * @param  string  $source  PHP source code
     *
     * @return array{namespace: string, name: string, kind: 'class'|'interface'|'trait'|'enum'}|null The declaration, null when the source has none
     *
     * @see PhpClassReader::readAll()
     */
    public function read(string $source): ?array
    {
        return $this->readAll($source)[0] ?? null;
    }

    /**
     * Every class-like declaration in PHP source, in source order.
     *
     * Each declaration gets the namespace of the namespace statement before it, so a file with several
     * namespace blocks is read correctly. Anonymous classes and Foo::class are not declarations.
     *
     * @param  string  $source  PHP source code
     *
     * @return list<array{namespace: string, name: string, kind: 'class'|'interface'|'trait'|'enum'}> The declarations, empty when the source has none
     *
     * @see PhpClassReader::nameAfter()
     * @see PhpClassReader::follows()
     */
    public function readAll(string $source): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $declarations = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                // namespace\Foo is a relative name, not a namespace statement
                if ($this->significantAfter($tokens, $index) === T_NS_SEPARATOR) {
                    continue;
                }

                // namespace { … } declares code in the global namespace
                $namespace = $this->nameAfter($tokens, $index) ?? '';

                continue;
            }

            $kind = self::KINDS[$token[0]] ?? null;

            if ($kind === null || $this->follows($tokens, $index, [T_DOUBLE_COLON, T_NEW])) {
                continue;
            }

            $name = $this->nameAfter($tokens, $index);

            if ($name !== null) {
                $declarations[] = ['namespace' => $namespace, 'name' => $name, 'kind' => $kind];
            }
        }

        return $declarations;
    }

    /**
     * Fully qualified name of the class declared in PHP source.
     *
     * Interfaces, traits and enums do not count, so a file holding one of them gives null; so does a file
     * with several class-like declarations, whose first one alone would hide the others.
     *
     * @param  string  $source  PHP source code
     *
     * @return string|null The class name without a leading backslash, null when the source does not declare exactly one class
     *
     * @see PhpClassReader::classNames()
     */
    public function className(string $source): ?string
    {
        $declarations = $this->readAll($source);

        if (count($declarations) !== 1 || $declarations[0]['kind'] !== 'class') {
            return null;
        }

        return ltrim($declarations[0]['namespace'].'\\'.$declarations[0]['name'], '\\');
    }

    /**
     * Fully qualified names of every class declared in PHP source.
     *
     * Interfaces, traits and enums are left out.
     *
     * @param  string  $source  PHP source code
     *
     * @return list<string> Class names without a leading backslash, in source order
     *
     * @see PhpClassReader::readAll()
     */
    public function classNames(string $source): array
    {
        $names = [];

        foreach ($this->readAll($source) as $declaration) {
            if ($declaration['kind'] === 'class') {
                $names[] = ltrim($declaration['namespace'].'\\'.$declaration['name'], '\\');
            }
        }

        return $names;
    }

    /**
     * Id of the nearest significant token after $index, skipping whitespace and comments.
     *
     * @param  list<array|string>  $tokens  Tokens of the source
     * @param  int  $index  Position to start from
     *
     * @return int|string|null Token id or character, null at the end of the source
     */
    private function significantAfter(array $tokens, int $index): int|string|null
    {
        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[0] : $token;
        }

        return null;
    }

    /**
     * Name that follows the keyword at $index, skipping whitespace and comments.
     *
     * Anything other than a name (an opening brace, a semicolon, a double colon) means the keyword is not
     * followed by one.
     *
     * @param  list<array|string>  $tokens  Tokens of the source
     * @param  int  $index  Position of the keyword
     *
     * @return string|null The name without a leading backslash, null when no name follows
     */
    private function nameAfter(array $tokens, int $index): ?string
    {
        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                return ltrim($token[1], '\\');
            }

            return null;
        }

        return null;
    }

    /**
     * Tells whether the keyword at $index directly follows one of the given tokens.
     *
     * Looks back past whitespace, comments and the class modifiers, so new readonly class {} is still
     * found to follow new.
     *
     * @param  list<array|string>  $tokens  Tokens of the source
     * @param  int  $index  Position of the keyword
     * @param  list<int>  $kinds  Token ids to look for
     *
     * @return bool True when the nearest significant token before the keyword is one of $kinds
     */
    private function follows(array $tokens, int $index, array $kinds): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                continue;
            }

            return is_array($token) && in_array($token[0], $kinds, true);
        }

        return false;
    }
}
