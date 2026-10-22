<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

/**
 * What consistent:rebuild is going to move, what it leaves in place and why.
 *
 * RebuildPlanner builds it before any file changes; with errors nothing may be written.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class RebuildPlan
{
    /**
     * Holds the plan.
     *
     * @param  list<Move>  $moves  Classes to move, models first
     * @param  list<array{class: string, reason: string}>  $kept  Classes left in place, with the reason
     * @param  list<string>  $errors  Problems that stop the run, such as two classes with one destination
     * @param  list<string>  $knownClasses  Fully qualified names of the classes in the scanned folders
     * @param  bool  $hasModels  Whether app/Models declares any class at all
     */
    public function __construct(
        public readonly array $moves,
        public readonly array $kept,
        public readonly array $errors,
        public readonly array $knownClasses,
        public readonly bool $hasModels,
    ) {}

    /**
     * Old class => new class of every move.
     *
     * @return array<string, string> Fully qualified names
     */
    public function classMap(): array
    {
        $map = [];

        foreach ($this->moves as $move) {
            $map[$move->oldClass] = $move->newClass;
        }

        return $map;
    }

    /**
     * Old controller class => module of every moved controller.
     *
     * @return array<string, string> Fully qualified name => module name
     */
    public function controllerModules(): array
    {
        $modules = [];

        foreach ($this->moves as $move) {
            if ($move->type === 'Controllers') {
                $modules[$move->oldClass] = $move->module;
            }
        }

        return $modules;
    }

    /**
     * Moves of models.
     *
     * @return list<Move> Model moves in plan order
     */
    public function modelMoves(): array
    {
        return array_values(array_filter($this->moves, fn (Move $move) => $move->type === 'Models'));
    }
}
