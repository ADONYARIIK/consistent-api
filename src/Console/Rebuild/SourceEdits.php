<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

use LogicException;

/**
 * Text replacements at byte offsets of a source, applied together.
 *
 * The rewriters record what to change while walking the syntax tree, whose nodes carry the offsets of the
 * original source; applying the edits from the end keeps every offset valid. Everything outside the edits
 * stays byte for byte as it was.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class SourceEdits
{
    /**
     * Edits as [start, length, text]; a length of 0 inserts.
     *
     * @var list<array{0: int, 1: int, 2: string}>
     */
    private array $edits = [];

    /**
     * Takes the offset of the text the edits will be applied to.
     *
     * Offsets passed to replace() and insert() are offsets of the whole file; with $base, the edits apply
     * to the part of the file that starts there, such as one statement.
     *
     * @param  int  $base  Offset of the edited text in the file
     */
    public function __construct(private readonly int $base = 0) {}

    /**
     * Replaces the bytes from $start to $end, both included.
     *
     * @param  int  $start  Offset of the first byte
     * @param  int  $end  Offset of the last byte
     * @param  string  $text  Replacement
     */
    public function replace(int $start, int $end, string $text): void
    {
        $this->edits[] = [$start - $this->base, $end - $start + 1, $text];
    }

    /**
     * Inserts text before the byte at $offset.
     *
     * @param  int  $offset  Offset to insert at
     * @param  string  $text  Text to insert
     */
    public function insert(int $offset, string $text): void
    {
        $this->edits[] = [$offset - $this->base, 0, $text];
    }

    /**
     * Tells whether any edit was recorded.
     *
     * @return bool True when apply() would change the source
     */
    public function isEmpty(): bool
    {
        return $this->edits === [];
    }

    /**
     * Applies every edit to the source.
     *
     * Insertions at the same offset keep the order they were recorded in.
     *
     * @param  string  $source  Source the offsets refer to
     *
     * @return string The edited source
     *
     * @throws LogicException When two replacements overlap, which would mean a bug in the rewriter
     */
    public function apply(string $source): string
    {
        $edits = $this->edits;

        // Stable sort by offset, then apply from the end so earlier offsets stay valid
        $order = array_keys($edits);
        usort($order, fn (int $left, int $right): int => [$edits[$left][0], $left] <=> [$edits[$right][0], $right]);

        $previousStart = PHP_INT_MAX;

        foreach (array_reverse($order) as $index) {
            [$start, $length, $text] = $edits[$index];

            if ($start + $length > $previousStart) {
                throw new LogicException(sprintf('Overlapping source edits at offset %d.', $start));
            }

            $source = substr_replace($source, $text, $start, $length);
            $previousStart = $start;
        }

        return $source;
    }
}
