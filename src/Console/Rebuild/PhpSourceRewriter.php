<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

use Adonyarik\ConsistentApi\Console\Support\PhpImports;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Token;

/**
 * Rewrites the class references of a PHP file after classes moved to other namespaces.
 *
 * Works on the syntax tree, so every reference is resolved the way PHP resolves it: through the file's
 * imports (group imports included) or its namespace, fully qualified, relative (Models\Post) or with the
 * namespace keyword. A moved file gets its new namespace whatever the form of its namespace statement.
 * Each reference is rewritten so it still points to the same class: an import is renamed, a fully
 * qualified name replaced, and a short name that resolved through the old namespace gets an import (or a
 * fully qualified name when its alias is taken). Class names in strings ('App\Models\Post', also with
 * escaped backslashes or an @method suffix) and in comments and docblocks are rewritten too, and short
 * names used only in docblock types get imports. Only the changed bytes differ from the original.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class PhpSourceRewriter
{
    /**
     * Docblock tags followed by types.
     */
    private const DOC_TAGS = '/@(?:var|param|return|property(?:-read|-write)?|method|mixin|extends|implements|use|throws|see|template(?:-covariant|-contravariant)?|(?:phpstan|psalm)-[\w-]+)\b(.*)$/m';

    /**
     * Lowercased old class name => new class name.
     *
     * @var array<string, string>
     */
    private array $classMap = [];

    /**
     * Lowercased names of the classes known to exist, for short names in docblocks.
     *
     * @var array<string, true>
     */
    private array $knownClasses = [];

    /**
     * Takes the moves and the classes the docblock scan may import.
     *
     * @param  array<string, string>  $classMap  Old fully qualified class name => new one
     * @param  list<string>  $knownClasses  Fully qualified names of classes that exist, moved or not
     */
    public function __construct(array $classMap, array $knownClasses = [])
    {
        foreach ($classMap as $old => $new) {
            $this->classMap[strtolower(ltrim($old, '\\'))] = ltrim($new, '\\');
        }

        foreach ($knownClasses as $class) {
            $this->knownClasses[strtolower(ltrim($class, '\\'))] = true;
        }
    }

    /**
     * New name of a class, or the name itself when it did not move.
     *
     * @param  string  $class  Fully qualified class name, with or without the leading backslash
     *
     * @return string Fully qualified name without the leading backslash
     */
    public function target(string $class): string
    {
        return $this->classMap[strtolower(ltrim($class, '\\'))] ?? ltrim($class, '\\');
    }

    /**
     * Rewrites the class references of a file.
     *
     * @param  string  $code  PHP source
     * @param  string|null  $newNamespace  Namespace the file moves to; null when it stays
     * @param  string|null  $useFactory  Factory a moved model that uses HasFactory should name with #[UseFactory]; null for none
     *
     * @return string The rewritten source, identical when nothing refers to a moved class
     *
     * @throws Error When the source is not valid PHP
     */
    public function rewrite(string $code, ?string $newNamespace = null, ?string $useFactory = null): string
    {
        $file = ParsedFile::parse($code);
        $edits = new SourceEdits;

        foreach ($file->blocks() as $block) {
            $this->rewriteBlock($file, $block, $edits, $newNamespace, $useFactory);
        }

        return $edits->apply($code);
    }

    /**
     * Adds class imports to the first namespace block of a file, skipping those already there.
     *
     * Used for module Routes.php files, which have no namespace.
     *
     * @param  string  $code  PHP source
     * @param  array<string, string>  $imports  Alias => fully qualified class name
     *
     * @return string The source with the imports
     *
     * @throws Error When the source is not valid PHP
     */
    public function addImports(string $code, array $imports): string
    {
        $file = ParsedFile::parse($code);
        $block = $file->blocks()[0];
        $edits = new SourceEdits;
        $uses = $this->scanUses($file, $block, rewrite: false);
        $new = [];

        foreach ($imports as $alias => $class) {
            $this->canImport($alias, $class, $uses['aliases'], $block['declared'], $new);
        }

        $this->finishImports($file, $block, $edits, $uses, $new);

        return $edits->apply($code);
    }

    /**
     * Records the edits of one namespace block.
     *
     * @param  ParsedFile  $file  Parsed source
     * @param  array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}  $block  Namespace block
     * @param  SourceEdits  $edits  Edits of the file, filled in
     * @param  string|null  $newNamespace  Namespace the file moves to; null when it stays
     * @param  string|null  $useFactory  Factory for #[UseFactory]; null for none
     */
    private function rewriteBlock(ParsedFile $file, array $block, SourceEdits $edits, ?string $newNamespace, ?string $useFactory): void
    {
        $oldNamespace = $block['namespace'];
        $newNamespace ??= $oldNamespace;

        if ($newNamespace !== $oldNamespace && $block['nameNode'] !== null) {
            $edits->replace($block['nameNode']->getStartFilePos(), $block['nameNode']->getEndFilePos(), $newNamespace);
        }

        $uses = $this->scanUses($file, $block, rewrite: true);
        $imports = [];

        foreach (ParsedFile::classReferences($block['stmts']) as $name) {
            $this->rewriteReference($name, $block, $uses['aliases'], $oldNamespace, $newNamespace, $edits, $imports);
        }

        foreach ((new NodeFinder)->findInstanceOf($block['stmts'], String_::class) as $string) {
            $this->rewriteString($file, $string, $edits);
        }

        foreach ($file->commentsBetween($block['start'], $block['end']) as $comment) {
            $this->rewriteComment($comment, $block, $uses['aliases'], $oldNamespace, $newNamespace, $edits, $imports);
        }

        if ($useFactory !== null) {
            $this->addUseFactory($file, $block, $uses['aliases'], $useFactory, $edits, $imports);
        }

        $this->finishImports($file, $block, $edits, $uses, $imports);
    }

    /**
     * Reads the imports of a block and, when rewriting, renames the imports of moved classes.
     *
     * Plain imports are renamed in place; a group import with a moved class is expanded into one import
     * per class. The block counts as simple when its imports are consecutive single-class imports with
     * nothing but whitespace between them: finishImports() then rewrites the whole block sorted.
     *
     * @param  ParsedFile  $file  Parsed source
     * @param  array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}  $block  Namespace block
     * @param  bool  $rewrite  Whether to rename imports of moved classes
     *
     * @return array{nodes: list<Stmt\Use_|Stmt\GroupUse>, aliases: array<string, string>, edits: list<array{0: int, 1: int, 2: string}>, lines: list<string>, simple: bool, changed: bool}
     */
    private function scanUses(ParsedFile $file, array $block, bool $rewrite): array
    {
        $nodes = array_values(array_filter($block['stmts'], fn (Stmt $stmt) => $stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse));
        $result = ['nodes' => $nodes, 'aliases' => [], 'edits' => [], 'lines' => [], 'simple' => $nodes !== [], 'changed' => false];

        foreach ($nodes as $index => $use) {
            if ($index > 0 && trim(substr($file->code, $nodes[$index - 1]->getEndFilePos() + 1, $use->getStartFilePos() - $nodes[$index - 1]->getEndFilePos() - 1)) !== '') {
                $result['simple'] = false;
            }

            if ($use instanceof Stmt\GroupUse || $use->type !== Stmt\Use_::TYPE_NORMAL || count($use->uses) !== 1) {
                $result['simple'] = false;
            }

            $statements = [];
            $changed = false;

            foreach ($use->uses as $item) {
                $type = $use->type | $item->type;
                $name = $use instanceof Stmt\GroupUse ? Name::concat($use->prefix, $item->name)->toString() : $item->name->toString();
                $target = $rewrite && $type === Stmt\Use_::TYPE_NORMAL ? $this->target($name) : $name;
                $alias = $item->alias !== null ? ' as '.$item->alias->toString() : '';

                if ($type === Stmt\Use_::TYPE_NORMAL) {
                    $result['aliases'][strtolower($item->getAlias()->toString())] = $target;
                }

                $keyword = match ($type) {
                    Stmt\Use_::TYPE_FUNCTION => 'use function ',
                    Stmt\Use_::TYPE_CONSTANT => 'use const ',
                    default => 'use ',
                };
                $statements[] = $keyword.$target.$alias.';';

                if ($target === $name) {
                    continue;
                }

                $changed = true;

                if ($use instanceof Stmt\Use_) {
                    $prefix = str_starts_with($file->text($item->name), '\\') ? '\\' : '';
                    $result['edits'][] = [$item->name->getStartFilePos(), $item->name->getEndFilePos(), $prefix.$target];
                }
            }

            if ($changed && $use instanceof Stmt\GroupUse) {
                $result['edits'][] = [$use->getStartFilePos(), $use->getEndFilePos(), implode("\n", $statements)];
            }

            $result['changed'] = $result['changed'] || $changed;
            $result['lines'] = [...$result['lines'], ...$statements];
        }

        return $result;
    }

    /**
     * Rewrites one class reference so that it keeps pointing to the same class.
     *
     * @param  Name  $name  Class reference with its resolvedName attribute
     * @param  array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}  $block  Namespace block
     * @param  array<string, string>  $aliases  Imports of the block after renaming, lowercased alias => class
     * @param  string  $oldNamespace  Namespace of the block before the move
     * @param  string  $newNamespace  Namespace of the block after the move
     * @param  SourceEdits  $edits  Edits of the file, filled in
     * @param  array<string, string>  $imports  Imports to add, lowercased alias => class, filled in
     */
    private function rewriteReference(Name $name, array $block, array $aliases, string $oldNamespace, string $newNamespace, SourceEdits $edits, array &$imports): void
    {
        /** @var Name\FullyQualified $resolved */
        $resolved = $name->getAttribute('resolvedName');
        $old = $resolved->toString();
        $new = $this->target($old);
        // In the global namespace a qualified name is already absolute, so the backslash is not needed
        $fullyQualified = fn () => $edits->replace(
            $name->getStartFilePos(),
            $name->getEndFilePos(),
            ($newNamespace === '' && ! $name instanceof Name\FullyQualified ? '' : '\\').$new,
        );

        if ($name instanceof Name\FullyQualified) {
            if ($new !== $old) {
                $fullyQualified();
            }

            return;
        }

        if ($name instanceof Name\Relative) {
            if ($new !== $old || $newNamespace !== $oldNamespace) {
                $fullyQualified();
            }

            return;
        }

        // Resolved through an import: the import itself is renamed, a name below an imported namespace is not
        if (isset($block['aliases'][strtolower($name->getFirst())])) {
            if ($name->isQualified() && $new !== $old) {
                $fullyQualified();
            }

            return;
        }

        // Resolved through the namespace of the block
        if ($new === $old && $newNamespace === $oldNamespace) {
            return;
        }

        if ($name->isUnqualified()) {
            if ($this->namespaceOf($new) === $newNamespace) {
                return;
            }

            if (! $this->canImport($name->toString(), $new, $aliases, $block['declared'], $imports)) {
                $fullyQualified();
            }

            return;
        }

        // A relative qualified name of a class that stays: import its first segment as a namespace
        if ($new === $old && $this->canImport($name->getFirst(), $oldNamespace.'\\'.$name->getFirst(), $aliases, $block['declared'], $imports)) {
            return;
        }

        $fullyQualified();
    }

    /**
     * Rewrites a string literal that holds a moved class name.
     *
     * The string must be the class name alone, optionally with a leading backslash and an @method or
     * ::method suffix (old route actions, callables). The quote style is kept; escaped backslashes stay
     * escaped.
     *
     * @param  ParsedFile  $file  Parsed source
     * @param  String_  $string  String literal
     * @param  SourceEdits  $edits  Edits of the file, filled in
     */
    public function rewriteString(ParsedFile $file, String_ $string, SourceEdits $edits): void
    {
        $value = $string->value;

        if (! str_contains($value, '\\') || strlen($value) > 1024
            || preg_match('/^(\\\\?)([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+)((?:@|::).*)?$/sD', $value, $parts) !== 1) {
            return;
        }

        $target = $this->classMap[strtolower($parts[2])] ?? null;

        if ($target === null) {
            return;
        }

        $newValue = $parts[1].$target.($parts[3] ?? '');
        $raw = $file->text($string);

        $literal = match ($string->getAttribute('kind')) {
            String_::KIND_SINGLE_QUOTED => str_contains($raw, '\\\\') || preg_match('/[\'\\\\]/', $parts[3] ?? '') === 1
                ? "'".addcslashes($newValue, "'\\")."'"
                : "'".$newValue."'",
            String_::KIND_DOUBLE_QUOTED => '"'.addcslashes($newValue, '"\\$').'"',
            default => null,
        };

        if ($literal !== null && $literal !== $raw) {
            $edits->replace($string->getStartFilePos(), $string->getEndFilePos(), $literal);
        }
    }

    /**
     * Rewrites moved class names in a comment and imports short names used in docblock types.
     *
     * Fully qualified names (with or without the leading backslash) are replaced wherever they stand. In a
     * docblock, a short name after a type tag (@var, @param, @property, ...) that resolved through the old
     * namespace to a known class gets an import, so tools reading the docblock still find the class.
     *
     * @param  Token  $comment  Comment or docblock token
     * @param  array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}  $block  Namespace block
     * @param  array<string, string>  $aliases  Imports of the block after renaming, lowercased alias => class
     * @param  string  $oldNamespace  Namespace of the block before the move
     * @param  string  $newNamespace  Namespace of the block after the move
     * @param  SourceEdits  $edits  Edits of the file, filled in
     * @param  array<string, string>  $imports  Imports to add, lowercased alias => class, filled in
     */
    private function rewriteComment(Token $comment, array $block, array $aliases, string $oldNamespace, string $newNamespace, SourceEdits $edits, array &$imports): void
    {
        $text = $this->commentText($comment->text);

        if ($text !== $comment->text) {
            $edits->replace($comment->pos, $comment->pos + strlen($comment->text) - 1, $text);
        }

        if ($comment->id !== T_DOC_COMMENT || $oldNamespace === '' || preg_match_all(self::DOC_TAGS, $comment->text, $tags) === 0) {
            return;
        }

        foreach ($tags[1] as $types) {
            preg_match_all('/(?<![\w\\\\$])([A-Za-z_]\w*)(?![\w\\\\])/', $types, $words);

            foreach (array_unique($words[1]) as $word) {
                $key = strtolower($word);
                $old = $oldNamespace.'\\'.$word;

                if (isset($block['aliases'][$key]) || isset($block['declared'][$key]) || ! isset($this->knownClasses[strtolower($old)])) {
                    continue;
                }

                $new = $this->target($old);

                if (($new !== $old || $newNamespace !== $oldNamespace) && $this->namespaceOf($new) !== $newNamespace) {
                    $this->canImport($word, $new, $aliases, $block['declared'], $imports);
                }
            }
        }
    }

    /**
     * Replaces the fully qualified names of moved classes in a comment.
     *
     * A name counts with or without the leading backslash, but not as part of a longer name.
     *
     * @param  string  $text  Comment or docblock
     *
     * @return string The comment with the new names
     */
    public function commentText(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\w\\\\])(\\\\?)([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+)(?![\w\\\\])/',
            fn (array $match) => isset($this->classMap[strtolower($match[2])]) ? $match[1].$this->classMap[strtolower($match[2])] : $match[0],
            $text,
        );
    }

    /**
     * Points a moved model that uses HasFactory to its factory with #[UseFactory].
     *
     * Laravel looks for the factory of App\Modules\Users\Models\User in
     * Database\Factories\Modules\Users\Models\UserFactory, which does not exist. Skipped when the model
     * already names its factory (#[UseFactory] or a static $factory property) or extends ConsistentModel,
     * which finds Database\Factories\{Model}Factory by itself.
     *
     * @param  ParsedFile  $file  Parsed source
     * @param  array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}  $block  Namespace block
     * @param  array<string, string>  $aliases  Imports of the block after renaming, lowercased alias => class
     * @param  string  $factory  Fully qualified factory class
     * @param  SourceEdits  $edits  Edits of the file, filled in
     * @param  array<string, string>  $imports  Imports to add, lowercased alias => class, filled in
     */
    private function addUseFactory(ParsedFile $file, array $block, array $aliases, string $factory, SourceEdits $edits, array &$imports): void
    {
        $class = null;

        foreach ($block['stmts'] as $stmt) {
            if ($stmt instanceof Stmt\Class_) {
                $class = $stmt;

                break;
            }
        }

        if ($class === null || $this->resolved($class->extends) === ConsistentModel::class) {
            return;
        }

        $usesHasFactory = false;

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $usesHasFactory = $usesHasFactory || $this->resolved($trait) === 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory';
                }
            }

            if ($stmt instanceof Stmt\Property && $stmt->isStatic()) {
                foreach ($stmt->props as $property) {
                    if ($property->name->toString() === 'factory') {
                        return;
                    }
                }
            }
        }

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->resolved($attribute->name) === 'Illuminate\\Database\\Eloquent\\Attributes\\UseFactory') {
                    return;
                }
            }
        }

        if (! $usesHasFactory) {
            return;
        }

        $attribute = $this->canImport('UseFactory', 'Illuminate\\Database\\Eloquent\\Attributes\\UseFactory', $aliases, $block['declared'], $imports)
            ? 'UseFactory'
            : '\\Illuminate\\Database\\Eloquent\\Attributes\\UseFactory';
        $short = substr((string) strrchr('\\'.$factory, '\\'), 1);
        $factoryName = $this->canImport($short, $factory, $aliases, $block['declared'], $imports) ? $short : '\\'.$factory;

        $start = $class->getStartFilePos();
        $lineStart = (int) strrpos(substr($file->code, 0, $start), "\n") + (str_contains(substr($file->code, 0, $start), "\n") ? 1 : 0);
        $indent = substr($file->code, $lineStart, $start - $lineStart);

        $edits->insert($start, sprintf("#[%s(%s::class)]\n%s", $attribute, $factoryName, trim($indent) === '' ? $indent : ''));
    }

    /**
     * Writes the renamed imports and the imports to add.
     *
     * A simple import block (see scanUses()) is replaced by all its imports sorted the way Pint sorts them.
     * Otherwise the renamed imports are edited in place and new ones go after the last import, after the
     * namespace statement, after declare(strict_types=1) or after the opening tag, in that order of
     * preference.
     *
     * @param  ParsedFile  $file  Parsed source
     * @param  array{namespace: string, nameNode: Name|null, start: int, end: int, stmts: list<Stmt>, aliases: array<string, string>, declared: array<string, true>}  $block  Namespace block
     * @param  SourceEdits  $edits  Edits of the file, filled in
     * @param  array{nodes: list<Stmt\Use_|Stmt\GroupUse>, aliases: array<string, string>, edits: list<array{0: int, 1: int, 2: string}>, lines: list<string>, simple: bool, changed: bool}  $uses  Result of scanUses()
     * @param  array<string, string>  $imports  Imports to add, lowercased alias => class
     */
    private function finishImports(ParsedFile $file, array $block, SourceEdits $edits, array $uses, array $imports): void
    {
        $added = [];

        foreach ($imports as $class) {
            $added[] = 'use '.$class.';';
        }

        if ($uses['simple'] && ($uses['changed'] || $added !== [])) {
            $first = $uses['nodes'][0];
            $last = $uses['nodes'][count($uses['nodes']) - 1];
            $edits->replace($first->getStartFilePos(), $last->getEndFilePos(), implode("\n", PhpImports::sort([...$uses['lines'], ...$added])));

            return;
        }

        foreach ($uses['edits'] as [$start, $end, $text]) {
            $edits->replace($start, $end, $text);
        }

        if ($added === []) {
            return;
        }

        $text = implode("\n", PhpImports::sort($added));

        if ($uses['nodes'] !== []) {
            $edits->insert($uses['nodes'][count($uses['nodes']) - 1]->getEndFilePos() + 1, "\n".$text);

            return;
        }

        if ($block['nameNode'] !== null) {
            $after = $this->firstAfter($file, $block['nameNode']->getEndFilePos(), [ord(';'), ord('{')]) ?? $block['nameNode']->getEndFilePos() + 1;
            $edits->insert($after, "\n\n".$text.$this->lineBreakBefore($file, $after));

            return;
        }

        foreach ($block['stmts'] as $stmt) {
            if ($stmt instanceof Stmt\Declare_) {
                $edits->insert($stmt->getEndFilePos() + 1, "\n\n".$text.$this->lineBreakBefore($file, $stmt->getEndFilePos() + 1));

                return;
            }
        }

        $openTag = $file->after(T_OPEN_TAG, 0) ?? 0;
        $whitespace = strspn($file->code, " \t\r\n", $openTag);

        if ($whitespace > 0) {
            $edits->replace($openTag, $openTag + $whitespace - 1, "\n".$text."\n\n");
        } else {
            $edits->insert($openTag, ($openTag === 0 ? "<?php\n" : '')."\n".$text."\n\n");
        }
    }

    /**
     * Line breaks to put after inserted imports so the code that follows starts on a line of its own.
     *
     * @param  ParsedFile  $file  Parsed source
     * @param  int  $offset  Offset the imports are inserted at
     *
     * @return string Nothing when a line break follows already, two line breaks otherwise
     */
    private function lineBreakBefore(ParsedFile $file, int $offset): string
    {
        $next = ltrim(substr($file->code, $offset, 64), " \t");

        return $next === '' || $next[0] === "\n" || $next[0] === "\r" ? '' : "\n\n";
    }

    /**
     * Claims an alias for an import, unless the block already uses it for something else.
     *
     * @param  string  $alias  Alias the class would be imported under
     * @param  string  $class  Fully qualified class or namespace
     * @param  array<string, string>  $aliases  Imports of the block after renaming, lowercased alias => class
     * @param  array<string, true>  $declared  Lowercased short names of the classes the block declares
     * @param  array<string, string>  $imports  Imports to add, lowercased alias => class, filled in
     *
     * @return bool True when $alias refers to $class after the call
     */
    private function canImport(string $alias, string $class, array $aliases, array $declared, array &$imports): bool
    {
        $key = strtolower($alias);

        if (isset($imports[$key])) {
            return strcasecmp($imports[$key], $class) === 0;
        }

        if (isset($aliases[$key])) {
            return strcasecmp($aliases[$key], $class) === 0;
        }

        if (isset($declared[$key])) {
            return false;
        }

        $imports[$key] = $class;

        return true;
    }

    /**
     * Offset after the first of the given tokens that follows $offset.
     *
     * @param  ParsedFile  $file  Parsed source
     * @param  int  $offset  Offset to search from
     * @param  list<int>  $ids  Token ids
     *
     * @return int|null Offset after the token, null when none follows
     */
    private function firstAfter(ParsedFile $file, int $offset, array $ids): ?int
    {
        foreach ($file->tokens as $token) {
            if ($token->pos > $offset && in_array($token->id, $ids, true)) {
                return $token->pos + strlen($token->text);
            }
        }

        return null;
    }

    /**
     * Fully qualified name a node resolved to.
     *
     * @param  Node|null  $name  Name node
     *
     * @return string|null The name without the leading backslash, null for anything else
     */
    private function resolved(?Node $name): ?string
    {
        $resolved = $name?->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : null;
    }

    /**
     * Namespace part of a fully qualified class name.
     *
     * @param  string  $class  Fully qualified class name
     *
     * @return string The namespace, empty for a global class
     */
    private function namespaceOf(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? '' : substr($class, 0, $position);
    }
}
