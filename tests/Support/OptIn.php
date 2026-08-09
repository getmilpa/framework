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

use PHPUnit\Framework\Assert;

/**
 * The opt-in gate: what a fresh app can measure with what it actually installed.
 *
 * ── WHY THE UNIT IS THE ASSERTION, NOT THE TEST ──────────────────────────────────────────────────
 *
 * A newborn app — `composer create-project`, nothing added — inherits a suite written against the
 * full house. Some of it exercises the floor every app has; some of it exercises capabilities that
 * arrive with an opt-in package. The two are NOT split by test method: a single method routinely
 * asserts three floor things and one frontier thing, and skipping the whole method throws away the
 * three. That is what decisions/0013 measured and decided against.
 *
 * So the governing rule is: **if a property is measurable with zero opt-ins, it is measured with
 * zero opt-ins.** `needs()` is for a method that is frontier all the way down; `has()` is for the
 * mixed method, so its floor runs and reports GREEN rather than skipped.
 *
 * ── WHY THE SYMBOL AND NOT THE METADATA ──────────────────────────────────────────────────────────
 *
 * The check asks the autoloader, never `installed.json`: metadata can assert a capability whose code
 * fatals, and the app finds out at the wrong moment. It asks with all THREE of `class_exists`,
 * `interface_exists` and `function_exists`, because `class_exists` answers false for an interface
 * and `Milpa\AiGateway\ToolCallGate` is one.
 *
 * The production code already gates this way — `AgentOperations::sessions()` opens with
 * `class_exists(SessionStore::class)`. The gate agrees with the app rather than inventing a second
 * authority.
 */
final class OptIn
{
    /**
     * Namespace prefix → the package that ships it, with the constraint this skeleton develops
     * against. Every row is read from that package's own `composer.json`; none is guessed.
     *
     * The map exists for the TEACHING, not for the decision: the gate is decided by the symbol
     * above. An unmapped symbol still gates correctly — it just names itself instead of printing an
     * install line, which is honest about not knowing the package rather than inventing one.
     *
     * @var array<string, string>
     */
    private const PACKAGES = [
        'Milpa\\Agent\\'      => 'milpa/agent:^0.8.0',
        'Milpa\\AiGateway\\'  => 'milpa/ai-gateway:^0.8.2',
        'Milpa\\Auth\\'       => 'milpa/auth:^0.3.8',
        'Milpa\\Data\\'       => 'milpa/data:^0.2.4',
        'Milpa\\DevTools\\'   => 'milpa/devtools:^0.13.0',
        'Milpa\\EventStore\\' => 'milpa/event-store',
        'Milpa\\McpServer\\'  => 'milpa/mcp-server:^0.4.5',
    ];

    /**
     * Is every named symbol loadable right now?
     *
     * The form for a MIXED method: wrap only the frontier assertions, leave the floor outside, and
     * the method reports green on a bare app instead of vanishing into the skip count.
     */
    public static function has(string ...$symbols): bool
    {
        foreach ($symbols as $symbol) {
            if (!class_exists($symbol) && !interface_exists($symbol) && !function_exists($symbol)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Skip this method unless every named symbol is present — and say what would bring it back.
     *
     * The form for a method that is frontier ALL THE WAY DOWN. A skip that does not teach is the
     * candidate this house already measured as losing, so the message always carries the install
     * line for what is missing.
     */
    public static function needs(string ...$symbols): void
    {
        $missing = array_values(array_filter($symbols, static fn (string $s): bool => !self::has($s)));
        if ($missing === []) {
            return;
        }

        // THE SKIP BUDGET IS NOT A BLANK CHEQUE (decisions/0013, measured in evidence/0103).
        //
        // A house that can skip tests without anyone noticing has no budget, it has an excuse. With
        // MILPA_SUITE_COMPLETE=1 a missing symbol FAILS instead of skipping, so the greenhouse — where
        // every opt-in is installed — can prove that its own suite skips nothing. If a skip ever
        // appears there, either an opt-in went missing or a gate is wrong, and both should be loud.
        if (getenv('MILPA_SUITE_COMPLETE') === '1') {
            Assert::fail('MILPA_SUITE_COMPLETE=1 y falta un opt-in: ' . self::teach($missing));
        }

        Assert::markTestSkipped(self::teach($missing));
    }

    /**
     * The teaching a missing opt-in prints: what is absent, and the command that installs it.
     *
     * @param list<string> $missing
     */
    private static function teach(array $missing): string
    {
        $install = [];
        foreach ($missing as $symbol) {
            foreach (self::PACKAGES as $prefix => $package) {
                if (str_starts_with($symbol, $prefix)) {
                    $install[$package] = true;
                    break;
                }
            }
        }

        $line = 'needs ' . implode(', ', $missing);

        return $install === []
            ? $line . ' — no package in this skeleton declares that namespace'
            : $line . "\n  composer require --dev " . implode(' ', array_keys($install));
    }
}
