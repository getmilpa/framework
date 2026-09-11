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

    /**
     * The page with every CSS comment removed — what a selector claim has to be measured against.
     *
     * 🚨 CSS COMMENTS SHIP TO THE BROWSER, AND THIS PAGE'S COMMENTS QUOTE THE SELECTORS THEY FORBID.
     * Two assertions in this suite failed on their own prose before this existed: the note explaining
     * why `pre code { background: none }` was retired CONTAINS that selector. It happened twice in one
     * session — once here and once in `milpa/live-web`, whose scoper copies comments verbatim — which
     * makes it a habit and not an accident: a claim about what a stylesheet DOES cannot be answered by
     * text that merely talks about it.
     */
    private static function selectorsOnly(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', self::page());
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

        self::assertStringNotContainsString('pre code {', self::selectorsOnly());
        self::assertStringNotContainsString('<pre><code>php bin/coa', $html, 'no hand-written block survived');
    }

    /**
     * 🚨 THE PAGE'S OWN `code` RULE DOES NOT REACH INSIDE A COMPONENT.
     *
     * A bare `code { background: … }` claims every `code` on the page, the component's included —
     * measured in a browser as a chip with its own padding and a 6px radius behind the command, drawn
     * by this page and not by the block. It is the same bug the retired `pre code { background: none }`
     * line used to patch, and scoping cannot prevent it: scoping keeps two COMPONENTS apart, not a
     * host document's element selectors. The page narrows its claim to the prose it owns.
     */
    public function testThePagesInlineCodeRuleOnlyClaimsItsOwnProse(): void
    {
        $html = self::page();

        self::assertStringContainsString('p code {', $html);
        self::assertDoesNotMatchRegularExpression(
            '/^\s*code\s*\{/m',
            self::selectorsOnly(),
            'a bare element selector would repaint the command inside every code-block on the page',
        );
    }

    /** The system is served and linked, dark-first, with the mark as the tab icon. */
    public function testItLinksTheSystemThisHouseServes(): void
    {
        $html = self::page();

        self::assertStringContainsString('href="/design/milpa-tokens.css"', $html);
        self::assertStringContainsString('href="/design/milpa-fonts.css"', $html);
        self::assertStringContainsString('href="/design/milpa-app-icon.svg"', $html);
    }

    /**
     * 🚨 THE PAGE ITSELF NEVER SCROLLS SIDEWAYS — and the longest identifier on it is why it could.
     *
     * Measured at a 500px viewport: the chip holding
     * `App\Plugins\HelloPlugin\Controllers\HomeController` rendered 502px wide and pushed the
     * document to 530px. A namespaced class name has no break opportunity — not even at the backslash —
     * so with `overflow-wrap: normal` the longest identifier in the prose sets the page's width.
     *
     * The commands are deliberately NOT covered by this: they live in `code-block`, which scrolls them
     * inside its own body, because a wrapped shell line reads as two commands. Measured in the same
     * run: 472px of command scrolling inside a 305px block while the document stayed at the viewport.
     */
    public function testAProseChipMayBreakMidWordSoTheLongestClassNameCannotWidenThePage(): void
    {
        $css = self::selectorsOnly();

        self::assertMatchesRegularExpression(
            '/p code\s*\{[^}]*overflow-wrap:\s*anywhere/',
            $css,
            'a namespaced class name in a chip would otherwise set the width of the whole page',
        );

        // And the block is NOT given the same permission: it scrolls instead of wrapping.
        self::assertStringNotContainsString('.line { overflow-wrap', $css);
    }

    /**
     * 🚨 NO `line-height` RIDES IN THE `font` SHORTHAND, because it would set every heading's leading.
     *
     * The shorthand carried `/1.6` — a literal the token set does not contain — and a `line-height` in
     * `font` is inherited by every heading on the page, so the h1 sat in a line box meant for running
     * text. The system ships four leading tokens and the headings now choose their own.
     */
    public function testTheBodyDoesNotLeakItsLeadingIntoEveryHeading(): void
    {
        $css = self::selectorsOnly();

        self::assertDoesNotMatchRegularExpression('#font:\s*var\(--text-base\)/#', $css, 'a leading in the shorthand is inherited by headings');
        self::assertStringContainsString('line-height: var(--leading-relaxed)', $css);
        self::assertStringContainsString('line-height: var(--leading-tight)', $css, 'the display heading sets its own');
    }

    /**
     * 🚨 THE HEADLINE IS SIZED AGAINST THE MARK, and both scale on one principle.
     *
     * Measured: the h1 at `--text-xl` was 20px in a 32px box beside a 144px mark — 4.5:1 — so the first
     * screen read as a big logo with a caption. The gap did not close as the window grew: the mark
     * clamps at 9rem above a 1108px viewport while a fixed heading stays put, so it locked at its worst
     * on exactly the wide screen this page is opened on. The mark sizes itself with a clamp in its own
     * stylesheet; the headline now does too.
     */
    public function testTheHeadlineIsSizedAgainstTheMarkAndNotAgainstTheParagraph(): void
    {
        $css = self::selectorsOnly();

        self::assertMatchesRegularExpression(
            '/\.hero h1\s*\{[^}]*font-size:\s*clamp\(var\(--text-2xl\), 4vw, var\(--text-4xl\)\)/',
            $css,
        );
        self::assertStringContainsString('letter-spacing: var(--tracking-display)', $css, 'display type is not set on body tracking');
        self::assertStringContainsString('text-wrap: balance', $css, 'the greeting comes from config and can be any length');

        // And the rung below it, or the flatness just moves down one element: everything the reader
        // scrolls through lives under the h2.
        self::assertMatchesRegularExpression('/h2\s*\{[^}]*font-size:\s*var\(--text-2xl\)/', $css);
    }

    /**
     * 🚨 A CHIP'S GROUND DIFFERS FROM EVERY SURFACE IT CAN LAND ON.
     *
     * Measured: of the ten chips on this page, the four inside the panel sat at 1.000:1 against their
     * own ground — `p code` was painted `--surface` and so is `.next` — so the same markup rendered as
     * a chip in one half of the page and as plain text in the other.
     */
    public function testAChipIsNotPaintedTheColourOfThePanelItSitsIn(): void
    {
        $css = self::selectorsOnly();

        self::assertMatchesRegularExpression('/p code\s*\{[^}]*background:\s*var\(--surface-raised\)/', $css);
        self::assertMatchesRegularExpression('/\.next\s*\{[^}]*background:\s*var\(--surface\)/', $css);
    }

    /** The measure is the token that holds it, not a literal that happens to equal it. */
    public function testTheMeasureIsTheTokenTheSystemShipsForIt(): void
    {
        self::assertStringContainsString('max-width: var(--container-narrow)', self::selectorsOnly());
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
