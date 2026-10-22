<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Support;

use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * File writes that can be undone as a whole.
 *
 * consistent:create and consistent:rebuild write through it. Every file is written atomically (to a
 * temporary file next to it, then renamed), so a crash never leaves a half-written file, and its previous
 * contents are kept in memory until the transaction ends. rollback() puts every touched file back as it
 * was and removes the directories the transaction created, carrying on past failures and reporting them
 * instead of stopping at the first one.
 *
 * @package Adonyarik\ConsistentApi\Console\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class FileTransaction
{
    /**
     * Contents of every touched file before its first change; null when it did not exist.
     *
     * @var array<string, string|null>
     */
    private array $originals = [];

    /**
     * Directories the transaction created, parents first.
     *
     * @var list<string>
     */
    private array $createdDirectories = [];

    /**
     * Takes the filesystem to write through.
     *
     * @param  Filesystem  $files  Laravel's filesystem
     */
    public function __construct(private readonly Filesystem $files = new Filesystem) {}

    /**
     * Writes a file atomically, creating its directory when needed.
     *
     * An existing file keeps its permissions; a new one gets the permissions file_put_contents() would
     * give it.
     *
     * @param  string  $path  File to write
     * @param  string  $contents  New contents
     */
    public function put(string $path, string $contents): void
    {
        $this->remember($path);
        $this->ensureDirectory(dirname($path));

        $mode = is_file($path) ? fileperms($path) & 0777 : 0666 & ~umask();

        $this->files->replace($path, $contents, $mode);
    }

    /**
     * Deletes a file; nothing happens when it does not exist.
     *
     * @param  string  $path  File to delete
     */
    public function delete(string $path): void
    {
        $this->remember($path);

        if (is_file($path)) {
            $this->files->delete($path);
        }
    }

    /**
     * Puts every touched file back and removes the created directories that are empty again.
     *
     * Files are restored in reverse order of their first change; a file that did not exist is deleted.
     * A step that fails does not stop the others.
     *
     * @return list<string> One message per step that failed, empty when everything was restored
     */
    public function rollback(): array
    {
        $errors = [];

        foreach (array_reverse($this->originals, true) as $path => $contents) {
            try {
                if ($contents === null) {
                    if (is_file($path)) {
                        $this->files->delete($path);
                    }

                    continue;
                }

                $this->ensureDirectory(dirname($path));
                $this->files->put($path, $contents);
            } catch (Throwable $exception) {
                $errors[] = sprintf('Could not restore %s: %s', $path, $exception->getMessage());
            }
        }

        foreach (array_reverse($this->createdDirectories) as $directory) {
            if (is_dir($directory) && $this->files->isEmptyDirectory($directory)) {
                @rmdir($directory);
            }
        }

        $this->originals = [];
        $this->createdDirectories = [];

        return $errors;
    }

    /**
     * Contents of every touched file before its first change; null when it did not exist.
     *
     * @return array<string, string|null> Path => original contents
     */
    public function originals(): array
    {
        return $this->originals;
    }

    /**
     * Directories the transaction created, parents first.
     *
     * @return list<string> Absolute paths
     */
    public function createdDirectories(): array
    {
        return $this->createdDirectories;
    }

    /**
     * Keeps the contents of a file before its first change.
     *
     * @param  string  $path  File about to change
     */
    private function remember(string $path): void
    {
        if (! array_key_exists($path, $this->originals)) {
            $this->originals[$path] = $this->files->exists($path) ? $this->files->get($path) : null;
        }
    }

    /**
     * Creates a directory and its missing parents, remembering each one created.
     *
     * @param  string  $directory  Directory that must exist
     */
    private function ensureDirectory(string $directory): void
    {
        $missing = [];

        for ($current = $directory; ! is_dir($current) && dirname($current) !== $current; $current = dirname($current)) {
            $missing[] = $current;
        }

        foreach (array_reverse($missing) as $created) {
            $this->files->makeDirectory($created);
            $this->createdDirectories[] = $created;
        }
    }
}
