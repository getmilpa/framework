<?php

/**
 * This file is part of Milpa Framework — the composer create-project starting point for a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace App\Tests\Support;

/** A test owns this directory; cleanup must never walk into its parent or follow a symlink. */
final class TemporaryDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/milpa-test-' . bin2hex(random_bytes(8));
        mkdir($this->path, 0o700);
    }

    public function remove(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->path);
    }
}
