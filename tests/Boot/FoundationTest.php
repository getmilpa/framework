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
     * The constitution says which of the two states it is in, EXPLICITLY, in both directions.
     *
     * A newborn is unfounded with `domain` and `founded_at` present and null — not absent — because
     * an app asked for domain work has to detect that state trivially and honestly, and a missing
     * key and a null one are different facts.
     *
     * ── WHY THIS LOOKS AT BOTH STATES AND NOT ONLY THE FIRST ────────────────────────────────────
     *
     * The previous version asserted `domain === null` flatly, which turned USING the app into
     * breaking its suite. The first command a newborn is taught is `foundation:found`; the moment
     * anyone ran it — that is, the moment they did what the system asked — their `phpunit` went red
     * for having founded. A test that only tolerates the factory state teaches the user that the app
     * broke when what they did was start using it.
     *
     * What matters is not that the domain is null. It is that the file is never AMBIGUOUS about
     * which state it is in. So the pair is asserted in both directions — unfounded, both null;
     * founded, both present and neither empty — and no in-between passes.
     */
    public function testTheConstitutionSaysWhichStateItIsIn(): void
    {
        $constitution = json_decode((string) file_get_contents(self::FOUNDATION), true);
        self::assertIsArray($constitution);

        self::assertArrayHasKey('domain', $constitution);
        self::assertArrayHasKey('founded_at', $constitution);

        if ($constitution['founded_at'] === null) {
            self::assertNull(
                $constitution['domain'],
                'an app with no founding date must not carry a domain: that is neither state',
            );

            return;
        }

        self::assertIsString($constitution['domain']);
        self::assertNotSame('', trim($constitution['domain']), 'a founded app names its domain');
        self::assertIsString($constitution['founded_at']);
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
