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

use PHPUnit\Framework\TestCase;

/**
 * THE README IS THE FIRST THING A NEW HOUSE IS READ BY, and it described a house that no longer exists.
 *
 * Measured on a clone and on a `create-project` (greenhouse evidence/1082): its Layout listed
 * `src/Operations`, `src/Auth` and `src/Tui` — moved to milpa/app-runtime at 0.21 — and it showed
 * `coa agent "…"` on a house where that answers «no such command», and, once enabled, refuses unless
 * signed. Both are facts a test can read, so they stop being a matter of somebody noticing.
 *
 * The README ships into every house and its owner may rewrite it: without the sections this reads,
 * there is nothing to hold it to, and it skips instead of failing.
 */
final class TheReadmeDescribesTheHouseItShipsWithTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    public function testEveryPathTheLayoutNamesExists(): void
    {
        $layout = $this->layoutBlock();

        preg_match_all('#^(\S+)\s#m', $layout, $m);
        self::assertNotEmpty($m[1], 'the Layout block names no path');
        foreach ($m[1] as $path) {
            self::assertFileExists(self::ROOT . '/' . rtrim($path, '/'), "README Layout names «{$path}», which this house does not have");
        }
    }

    public function testEveryAgentAndTokenExampleIsSigned(): void
    {
        $readme = $this->readme();
        // Both declare a lasting change, and the terminal refuses one unsigned (greenhouse decisions/0522).
        preg_match_all('#^\$? ?php bin/coa (agent|token:new) .*$#m', $readme, $m);
        if ($m[0] === []) {
            self::markTestSkipped('this README shows no `coa agent` or `coa token:new` example');
        }

        foreach ($m[0] as $line) {
            self::assertStringContainsString('--sign', $line, "an unsigned lasting change is refused, yet the README shows: {$line}");
        }
        if (!str_contains($readme, 'php bin/coa agent ')) {
            return;
        }
        // And the agent is shown only after the capabilities that bring it.
        $agent = strpos($readme, 'php bin/coa agent ');
        foreach (['capabilities:enable milpa/ai-gateway', 'capabilities:enable milpa/agent '] as $enable) {
            $at = strpos($readme, $enable);
            self::assertNotFalse($at, "the README runs `coa agent` without ever showing `{$enable}`");
            self::assertLessThan($agent, $at, "`{$enable}` must come before the first `coa agent`");
        }
    }

    private function readme(): string
    {
        $readme = @file_get_contents(self::ROOT . '/README.md');
        if (!\is_string($readme)) {
            self::markTestSkipped('this house has no README.md');
        }

        return $readme;
    }

    private function layoutBlock(): string
    {
        if (preg_match('/^## Layout\s*\n+```\n(.*?)\n```/ms', $this->readme(), $m) !== 1) {
            self::markTestSkipped('this README has no Layout block');
        }

        return $m[1];
    }
}
