<?php

/**
 * This file is part of the Milpa framework skeleton.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace App\Tests\Plugins;

use App\Plugins\HelloPlugin\Controllers\HomeController;
use Milpa\Live\Support\DesignTokens;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE FIRST SCREEN ANYONE SEES IS COMPOSED, NOT HAND-WRITTEN.
 *
 * The page that decided for itself picked six hex values and two of them rendered invisible — 1.07:1
 * and 1.02:1, measured in a browser (greenhouse decisions/0298). So the mark is `brand-mark` and every
 * command is `code-block`, both from `milpa/live-web`, and what this page still writes is its layout
 * and its words (greenhouse decisions/0299).
 *
 * It is worth a test of its own because it is also an EXAMPLE: the first surface in a new app is
 * composed the way the developer's own surfaces should be.
 */
#[CoversClass(HomeController::class)]
final class TheWelcomePageIsComposedTest extends TestCase
{
    private static function page(): string
    {
        return (string) (new HomeController('Milpa is running'))
            ->index(new ServerRequest('GET', '/'))
            ->getBody();
    }

    /** The mark Rod could not find anywhere on the page, as the component that owns it. */
    public function testTheHousesMarkIsOnThePageAsAComponent(): void
    {
        $html = self::page();

        self::assertSame(1, substr_count($html, '<svg data-milpa-component="brand-mark"'));
        // READY, not growing: a mark left loading on a page that is already painted is the spinner
        // that never goes away.
        self::assertStringContainsString('data-state="ready"', $html);
    }

    /** Every command is a block, and every block offers to be taken. */
    public function testEveryCommandIsACodeBlockWithItsCopyAffordance(): void
    {
        $html = self::page();

        $blocks = substr_count($html, '<div data-milpa-component="code-block"');
        self::assertSame(8, $blocks, 'the first five minutes are eight commands');
        self::assertSame($blocks, substr_count($html, 'data-milpa-copy='), 'each one can be taken');
        self::assertStringContainsString('php bin/coa house:start', $html);
    }

    /**
     * 🚨 EIGHT BLOCKS, ONE STYLESHEET — the reason a component may ship a look at all.
     *
     * Emission is keyed by `name@version`, so a component used eight times costs its stylesheet once.
     * The alternative, per-instance styling, was measured at ~954 bytes an instance in a system that
     * shipped it.
     */
    public function testTheComponentsAssetsAreEmittedOnce(): void
    {
        $html = self::page();

        self::assertSame(1, substr_count($html, '<style data-milpa-assets="components">'));
        self::assertSame(1, substr_count($html, '<script data-milpa-assets="components">'));
    }

    /**
     * 🚨 NOT ONE COLOUR OF ITS OWN, and the single exception is the brand's.
     *
     * The only literal on the whole page is the mark's gold: the logo kit forbids painting the mark
     * with `var(--accent)`, and WCAG exempts logotypes from contrast adaptation. Every other value —
     * page and components alike — is a token the design system defines.
     */
    public function testTheOnlyLiteralColourOnThePageIsTheMarksGold(): void
    {
        preg_match_all('/#[0-9a-fA-F]{3,6}\b/', self::page(), $found);

        self::assertSame([DesignTokens::MARK_GOLD], array_values(array_unique($found[0])));
    }

    /**
     * The rules a component now owns are gone from the page, not merely overridden.
     *
     * A page that kept its own `pre` rules alongside the component's would have two authors for one
     * surface, and the one that wins would depend on source order.
     */
    public function testThePageNoLongerWritesTheRulesTheBlockOwns(): void
    {
        $html = self::page();

        self::assertStringNotContainsString('pre code {', $html);
        self::assertStringNotContainsString('<pre><code>php bin/coa', $html, 'no hand-written block survived');
    }

    /** The system is served and linked, dark-first, with the mark as the tab icon. */
    public function testItLinksTheSystemThisHouseServes(): void
    {
        $html = self::page();

        self::assertStringContainsString('href="/design/milpa-tokens.css"', $html);
        self::assertStringContainsString('href="/design/milpa-fonts.css"', $html);
        self::assertStringContainsString('href="/design/milpa-app-icon.svg"', $html);
    }

    /** The steps are an ordered list because they ARE a sequence — the marker reads the order. */
    public function testTheStepsAreAnOrderedListAndTheNumbersAreNotWrittenIntoTheWords(): void
    {
        $html = self::page();

        self::assertStringContainsString('<ol class="steps">', $html);
        self::assertStringContainsString('counter-increment: step', $html);
        self::assertStringNotContainsString('<li>1.', $html, 'the order is data, not typed prose');
    }
}
