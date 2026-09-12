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

use PHPUnit\Framework\TestCase;

final class TemporaryDirectoryTest extends TestCase
{
    /** Hidden files are removed, but siblings and symlink targets belong to someone else. */
    public function testCleanupStaysInsideTheOwnedDirectory(): void
    {
        $directory = new TemporaryDirectory();
        $neighbor = new TemporaryDirectory();
        $sibling = $directory->path . '-keep';
        try {
            file_put_contents($sibling, 'sibling');
            file_put_contents($neighbor->path . '/keep', 'neighbor');
            mkdir($directory->path . '/.hidden/nested', 0o700, true);
            file_put_contents($directory->path . '/.hidden/nested/.file', 'fixture');
            symlink($neighbor->path, $directory->path . '/linked-directory');
            symlink($neighbor->path . '/keep', $directory->path . '/linked-file');

            $directory->remove();

            self::assertDirectoryDoesNotExist($directory->path);
            self::assertFileExists($sibling, 'cleanup must not traverse .. and delete another test\'s files');
            self::assertSame('sibling', file_get_contents($sibling));
            self::assertSame('neighbor', file_get_contents($neighbor->path . '/keep'));
        } finally {
            if (is_dir($directory->path)) {
                $directory->remove();
            }
            $neighbor->remove();
            if (is_file($sibling)) {
                unlink($sibling);
            }
        }
    }
}
