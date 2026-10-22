<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

use PhpParser\Error;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Moves the routes of moved controllers from routes/api.php into module Routes.php files.
 *
 * Only whole top-level statements move, and only statements that are a chain of calls on the Route facade
 * (Route::get(...), Route::middleware(...)->group(...), Route::apiResource(...)->only(...)) and mention
 * controllers of exactly one module. A route inside a group, an if or any other statement stays where it
 * is, inside that statement, so it keeps its middleware and conditions; so do assignments
 * ($posts = Route::...) and statements naming controllers of several modules. Comments are never taken
 * for code: a commented-out route stays a comment in routes/api.php. Every class a moved statement names
 * is imported into the module file (or written fully qualified when the alias is taken there), so
 * middleware and controllers of other modules keep resolving.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class ApiRoutesRelocator
{
    /**
     * Lowercased class names the Route facade goes by: the facade and its global alias.
     */
    private const ROUTE_FACADES = ['illuminate\\support\\facades\\route', 'route'];

    /**
     * Lowercased old controller class => module name.
     *
     * @var array<string, string>
     */
    private array $controllerModules = [];

    /**
     * Takes the class moves and the module of every moved controller.
     *
     * @param  PhpSourceRewriter  $rewriter  Rewriter that knows the new class names
     * @param  array<string, string>  $controllerModules  Old fully qualified controller class => module name
     */
    public function __construct(
        private readonly PhpSourceRewriter $rewriter,
        array $controllerModules,
    ) {
        foreach ($controllerModules as $class => $module) {
            $this->controllerModules[strtolower(ltrim($class, '\\'))] = $module;
        }
    }

    /**
     * Splits routes/api.php into what stays and what moves to each module.
     *
     * The statements that move are cut out of the file; imports of moved controllers that nothing in the
     * file uses any more are removed, the others stay. The remaining source still uses the old class
     * names; PhpSourceRewriter rewrites it afterwards like any other file.
     *
     * @param  string  $code  Source of routes/api.php
     * @param  array<string, array<string, string>>  $moduleAliases  Imports of each existing module Routes.php, lowercased alias => class
     *
     * @return array{remaining: string, modules: array<string, array{statements: list<string>, imports: array<string, string>}>, left: list<string>} What stays, what each module gets, and the statements that name moved controllers but stay
     *
     * @throws Error When the source is not valid PHP
     */
    public function relocate(string $code, array $moduleAliases): array
    {
        $file = ParsedFile::parse($code);
        $cut = new SourceEdits;
        $modules = [];
        $left = [];

        foreach ($file->blocks() as $block) {
            foreach ($block['stmts'] as $stmt) {
                $referenced = $this->modulesOf($stmt);

                if ($referenced === []) {
                    continue;
                }

                $summary = sprintf('line %d: %s', $stmt->getStartLine(), $this->summary($file->text($stmt)));

                if (! $this->isRouteStatement($stmt)) {
                    $left[] = $summary.' (not a Route:: statement)';

                    continue;
                }

                if (count($referenced) > 1) {
                    $left[] = $summary.' (controllers of several modules: '.implode(', ', $referenced).')';

                    continue;
                }

                $module = $referenced[0];
                $modules[$module] ??= ['statements' => [], 'imports' => []];
                $modules[$module]['statements'][] = $this->moduleStatement($file, $stmt, $moduleAliases[$module] ?? [], $modules[$module]['imports']);

                $cut->replace($stmt->getStartFilePos(), $this->endOfLine($code, $stmt->getEndFilePos()), '');
            }
        }

        if ($modules === []) {
            return ['remaining' => $code, 'modules' => [], 'left' => $left];
        }

        return [
            'remaining' => self::tidy($this->removeUnusedImports($cut->apply($code))),
            'modules' => $modules,
            'left' => $left,
        ];
    }

    /**
     * Collapses runs of blank lines and ends the file with one line break.
     *
     * An empty file, or one with only the opening tag left, becomes just the opening tag.
     *
     * @param  string  $code  PHP source
     *
     * @return string The tidied source
     */
    public static function tidy(string $code): string
    {
        $code = trim((string) preg_replace("/\n{3,}/", "\n\n", $code));

        if ($code === '' || $code === '<?php') {
            return "<?php\n";
        }

        return (str_starts_with($code, '<?php') ? '' : "<?php\n\n").$code."\n";
    }

    /**
     * Modules whose moved controllers a statement names, by class reference or class name string.
     *
     * @param  Stmt  $stmt  Top-level statement
     *
     * @return list<string> Module names, each once, in order of appearance
     */
    private function modulesOf(Stmt $stmt): array
    {
        $modules = [];

        foreach (ParsedFile::classReferences([$stmt]) as $name) {
            $module = $this->controllerModules[strtolower($name->getAttribute('resolvedName')->toString())] ?? null;

            if ($module !== null) {
                $modules[$module] = true;
            }
        }

        foreach ((new NodeFinder)->findInstanceOf([$stmt], String_::class) as $string) {
            if (preg_match('/^\\\\?([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+)(?:@.*)?$/sD', $string->value, $parts) === 1
                && isset($this->controllerModules[strtolower($parts[1])])) {
                $modules[$this->controllerModules[strtolower($parts[1])]] = true;
            }
        }

        return array_keys($modules);
    }

    /**
     * Tells whether a statement is a chain of calls that starts on the Route facade.
     *
     * @param  Stmt  $stmt  Top-level statement
     *
     * @return bool True for Route::...(...)->...(...); statements
     */
    private function isRouteStatement(Stmt $stmt): bool
    {
        if (! $stmt instanceof Stmt\Expression) {
            return false;
        }

        $expr = $stmt->expr;

        while ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
            $expr = $expr->var;
        }

        if (! $expr instanceof Expr\StaticCall || ! $expr->class instanceof Name) {
            return false;
        }

        $resolved = $expr->class->getAttribute('resolvedName');

        return $resolved instanceof Name && in_array(strtolower($resolved->toString()), self::ROUTE_FACADES, true);
    }

    /**
     * Source of a statement as it goes into a module Routes.php.
     *
     * Every class reference is rewritten for the module file: moved classes get their new names, a short
     * or relative name becomes the short name with an import (or the fully qualified name when that alias
     * is taken), fully qualified names stay fully qualified, and global classes (the Route alias) stay as
     * written. Class name strings and comments are rewritten as in any other file.
     *
     * @param  ParsedFile  $file  Parsed routes/api.php
     * @param  Stmt  $stmt  Statement to move
     * @param  array<string, string>  $existing  Imports of the module's existing Routes.php, lowercased alias => class
     * @param  array<string, string>  $imports  Imports the module file needs, lowercased alias => class, filled in
     *
     * @return string The statement's source for the module file
     */
    private function moduleStatement(ParsedFile $file, Stmt $stmt, array $existing, array &$imports): string
    {
        $start = $stmt->getStartFilePos();
        $edits = new SourceEdits($start);

        foreach (ParsedFile::classReferences([$stmt]) as $name) {
            $new = $this->rewriter->target($name->getAttribute('resolvedName')->toString());

            if (! str_contains($new, '\\')) {
                continue;
            }

            if ($name instanceof Name\FullyQualified) {
                if ($file->text($name) !== '\\'.$new) {
                    $edits->replace($name->getStartFilePos(), $name->getEndFilePos(), '\\'.$new);
                }

                continue;
            }

            $short = substr((string) strrchr('\\'.$new, '\\'), 1);
            $text = $this->claim($short, $new, $existing, $imports) ? $short : '\\'.$new;

            if ($file->text($name) !== $text) {
                $edits->replace($name->getStartFilePos(), $name->getEndFilePos(), $text);
            }
        }

        foreach ((new NodeFinder)->findInstanceOf([$stmt], String_::class) as $string) {
            $this->rewriter->rewriteString($file, $string, $edits);
        }

        foreach ($file->commentsBetween($start, $stmt->getEndFilePos()) as $comment) {
            $text = $this->rewriter->commentText($comment->text);

            if ($text !== $comment->text) {
                $edits->replace($comment->pos, $comment->pos + strlen($comment->text) - 1, $text);
            }
        }

        return $edits->apply($file->text($stmt));
    }

    /**
     * Claims an alias in the module file, unless it already stands for another class there.
     *
     * @param  string  $alias  Short name
     * @param  string  $class  Fully qualified class
     * @param  array<string, string>  $existing  Imports of the existing module file, lowercased alias => class
     * @param  array<string, string>  $imports  Imports to add, lowercased alias => class, filled in
     *
     * @return bool True when the alias refers to the class in the module file
     */
    private function claim(string $alias, string $class, array $existing, array &$imports): bool
    {
        $key = strtolower($alias);
        $taken = $imports[$key] ?? $existing[$key] ?? null;

        if ($taken !== null) {
            return strcasecmp($taken, $class) === 0;
        }

        $imports[$key] = $class;

        return true;
    }

    /**
     * Removes imports of moved controllers that the remaining file no longer uses.
     *
     * An import counts as used when its alias starts a name anywhere in the code or appears in a comment.
     * Imports of other classes and imports in group or multi-class statements are left alone.
     *
     * @param  string  $code  routes/api.php without the moved statements
     *
     * @return string The source without the unused imports
     *
     * @throws Error When the source is not valid PHP
     */
    private function removeUnusedImports(string $code): string
    {
        $file = ParsedFile::parse($code);
        $used = [];

        foreach ((new NodeFinder)->findInstanceOf($file->stmts, Name::class) as $name) {
            if (! $name instanceof Name\FullyQualified) {
                $used[strtolower($name->getFirst())] = true;
            }
        }

        foreach ($file->commentsBetween(0, strlen($code)) as $comment) {
            preg_match_all('/[A-Za-z_]\w*/', $comment->text, $words);

            foreach ($words[0] as $word) {
                $used[strtolower($word)] = true;
            }
        }

        $edits = new SourceEdits;

        foreach ($file->blocks() as $block) {
            foreach ($block['stmts'] as $stmt) {
                if (! $stmt instanceof Stmt\Use_ || $stmt->type !== Stmt\Use_::TYPE_NORMAL || count($stmt->uses) !== 1) {
                    continue;
                }

                $use = $stmt->uses[0];

                if (isset($this->controllerModules[strtolower($use->name->toString())]) && ! isset($used[strtolower($use->getAlias()->toString())])) {
                    $edits->replace($stmt->getStartFilePos(), $this->endOfLine($code, $stmt->getEndFilePos()), '');
                }
            }
        }

        return $edits->apply($code);
    }

    /**
     * Offset of the line break that ends the line of $offset, when only whitespace lies between.
     *
     * Cutting a statement up to there removes its line instead of leaving it blank.
     *
     * @param  string  $code  Source
     * @param  int  $offset  Last offset of a statement
     *
     * @return int The offset of the line break, or $offset when other code follows on the line
     */
    private function endOfLine(string $code, int $offset): int
    {
        $spaces = strspn($code, " \t", $offset + 1);
        $next = $offset + 1 + $spaces;

        if ($next >= strlen($code)) {
            return strlen($code) - 1;
        }

        return $code[$next] === "\n" ? $next : $offset;
    }

    /**
     * First line of a statement, shortened for messages.
     *
     * @param  string  $statement  Statement source
     *
     * @return string At most 80 characters
     */
    private function summary(string $statement): string
    {
        $line = trim(strtok($statement, "\n") ?: '');

        return strlen($line) > 80 ? substr($line, 0, 77).'...' : $line;
    }
}
