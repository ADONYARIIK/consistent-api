<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\Token;

/**
 * A PHP file parsed for rewriting: the syntax tree with resolved names, the tokens and the namespace blocks.
 *
 * consistent:rebuild reads every file through it instead of regular expressions, so comments, strings,
 * heredocs, one-line and braced namespace declarations and group imports are recognized as PHP sees them.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class ParsedFile
{
    /**
     * Namespace blocks, see blocks().
     *
     * @var list<array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}>|null
     */
    private ?array $blocks = null;

    /**
     * Holds a parsed file; parse() creates it.
     *
     * @param  string  $code  Source code
     * @param  list<Stmt>  $stmts  Top-level statements with names resolved by ClassNameResolver
     * @param  list<Token>  $tokens  Tokens of the source
     */
    private function __construct(
        public readonly string $code,
        public readonly array $stmts,
        public readonly array $tokens,
    ) {}

    /**
     * Parses PHP source and resolves its names.
     *
     * @param  string  $code  Source code
     *
     * @return self The parsed file
     *
     * @throws Error When the source is not valid PHP
     */
    public static function parse(string $code): self
    {
        $parser = (new ParserFactory)->createForHostVersion();
        $stmts = $parser->parse($code) ?? [];
        $stmts = (new NodeTraverser(new ClassNameResolver))->traverse($stmts);

        /** @var list<Stmt> $stmts */
        return new self($code, $stmts, $parser->getTokens());
    }

    /**
     * Namespace blocks of the file, in source order.
     *
     * A file without a namespace statement is one block with the empty namespace. Each block lists its
     * top-level statements, its class imports (lowercased alias => fully qualified name, group imports
     * included) and the short names of the class-like declarations at its top level.
     *
     * @return list<array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}>
     */
    public function blocks(): array
    {
        if ($this->blocks !== null) {
            return $this->blocks;
        }

        $namespaces = array_values(array_filter($this->stmts, fn (Stmt $stmt) => $stmt instanceof Stmt\Namespace_));
        $blocks = [];

        if ($namespaces === []) {
            $blocks[] = $this->block('', null, 0, strlen($this->code) - 1, $this->stmts);
        }

        foreach ($namespaces as $namespace) {
            /** @var Stmt\Namespace_ $namespace */
            $blocks[] = $this->block(
                $namespace->name?->toString() ?? '',
                $namespace->name,
                $namespace->getStartFilePos(),
                $namespace->getEndFilePos(),
                $namespace->stmts,
            );
        }

        return $this->blocks = $blocks;
    }

    /**
     * Names in the statements that refer to classes, without self, parent and static.
     *
     * @param  array<Node>  $nodes  Nodes to search
     *
     * @return list<Name> Class reference nodes with their resolvedName attribute
     */
    public static function classReferences(array $nodes): array
    {
        /** @var list<Name> $names */
        $names = (new NodeFinder)->find($nodes, fn (Node $node) => $node instanceof Name
            && $node->getAttribute(ClassNameResolver::CLASS_REFERENCE) === true
            && ! $node->isSpecialClassName()
            && $node->getAttribute('resolvedName') instanceof Name\FullyQualified);

        return $names;
    }

    /**
     * Source text of a node.
     *
     * @param  Node  $node  Node of this file
     *
     * @return string The bytes the node spans
     */
    public function text(Node $node): string
    {
        return substr($this->code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
    }

    /**
     * Comment and docblock tokens between two offsets.
     *
     * @param  int  $start  First offset
     * @param  int  $end  Last offset
     *
     * @return list<Token> T_COMMENT and T_DOC_COMMENT tokens
     */
    public function commentsBetween(int $start, int $end): array
    {
        return array_values(array_filter(
            $this->tokens,
            fn (Token $token) => in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true) && $token->pos >= $start && $token->pos <= $end,
        ));
    }

    /**
     * Offset right after the first token of the given kind at or after $offset.
     *
     * @param  int|string  $id  Token id or single character
     * @param  int  $offset  Offset to search from
     *
     * @return int|null Offset after the token, null when there is none
     */
    public function after(int|string $id, int $offset): ?int
    {
        $id = is_string($id) ? ord($id) : $id;

        foreach ($this->tokens as $token) {
            if ($token->pos >= $offset && $token->id === $id) {
                return $token->pos + strlen($token->text);
            }
        }

        return null;
    }

    /**
     * Describes one namespace block.
     *
     * @param  string  $namespace  Namespace name, empty for the global namespace
     * @param  Name|null  $nameNode  Name node of the namespace statement
     * @param  int  $start  First offset of the block
     * @param  int  $end  Last offset of the block
     * @param  array<Stmt>  $stmts  Top-level statements of the block
     *
     * @return array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}
     */
    private function block(string $namespace, ?Name $nameNode, int $start, int $end, array $stmts): array
    {
        $aliases = [];
        $declared = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse) {
                foreach ($stmt->uses as $use) {
                    if (($stmt->type | $use->type) !== Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }

                    $name = $stmt instanceof Stmt\GroupUse ? Name::concat($stmt->prefix, $use->name) : $use->name;
                    $aliases[strtolower($use->getAlias()->toString())] = $name->toString();
                }
            }

            if ($stmt instanceof Stmt\ClassLike && $stmt->name !== null) {
                $declared[strtolower($stmt->name->toString())] = true;
            }
        }

        return [
            'namespace' => $namespace,
            'nameNode' => $nameNode,
            'start' => $start,
            'end' => $end,
            'stmts' => array_values($stmts),
            'aliases' => $aliases,
            'declared' => $declared,
        ];
    }
}
