<?php

/**
 * This file is part of milpa/framework — the entry point for creating YOUR Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace App\Tests\Boot;

use PHPUnit\Framework\TestCase;

/**
 * Every Milpa is born with a constitution; not every Milpa needs a government yet.
 *
 * The newborn ships the foundation kit (spec-foundation §3): a constitution that SAYS it is
 * unfounded — an explicit «not yet», readable by any surface, instead of a missing file that says
 * nothing — plus the empty homes for decisions and slice evidence. The founding rite (session #1)
 * fills the constitution; nothing here scaffolds a domain.
 */
final class FoundationTest extends TestCase
{
    private const FOUNDATION = __DIR__ . '/../../.milpa/foundation.json';

    /** The constitution ships, is valid JSON, and declares the versioned schema. */
    public function testTheNewbornCarriesItsConstitution(): void
    {
        self::assertFileExists(self::FOUNDATION);

        $constitution = json_decode((string) file_get_contents(self::FOUNDATION), true);
        self::assertIsArray($constitution);
        self::assertSame('milpa.foundation/v1', $constitution['schema'] ?? null);
    }

    /**
     * The newborn is EXPLICITLY unfounded: domain and founding date are null, not absent — an
     * unfounded app asked for domain work must found first, and detecting that state has to be
     * trivial and honest.
     */
    public function testTheNewbornSaysItIsNotFoundedYet(): void
    {
        $constitution = json_decode((string) file_get_contents(self::FOUNDATION), true);
        self::assertIsArray($constitution);

        self::assertArrayHasKey('domain', $constitution);
        self::assertNull($constitution['domain']);
        self::assertArrayHasKey('founded_at', $constitution);
        self::assertNull($constitution['founded_at']);
    }

    /** No app is born lawless: even unfounded, the authorities default to the human. */
    public function testEvenUnfoundedTheAuthoritiesDefaultToTheHuman(): void
    {
        $constitution = json_decode((string) file_get_contents(self::FOUNDATION), true);
        self::assertIsArray($constitution);

        self::assertSame('human', $constitution['authorities']['product'] ?? null);
        self::assertSame('human', $constitution['authorities']['destructive_changes'] ?? null);
    }

    /** The homes for decisions and slice evidence exist from birth — used from the founding itself. */
    public function testTheDecisionAndEvidenceHomesExistFromBirth(): void
    {
        self::assertDirectoryExists(\dirname(self::FOUNDATION) . '/decisions');
        self::assertDirectoryExists(\dirname(self::FOUNDATION) . '/evidence');
    }
}
