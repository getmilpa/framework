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

/**
 * RECORDS WHICH FRAMEWORK THIS HOUSE WAS BORN FROM — run once, by composer, at create-project time.
 *
 * ── WHY THIS HAS TO EXIST AT ALL ────────────────────────────────────────────────────────────────────
 *
 * `milpa/framework` is a SKELETON: `create-project` copies its files and then the package is gone. It
 * is not in the app's `composer.lock`, the app's own `composer.json` inherits the skeleton's name with
 * no version, and nothing recorded which release the tree came from. Measured on cattle before this
 * file existed: zero occurrences of `milpa/framework` in the lock and no version anywhere. So the
 * panel could not answer «which framework does this house run on», and no update could be computed at
 * all (greenhouse decisions/0291).
 *
 * ── AND WHY IT RECORDS HASHES, NOT JUST A NUMBER ────────────────────────────────────────────────────
 *
 * 🚨 A reconciliation needs THREE points, not two: what the house was BORN with, what the house has
 * NOW, and what the new skeleton SHIPS. With only the version and today's files you cannot tell «the
 * app customized `public/index.php`» from «the skeleton changed `public/index.php`» — and those two
 * demand opposite answers. One is a file to leave alone; the other is a file to offer. So the birth
 * hashes are written HERE, at the one moment they are knowable, because afterwards the original bytes
 * are gone.
 *
 * Only files a HOUSE can edit are recorded: the entry points, the config, the starter plugin, the
 * runner. Not the tests, not the CI, not the docs — those are the skeleton's own tooling, and an app
 * that deletes them has not diverged from anything it will ever be offered.
 */
final class FrameworkStamp
{
    /**
     * The skeleton's own files, as globs — everything `create-project` hands over that a house may edit.
     *
     * A glob and not a hard list because the list would go stale silently: a file added to the skeleton
     * and forgotten here would be invisible to every future reconciliation, which is the failure mode
     * that looks like «nothing changed».
     */
    private const array TRACKED = [
        'bin/*',
        'config/*.php',
        'public/*.php',
        'src/*.php',
        'src/*/*.php',
        'src/*/*/*.php',
        'src/*/*/*/*.php',
        'recipes/*.json',
    ];

    public static function main(string $root): int
    {
        $record = $root . '/.milpa/framework.json';
        if (!is_file($record)) {
            fwrite(\STDERR, "milpa: .milpa/framework.json is missing, so this tree cannot say which framework it is.\n");

            return 1;
        }

        /** @var array<string, mixed> $stamp */
        $stamp = json_decode((string) file_get_contents($record), true) ?: [];
        $version = \is_string($stamp['version'] ?? null) ? $stamp['version'] : '';
        if ($version === '') {
            fwrite(\STDERR, "milpa: .milpa/framework.json carries no version.\n");

            return 1;
        }

        // Already stamped: a second create-project into an existing tree would otherwise overwrite the
        // birth record with today's bytes, and a house would silently forget every change it had made.
        if (isset($stamp['born'])) {
            return 0;
        }

        $stamp['born'] = [
            'version' => $version,
            // `date('c')` and not a clock service: this runs as a composer script in a bare tree, before
            // any container exists. It is the one place in the family where that is not a shortcut.
            'at' => date('c'),
            'files' => self::hashes($root),
        ];

        file_put_contents($record, json_encode($stamp, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(\STDOUT, \sprintf(
            "milpa: this house was born from framework %s — %d files recorded in .milpa/framework.json\n",
            $version,
            \count($stamp['born']['files']),
        ));

        return 0;
    }

    /**
     * Every tracked file, path relative to the root, keyed to the sha256 of its bytes.
     *
     * @return array<string, string>
     */
    public static function hashes(string $root): array
    {
        $out = [];
        foreach (self::TRACKED as $glob) {
            foreach (glob($root . '/' . $glob) ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $relative = substr($path, \strlen($root) + 1);
                $out[$relative] = hash_file('sha256', $path) ?: '';
            }
        }
        ksort($out);

        return $out;
    }
}

if (\PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(FrameworkStamp::main(\dirname(__DIR__)));
}
