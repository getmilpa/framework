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

namespace App\Tests\Framework;

use App\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * A HOUSE HAS TO KNOW WHICH FRAMEWORK IT WAS BORN FROM, and until now none did.
 *
 * `milpa/framework` is a SKELETON: `create-project` copies its files and the package is then gone.
 * Measured on cattle before this existed: zero occurrences of `milpa/framework` in the app's
 * `composer.lock`, no `version` in the app's `composer.json`, and nothing anywhere recording the
 * release. So «which framework does this house run on» had no answer, and no update could be computed
 * (greenhouse decisions/0291).
 *
 * 🚨 And the birth HASHES matter as much as the number. A reconciliation needs three points: what the
 * house was born with, what it has now, and what the new skeleton ships. Without the originals you
 * cannot tell a file the APP customized from a file the SKELETON changed — and those demand opposite
 * answers. The originals are knowable at exactly one moment, so they are written then.
 */
final class TheHouseRecordsWhichFrameworkItWasBornFromTest extends TestCase
{
    /** @var list<TemporaryDirectory> */
    private array $trees = [];

    protected function tearDown(): void
    {
        foreach ($this->trees as $tree) {
            $tree->remove();
        }
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function stamp(): void
    {
        require_once self::root() . '/tools/stamp-framework.php';
    }

    /**
     * 🚨 THE FILE THAT CARRIES THE SKELETON'S OWN WIRING IS TRACKED, and it was not.
     *
     * Measured the first time a real diff was taken between two published releases: 0.47.1 → 0.48.0
     * changed ZERO tracked files, while the actual change of that release was `composer.json` gaining
     * the `post-create-project-cmd` that writes the birth record, plus `tools/stamp-framework.php`
     * itself. A reconciliation would have told a house «nothing to reconcile» about the very release
     * that gave it the ability to reconcile (greenhouse decisions/0294).
     */
    public function testTheTrackedSetIncludesComposerJsonAndTheToolsAnAppRuns(): void
    {
        self::stamp();
        $tracked = \FrameworkStamp::hashes(self::root());

        self::assertArrayHasKey('composer.json', $tracked, 'the file a house edits first and the skeleton changes most consequentially');
        self::assertArrayHasKey('tools/stamp-framework.php', $tracked, 'an app runs the tools; a newer one has to be offerable');
        self::assertArrayHasKey('public/index.php', $tracked, 'and the entry point, which was always there');
        self::assertArrayNotHasKey('phpunit.xml', $tracked, 'the skeleton\'s own harness is not something a house diverges from');
    }

    /** The tree keeps its version both before and after Composer writes its birth record. */
    public function testTheTreeCarriesTheVersionAndAValidBirthRecordWhenStamped(): void
    {
        $record = json_decode((string) file_get_contents(self::root() . '/.milpa/framework.json'), true);

        self::assertIsArray($record);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $record['version'], 'a real release number, not a range');

        // create-project runs the stamp before the user can run this suite. A birth record is
        // expected there; it must remain valid even after the house customizes its tracked files.
        if (array_key_exists('born', $record)) {
            self::assertIsArray($record['born']);
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $record['born']['version']);
            self::assertIsString($record['born']['at']);
            self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $record['born']['at']));
            self::assertIsArray($record['born']['files']);
            self::assertNotEmpty($record['born']['files']);
            foreach ($record['born']['files'] as $hash) {
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
            }
        }

        $config = json_decode((string) file_get_contents(self::root() . '/.github/release-please-config.json'), true);
        self::assertIsArray($config);
        $extra = $config['packages']['.']['extra-files'] ?? [];
        self::assertContains(
            ['type' => 'json', 'path' => '.milpa/framework.json', 'jsonpath' => '$.version'],
            $extra,
            'release-please must bump this file, or it goes stale the first release after it was written',
        );
    }

    /** The version in the shipped file is the one release-please is tracking. */
    public function testTheShippedVersionAgreesWithTheReleaseManifest(): void
    {
        $record = json_decode((string) file_get_contents(self::root() . '/.milpa/framework.json'), true);
        $manifest = json_decode((string) file_get_contents(self::root() . '/.github/.release-please-manifest.json'), true);

        self::assertIsArray($record);
        self::assertIsArray($manifest);
        self::assertSame($manifest['.'], $record['version'], 'two files naming one version is a lie waiting to happen — this is the assertion that keeps them one');
    }

    /** Composer runs the stamp exactly once, at create-project time. */
    public function testComposerRunsTheStampWhenAHouseIsCreated(): void
    {
        $composer = json_decode((string) file_get_contents(self::root() . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertSame(['@php tools/stamp-framework.php'], $composer['scripts']['post-create-project-cmd'] ?? null);
    }

    /** Stamping an unstamped tree records the version, a time, and a hash per tracked file. */
    public function testStampingRecordsTheVersionAndAHashPerFile(): void
    {
        self::stamp();
        $tree = $this->freshTree();

        $skeleton = json_decode((string) file_get_contents($tree . '/.milpa/framework.json'), true);
        self::assertIsArray($skeleton);
        self::assertArrayNotHasKey('born', $skeleton, 'the fixture starts as a skeleton, before Composer stamps it');

        self::assertSame(0, \FrameworkStamp::main($tree));

        $record = json_decode((string) file_get_contents($tree . '/.milpa/framework.json'), true);
        self::assertIsArray($record);
        self::assertSame('9.9.9', $record['born']['version']);
        self::assertNotSame('', (string) $record['born']['at']);
        self::assertSame(
            hash('sha256', "<?php // the app's entry point\n"),
            $record['born']['files']['public/index.php'],
            'the ORIGINAL bytes, which is the whole point — after today they are gone',
        );
        self::assertArrayNotHasKey('tests/SomethingTest.php', $record['born']['files'], 'the skeleton\'s own tooling is not something a house diverges from');
    }

    /**
     * A SECOND stamp leaves the first alone.
     *
     * Otherwise `create-project` into an existing tree — or any accidental re-run — would overwrite the
     * birth record with today's bytes, and the house would silently forget every change it had made.
     * That is worse than having no record: it would report a customized file as untouched.
     */
    public function testStampingTwiceDoesNotOverwriteTheBirthRecord(): void
    {
        self::stamp();
        $tree = $this->freshTree();
        \FrameworkStamp::main($tree);

        file_put_contents($tree . '/public/index.php', "<?php // the app CHANGED this\n");
        self::assertSame(0, \FrameworkStamp::main($tree));

        $record = json_decode((string) file_get_contents($tree . '/.milpa/framework.json'), true);
        self::assertIsArray($record);
        self::assertSame(
            hash('sha256', "<?php // the app's entry point\n"),
            $record['born']['files']['public/index.php'],
            'the birth record still holds the ORIGINAL, so the change is still visible as a change',
        );
    }

    /** A tree with no record says so and fails, rather than inventing a version. */
    public function testATreeWithNoRecordRefuses(): void
    {
        self::stamp();
        $tree = $this->freshTree();
        unlink($tree . '/.milpa/framework.json');

        self::assertSame(1, \FrameworkStamp::main($tree));
    }

    /** A minimal skeleton-shaped tree. */
    private function freshTree(): string
    {
        $directory = new TemporaryDirectory();
        $this->trees[] = $directory;
        $tree = $directory->path;
        mkdir($tree . '/.milpa', 0o777, true);
        mkdir($tree . '/public', 0o777, true);
        mkdir($tree . '/config', 0o777, true);
        mkdir($tree . '/tests', 0o777, true);
        file_put_contents($tree . '/.milpa/framework.json', json_encode(['version' => '9.9.9']));
        file_put_contents($tree . '/public/index.php', "<?php // the app's entry point\n");
        file_put_contents($tree . '/config/app.php', "<?php return [];\n");
        file_put_contents($tree . '/tests/SomethingTest.php', "<?php // the skeleton's own\n");

        return $tree;
    }
}
