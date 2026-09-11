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

namespace App\Plugins\HelloPlugin\Controllers;

use Milpa\Live\Assets\ComponentAssetOrchestrator;
use Milpa\Live\Assets\PageAssets;
use Milpa\Live\Components\BrandMarkComponent;
use Milpa\Live\Components\CodeBlockComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use App\Plugins\HelloPlugin\HelloPlugin;
use App\Plugins\HelloPlugin\HouseState;
use Milpa\Live\Rendering\BrandMarkHtmlRenderer;
use Milpa\Live\Rendering\CodeBlockHtmlRenderer;
use Milpa\Live\Support\DesignTokens;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * This app's only controller: proves `Milpa\Runtime\Http\RequestHandler` dispatched a real request
 * end to end. Returns a plain PSR-7 {@see Response} built with `nyholm/psr7` — the app's declared
 * PSR-7 implementation (`milpa/http` ships only the routing contracts, no concrete message classes).
 *
 * 🚨 IT COMPOSES COMPONENTS; IT DOES NOT WRITE THE HTML THEY OWN.
 *
 * The mark is `brand-mark` and every command is `code-block`, both from `milpa/live-web` — the
 * framework's own UI system (greenhouse decisions/0189). What this file still writes is the page's
 * LAYOUT and its words, which are its own; what a block of code or the house's mark looks like is
 * not, and the version of this page that decided for itself picked six hex values, two of which
 * rendered invisible in a browser (1.07:1 and 1.02:1, greenhouse decisions/0298).
 *
 * That is also why the page is worth reading as an example: the first surface anyone sees in a new
 * app is composed the way their own surfaces should be, and `components:catalogue` lists what else
 * is available to compose with.
 *
 * The `$greeting` is NOT read here — {@see \App\Plugins\HelloPlugin\HelloPlugin::boot()} pulls it
 * out of `config/app.php` via `Milpa\Runtime\Config` and constructs this controller with it.
 */
final class HomeController
{
    /**
     * THE DOOR — one command, because the house knows its own state and this page does not.
     *
     * 🚨 THIS USED TO BE EIGHT NUMBERED STEPS, and the defect was not their number. It was that a page
     * cannot know what an app's next step is. It mixed three different stories into one run — meeting
     * the house (`house:start`, `list`, `capabilities`), equipping it (`capabilities:enable`,
     * `recipe:apply`) and operating it (`shell`, `serve`) — and it hardcoded an order that goes stale
     * the moment somebody switches a capability on.
     *
     * `house:start` already answers all of it, from the app's real authorities: its `next` is declared
     * as «the next real steps, in order: each {step, command, why} — every command is one this app
     * offers today», and its observable evidence is that «following the first one literally changes
     * what `house:start` answers next». So this page hands over the door and stops there. The state
     * knows, the house decides, the surface projects — and a surface that RETYPED the steps would be
     * inventing a criterion it does not own.
     *
     * Not the state panel itself, and that is measured rather than chosen: `house:start` is a read
     * (`EffectProfile::readOnly()`) but it declares `surfaces: ['cli', 'tui', 'mcp']` and NOT http,
     * because «the answer carries the app's filesystem root, and a route the ops surface publishes
     * under `expose: ['*']` answers without a principal». This page is the one surface a fresh app
     * serves with no identity at all, so projecting that answer here is a decision about what is safe
     * without a principal — not a wiring job (greenhouse decisions/0301).
     */
    private const string DOOR = 'php bin/coa house:start';

    /**
     * The three ways out, for a reader who would rather look around than be told.
     *
     * One line each, because they are not steps: they are alternatives, and numbering alternatives is
     * the defect the eight-step list had. The `--dry-run` is here on purpose and not for symmetry —
     * it teaches, in the first minute, that this house lets you ask what a change would do before
     * making it, which is the doctrine the rest of the framework is built on.
     *
     * 🚨 FOUR LABELS, FOUR CONCEPTS, AND NOT ONE OF THEM EXPLAINS ITSELF. They used to read «See what
     * it can do, AND WHAT IT COULD DO NEXT» and «Let a recipe do the first hour, PAUSING FOR YOUR
     * CONSENT» — each one describing the mechanism behind the command instead of naming what the
     * reader wants. The commands demonstrate their own mechanisms when they run; a label that promises
     * what a command will do is a promise the page has to keep in sync with a package it does not own.
     * What survives is the concept: what it knows, what a change would do, reuse, and going in.
     *
     * @var list<array{does: string, command: string}>
     */
    private const array WAYS_OUT = [
        ['does' => 'See what it can do', 'command' => 'php bin/coa capabilities'],
        ['does' => 'Preview a change', 'command' => 'php bin/coa capabilities:enable milpa/agent --dry-run'],
        ['does' => 'Apply a recipe', 'command' => 'php bin/coa recipe:apply --recipe=notes'],
        // «Work in», not «Enter»: you are already inside — the app is answering this request. What the
        // shell adds is a place to WORK, which is the concept the other three are named by too.
        ['does' => 'Work in the house', 'command' => 'php bin/coa shell'],
    ];

    /**
     * Where the house can be read and where it is published — the only two links on this page.
     *
     * Absolute and literal because they are not this app's: they are the framework's own, and an app
     * that moves does not move them. The landing is `.lat` on purpose — it is Spanish-first, which the
     * TLD earns (greenhouse decisions/0138).
     *
     * @var array<string, string>
     */
    private const array ELSEWHERE = [
        'Source' => 'https://github.com/getmilpa/framework',
        'milpa.lat' => 'https://milpa.lat',
    ];

    /** @var \Closure(): HouseState how this page asks the house where it stands, at request time */
    private readonly \Closure $state;

    /**
     * @param string|null $version which `milpa/framework` this house runs, or null when nothing can say
     */
    public function __construct(
        private readonly string $greeting,
        private readonly ?string $version = null,
        // WHY A CLOSURE AND NOT THE STATE ITSELF: the Kernel enters the container AFTER the boot
        // (greenhouse evidence/0294), and this controller is built during it. Resolved at request
        // time, the answer is the running house's; resolved at boot, there is no house to ask yet.
        // Absent → the page renders no strip, which is what a surface with no authority should do.
        ?\Closure $state = null,
    ) {
        $this->state = $state ?? static fn (): HouseState => HouseState::unasked();
    }

    /**
     * The page, as one HTML response.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $this->html());
    }

    /**
     * Renders one component with one renderer — the three-line shape every Milpa surface uses.
     *
     * @param array<string, mixed> $props
     */
    private static function compose(
        ComponentDefinitionInterface $component,
        ComponentRendererInterface $renderer,
        array $props,
        string $id,
    ): string {
        $context = new ComponentContext(componentId: $id);

        return $renderer->render($component, new RenderRequest(
            context: $context,
            props: $props,
            state: $component->mount($props, $context),
            target: RenderTarget::HTML,
        ))->output;
    }

    /**
     * What this page's components declared — read, scoped and deduplicated, emitted once.
     *
     * Eight code blocks cost their stylesheet once: emission is keyed by `name@version`, which is
     * the whole reason a component may ship a look at all.
     */
    private static function assets(): PageAssets
    {
        return (new ComponentAssetOrchestrator())->collect([
            BrandMarkComponent::contract(),
            CodeBlockComponent::contract(),
        ]);
    }

    /** The door: one block, composed, with the copy affordance its component owns. */
    private static function door(): string
    {
        return self::compose(new CodeBlockComponent(), new CodeBlockHtmlRenderer(), ['command' => self::DOOR], 'door');
    }

    /**
     * The ways out — a command each, as a chip rather than a block.
     *
     * A `code-block` per row would give four more framed terminals equal weight to the door, and the
     * whole point of the door is that it is not one of four. These are a chip and a sentence.
     */
    private static function waysOut(): string
    {
        $renderer = new CodeBlockHtmlRenderer();
        $component = new CodeBlockComponent();
        $html = '';

        foreach (self::WAYS_OUT as $i => $way) {
            // COMPOSED, NOT A CHIP. Written as a plain `<code>` these four could be read and not
            // taken — «solo se puede copiar 1 comando, los otros se muestran pero no hay UX» — and the
            // copy affordance belongs to the component, not to a button this page would sew on. The
            // compact variant is one row, so the door keeps its weight.
            $html .= '<li><span class="does">' . $way['does'] . '</span>'
                . self::compose($component, $renderer, [
                    'command' => $way['command'],
                    // No prompt: the door carries the `$` because it is the thing being run. A listed
                    // alternative is being named, and a prompt on every row is the eight «TERMINAL»
                    // labels again.
                    'prompt' => '',
                    'compact' => true,
                ], 'way-' . ($i + 1))
                . '</li>';
        }

        return $html;
    }

    /**
     * Where the house stands, in a line — or nothing at all when nobody can say.
     *
     * Three shapes, and the third is the one this exists for:
     *
     * - NOT ASKED (no resolver wired): renders nothing. A house that was never asked must not be
     *   reported as a house with nothing.
     * - COULD NOT SAY: the authority's own sentence, marked as a problem. `Capabilities::state()` has
     *   no manifest guard — only `houseStart()` does — so a page deriving the registry itself would
     *   print «0 capabilities» as a FACT after an interrupted `composer install`. That is the defect
     *   `decisions/0307` paid to remove from the CLI, and it would have arrived here for free.
     * - KNOWN: whether it is founded, and two counts. Never the names, never the paths, never the root
     *   — {@see HouseState} carries the reasoning for each.
     */
    private function stateStrip(): string
    {
        $state = ($this->state)();
        if (!$state->known && $state->cannotSay === null) {
            return '';
        }
        if (!$state->known) {
            // Prose, for the same reason: it is on the page when it loads, not announced into it.
            return '<p class="state-unknown">'
                . htmlspecialchars($state->cannotSay ?? '', \ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // A COUNT AND ITS NOUN, pluralised — «1 capabilities» is the tell of a page that renders data
        // rather than writing a sentence, and this is the first sentence anybody reads.
        $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);

        // 🚨 A LIST, BECAUSE THREE FACTS ARE A LIST — and because spans ran together when read aloud.
        // The first version was one `<p>` of three spans with the separator DRAWN rather than typed, on
        // the reasoning that a `·` between them would be announced as a word. That half was right and
        // it created the other half: `textContent` came out «Founded3 capabilities3 routes», so a
        // screen reader got no separation at all. `<li>` gives the reading separation for free and
        // announces «list, 3 items», which is what this is (greenhouse decisions/0312).
        // 🚨 NO `role="status"`, AND THE A11Y TREE IS WHY. It was there first, copied from the copy
        // button — where it is right, because «copied» IS a status. Here it made the list a LIVE
        // REGION: the tree reported `status atomic live="polite"` and the list semantics were gone, so
        // a screen reader announced a polite interruption instead of «list, 3 items». This strip never
        // changes after load; it is content, not a notification. A role that describes the element's
        // MECHANISM rather than its CONTENT is how a page ends up announcing itself.
        //
        // `role="list"` IS KEPT, AND ITS USUAL JUSTIFICATION DID NOT REPRODUCE HERE — said this way
        // round because the first version of this comment claimed it had. `list-style: none` is known
        // to drop the list role in WebKit, which is why declaring it back is the standard remedy; this
        // machine has no WebKit, so that half is INFERRED and not measured. What WAS measured, both
        // ways in the accessibility tree: Chromium keeps `list` → three `listitem` with the role and
        // without it, byte for byte. The role stays because it costs nothing and answers the engine
        // that cannot be checked from here; the claim is trimmed to what a measurement supports.
        //
        // 🚨 And the thing that fooled me twice is the INSTRUMENT: a non-verbose a11y snapshot PRUNES
        // list wrappers, so it showed three bare texts and I read that as lost semantics. The page's
        // other `<ul>` was pruned identically, which is what gave it away.
        return '<ul class="state" role="list">'
            . '<li class="state__item">' . ($state->founded ? 'Founded' : 'Not founded yet') . '</li>'
            . '<li class="state__item">' . $plural($state->capabilities, 'capability', 'capabilities') . '</li>'
            . '<li class="state__item">' . $plural($state->routes, 'route', 'routes') . '</li>'
            . '</ul>';
    }

    /** The house's own version and where to read it — nothing, when the record cannot say. */
    private function elsewhere(): string
    {
        $html = '';

        if ($this->version !== null) {
            $html .= '<span class="version">milpa/framework '
                . htmlspecialchars($this->version, \ENT_QUOTES, 'UTF-8') . '</span>';
        }

        foreach (self::ELSEWHERE as $name => $href) {
            $html .= '<a href="' . $href . '" rel="noopener noreferrer">' . $name . '</a>';
        }

        return $html;
    }

    /**
     * The design system's tags, from the authority rather than from typing.
     *
     * The three filenames used to be spelled here, a fourth time in this family, and the prefix a
     * fourth WAY — which is how `/design/` came to exist next to `/webauthn/`, `/admin/assets/` and
     * `/live/` without anyone choosing four. {@see HelloPlugin::designPrefix()} owns the prefix and
     * {@see DesignTokens::urls()} owns the filenames; this method owns neither
     * (greenhouse decisions/0308).
     */
    private static function designLinks(): string
    {
        $design = DesignTokens::urls(HelloPlugin::designPrefix());

        return '<link rel="stylesheet" href="' . $design[DesignTokens::TOKENS] . '">'
            . '<link rel="stylesheet" href="' . $design[DesignTokens::FONTS] . '">'
            . DesignTokens::iconLink($design[DesignTokens::APP_ICON]);
    }

    private function html(): string
    {
        $assets = self::assets();

        return \str_replace(
            ['__GREETING__', '__MARK__', '__STATE__', '__DOOR__', '__WAYS_OUT__', '__ELSEWHERE__', '__DESIGN__', '__STYLES__', '__SCRIPTS__'],
            [
                htmlspecialchars($this->greeting, \ENT_QUOTES, 'UTF-8'),
                // READY, not `sown`: the mark reports what the surface is doing, and this page has
                // finished doing it. A mark left growing on a page that is already painted is the
                // loader that never goes away.
                self::compose(new BrandMarkComponent(), new BrandMarkHtmlRenderer(), ['state' => 'ready'], 'mark'),
                $this->stateStrip(),
                self::door(),
                self::waysOut(),
                $this->elsewhere(),
                self::designLinks(),
                $assets->styleTag(),
                $assets->scriptTag(),
            ],
            <<<'HTML'
                <!doctype html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <title>Milpa is running</title>
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    __DESIGN__
                    __STYLES__
                    <style>
                        /* LAYOUT AND WORDS ONLY — no colour of its own, and nothing that a component owns.
                           Milpa's design system ships the tokens inside `milpa/live-web` and this house
                           serves them, so every COLOUR, SPACE, SIZE and LEADING here is a token.

                           🚨 That sentence used to read «every value here is a variable the system
                           defines», which was not true and an audit said so: the measure (`48rem`, now
                           `--container-narrow`) is a token, but the grid tracks and the chip's `em`
                           padding are not, and cannot be — a track is structure, and `em` padding is
                           deliberately relative to the chip's OWN text rather than to a global step. An
                           overstated claim in a comment is worse than no claim: it is the sentence a
                           reader trusts instead of checking. The rules for
                           `pre` and `pre code` that used to live here are gone: `code-block` owns them now,
                           and the page that owned them shipped two unreadable contrasts
                           (greenhouse decisions/0298, composed in 0299).

                           The system is DARK-FIRST and does not read `prefers-color-scheme`: its light half
                           answers only to an explicit `[data-theme="light"]` stamp. So this page is dark
                           wherever it is opened, exactly like the admin panel. */
                        body {
                            /* 🚨 NOT THE `font` SHORTHAND. It carried `/1.6` — a literal the token set does
                               not contain — and a `line-height` in the shorthand is inherited by EVERY
                               heading on the page, so the h1 below was sitting in a 1.6 line box meant
                               for running text. The system ships four leading tokens; this is the one
                               for prose, and the headings now set their own. */
                            font-family: var(--font-body);
                            font-size: var(--text-base);
                            line-height: var(--leading-relaxed);
                            max-width: var(--container-narrow);
                            margin: 0 auto;
                            padding: var(--space-16) var(--space-6) var(--space-32);
                            /* THE GROUND IS PAINTED, ALWAYS. A body with no background borrows whatever the
                               browser puts behind it, and then a fixed text colour is a coin flip. */
                            background: var(--bg);
                            color: var(--text);
                        }
                        .hero {
                            display: grid;
                            grid-template-columns: auto 1fr;
                            align-items: center;
                            gap: var(--space-6);
                            margin-bottom: var(--space-8);
                        }
                        /* The mark sizes itself (`clamp` in its own stylesheet); the hero only says where
                           it goes. A page that re-sized it would be deciding for the brand. */
                        /* 🚨 THE HEADLINE IS SIZED AGAINST THE MARK, NOT AGAINST THE PARAGRAPH.
                           At `--text-xl` it was 20px in a 32px box beside a 144px mark — a measured
                           4.5:1 — so the first screen was a big logo with a caption, and the eye landed
                           on the brand and found nothing of equal weight to read. The gap did not close
                           as the window grew: the mark clamps at 9rem above a 1108px viewport while a
                           fixed heading stays put, so it locked at its worst on exactly the wide screen
                           this page is opened on.

                           A clamp rather than a fixed step, because the mark sizes itself the same way
                           (`clamp(5rem, 13vw, 9rem)` in its own stylesheet) — so the hero's two halves
                           now scale on one principle instead of one clamping while the other snaps. And
                           `balance` because the text is `app.greeting` from config: arbitrary length. */
                        .hero h1 {
                            font-family: var(--font-heading);
                            font-size: clamp(var(--text-2xl), 4vw, var(--text-4xl));
                            line-height: var(--leading-tight);
                            letter-spacing: var(--tracking-display);
                            text-wrap: balance;
                            margin: 0;
                        }
                        .hero p { color: var(--text-muted); margin: var(--space-1) 0 0; }
                        @media (max-width: 30rem) {
                            .hero { grid-template-columns: 1fr; }
                        }
                        /* 🚨 `p code` AND NOT `code`. A bare element selector claims every `code` on the
                           page, including the ones inside a component — measured: this rule painted the
                           command inside `code-block` on a chip with its own padding and radius, which is
                           the exact bug the retired `pre code { background: none }` line used to patch. A
                           component cannot defend itself from its host by scoping, because scoping keeps
                           two COMPONENTS apart. The page narrows its claim to the prose it owns; the
                           component declares its own paint. Both, because either alone is one stranger
                           away from the same chip. */
                        p code {
                            /* 🚨 THE CHIP GETS THE BLOCK'S TREATMENT: the darkest ground AND the edge that
                               makes it readable on any surface. Same thing a command gets, because it is
                               the same kind of thing.

                               The ground alone cannot do it. `--tierra-950` IS `--bg` — the same
                               #17120D — so a flat dark chip sits at 1.000:1 against the prose it lives
                               in: invisible, which is the defect this rule was rewritten to fix once
                               already. The block solved that with a line, and the arithmetic is the
                               reason: `--border-strong` measures 4.429:1 against `--bg` and 3.128:1
                               against `--surface`, so one border works on BOTH surfaces this page paints
                               while no fill in the dark half of the palette works on either.

                               Two earlier answers here were each right about one half. `--surface` made
                               the four chips inside the panel vanish (identical colour).
                               `--surface-raised` made all ten visible but left them lighter than the
                               commands they quote — which is what the reader saw. */
                            background: var(--tierra-950);
                            border: 1px solid var(--border-strong);
                            color: var(--text);
                            /* 🚨 THE CHIP'S BOX HAS TO FIT INSIDE THE LINE IT SITS IN.
                               Measured with the border added: the chip stood 31px tall in a 27.2px line
                               box, so chips on consecutive lines overlapped by 4px and 3px. The overlap
                               was VERTICAL — the horizontal gaps measured 10px and 71px, so the touching
                               was never a spacing problem between neighbours.

                               An inline box's border box is sized by the FONT's metrics, not by
                               line-height, and Space Mono's are tall (~1.5em against Space Grotesk's
                               body line). So the fix is both halves: the face comes down to `0.875em` —
                               the exact `--text-sm` / `--text-base` ratio, derived from the scale rather
                               than picked by eye, and relative so it tracks whatever text surrounds it —
                               and the vertical padding comes down with it.

                               `0.75em` and not something between: that is the `--text-xs` /
                               `--text-base` ratio, one rung down on the scale the system defines.
                               `0.8125em` would read as «a little smaller» too and would be a value
                               picked by eye, which is the thing this page exists as an example
                               against. */
                            font-size: 0.75em;
                            padding: 0.08em 0.4em;
                            border-radius: var(--radius-sm);
                            font-family: var(--font-mono);
                            /* 🚨 A CHIP MAY BREAK MID-WORD, BECAUSE A CLASS NAME HAS NO SPACES IN IT.
                               Measured at a 500px viewport: the chip holding
                               `App\Plugins\HelloPlugin\Controllers\HomeController` was 502px wide and
                               pushed the document to 530px — the page itself scrolled sideways, which is
                               the one thing a layout must never do. `normal` cannot break a namespaced
                               name, so the longest identifier on the page sets the page's width.

                               `anywhere` and not `break-word`: the string has no break opportunity at
                               all, and the backslash is not one. The commands are NOT affected — they
                               live in `code-block`, which scrolls them inside its own body on purpose:
                               a wrapped shell line reads as two commands (measured: 472px of command
                               scrolling inside a 305px block while the page stayed put). */
                            overflow-wrap: anywhere;
                        }
                        /* Raised with the h1, because everything a reader scrolls through lives under
                           this heading: lifting only the h1 would relocate the flatness rather than fix
                           it, leaving an 18px heading to govern the whole body of the page. */
                        h2 {
                            font-family: var(--font-heading);
                            font-size: var(--text-2xl);
                            line-height: var(--leading-snug);
                        }
                        .next {
                            border: 1px solid var(--border);
                            border-radius: var(--radius-lg);
                            padding: var(--space-6);
                            background: var(--surface);
                        }
                        /* 🚨 NO NUMBERED LIST HERE ANY MORE, and its removal is the point of this slice.
                           Eight numbered steps asserted an order this page does not own: the house
                           reports its own next steps and they change as you take them, so a hardcoded
                           sequence is a second, stale answer to a question something else already
                           answers (greenhouse decisions/0301). */

                        /* The plumbing, disclosed. `--text-muted` on the summary because it is an offer,
                           not an instruction, and the caret is the browser's own — a marker this page
                           drew itself would be a second one to keep in sync with the open state. */
                        .plumbing { margin: var(--space-6) 0; }
                        .plumbing > summary {
                            color: var(--text-muted);
                            cursor: pointer;
                            padding-block: var(--space-1);
                        }
                        .plumbing > summary:focus-visible {
                            outline: 2px solid var(--accent);
                            outline-offset: 2px;
                            border-radius: var(--radius-sm);
                        }
                        .plumbing[open] > summary { margin-bottom: var(--space-3); }

                        /* 🚨 `h2 + p` AND NOT `.next > p`. The rule exists to close the gap under the
                           heading, but as a child selector it beat `.or` on specificity — (0,1,1)
                           against (0,1,0) — and zeroed the margin of a paragraph that is not its
                           business. Measured: `.or` computed `margin-top: 0px` and sat flush against
                           the block above it. A type-based selector fighting a named one over spacing
                           is the cascade collision this page is supposed to be an example against. */
                        .next > h2 + p { margin-top: 0; }
                        /* The door is the one thing with a block; everything after it is quieter. */
                        /* A TITLE, not a muted aside. It labels a group of four and sits one rung under
                           «Start here», so it looks like a heading: the heading face, bold, and a step
                           up from body text. Read as `--text-base` at weight 400 it was indistinguishable
                           from the labels it governs. */
                        .or {
                            color: var(--text);
                            font-family: var(--font-heading);
                            font-size: var(--text-lg);
                            font-weight: 700;
                            margin: var(--space-8) 0 var(--space-4);
                        }

                        /* THE WAYS OUT ARE NOT STEPS, so they carry no number and no rule between them:
                           they are alternatives, and numbering alternatives is exactly what the eight-step
                           list did wrong. A sentence and the command that does it. */
                        .ways-out { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--space-3); }
                        /* ONE LINE EACH: the concept, then the command. `wrap` because the longest of
                           them is the `--dry-run`, and a row that cannot wrap is the page scrolling
                           sideways again. */
                        /* 🚨 ONE GRID FOR THE WHOLE LIST, so every command starts at the same x.
                           Each row used to be its own flex line, which meant four labels of four
                           different lengths pushed their commands to four different offsets — read as
                           «los mandos no estan alineados, se ve sucio». A per-row layout cannot align
                           across rows; only a shared track can, and `subgrid` is what shares it. */
                        .ways-out {
                            grid-template-columns: max-content 1fr;
                            column-gap: var(--space-3);
                            align-items: baseline;
                        }
                        .ways-out > li {
                            display: grid;
                            grid-template-columns: subgrid;
                            grid-column: 1 / -1;
                            align-items: baseline;
                            /* 🚨 THE ROW IS THE ONE THAT HAS TO AGREE TO SHRINK. It is a GRID item, and a
                               grid item's `min-width` is `auto` — min-content — which includes the
                               unwrappable command inside it. Measured: the row stood 532px wide inside a
                               402px list and pushed the document to 581px in a 500px viewport, while
                               the command's own `min-width: 0` changed nothing because it was inheriting
                               the row's refusal. Two guesses cost me two measurements; walking the
                               ancestor chain and reading `minWidth` at each level answered it at once. */
                            min-width: 0;
                        }
                        /* 🚨 THE BLOCK HUGS ITS COMMAND — it does not fill the track.
                           A grid item stretches by default, so each compact block came out 544px wide
                           with up to 341px of empty box after its command: a terminal that is not full.
                           `justify-self` is the item's participation in THIS page's grid, not the
                           component's interior, so it is the page's to set — and it is set through the
                           component's public root attribute, never through its internals. Left edges
                           stay aligned, which was the whole ask; the right edges go ragged, which is
                           what content-sized boxes do. */
                        .ways-out [data-milpa-component="code-block"] {
                            justify-self: start;
                            max-width: 100%;
                        }

                        .ways-out .does { color: var(--text-muted); }
                        /* The dash is a separator, so it is drawn rather than written: a literal one in
                           the markup would be read out between every label and command. */
                        .ways-out .does::after { content: ' —'; }

                        /* Where the house can be read. Quiet, at the end, and the only links on the
                           page — so they are the only thing here that carries the accent. */
                        .elsewhere {
                            display: flex;
                            flex-wrap: wrap;
                            gap: var(--space-4);
                            margin-top: var(--space-8);
                            color: var(--text-muted);
                            font-size: var(--text-sm);
                        }
                        .elsewhere a { color: var(--accent); text-decoration: none; }
                        .elsewhere a:hover, .elsewhere a:focus-visible { text-decoration: underline; }
                        .elsewhere a:focus-visible {
                            outline: 2px solid var(--accent);
                            outline-offset: 2px;
                            border-radius: var(--radius-sm);
                        }
                        .version { font-family: var(--font-mono); }
                        /* WHERE IT STANDS. A row of facts, not a card: border and fill say «separate
                           object», and this is a caption on the greeting above it, not a panel of its
                           own. `flex-wrap` because three short facts on a phone are two lines, and a
                           row that refuses to wrap is a row that widens the document. */
                        .state {
                            list-style: none;
                            padding: 0;
                            display: flex;
                            flex-wrap: wrap;
                            gap: var(--space-1) var(--space-4);
                            margin-top: var(--space-3);
                            color: var(--text-muted);
                            font-size: var(--text-sm);
                        }
                        .state__item { white-space: nowrap; }
                        /* Separators drawn, not typed: a `·` between spans would be read aloud by a
                           screen reader as a word, and it would survive a copy-paste of the line. */
                        .state__item + .state__item { position: relative; padding-left: var(--space-4); }
                        .state__item + .state__item::before {
                            content: '';
                            position: absolute;
                            /* CENTRED IN THE GAP, and the offset is why it is written as a calc: the
                               item's padding box starts AFTER the flex gap, so `left: 0` put 16px
                               before the rule and 8px after it — the rule read as a dash attached to
                               the following word instead of a separator between two. Measured, then
                               centred: 12px either side. */
                            left: calc(var(--space-1) * -1);
                            top: 50%;
                            width: var(--space-2);
                            height: 1px;
                            background: var(--border-strong);
                        }
                        /* A HOUSE THAT CANNOT SAY is not a house with nothing, and it must not read
                           like a caption. Same measure as the prose, so a full sentence fits. */
                        .state-unknown {
                            max-width: var(--container-narrow);
                            margin-top: var(--space-3);
                            font-size: var(--text-sm);
                            color: var(--text);
                            border-left: 2px solid var(--accent);
                            padding-left: var(--space-3);
                        }

                        /* NOTHING HERE ABOUT HOW A COMMAND WRAPS ANY MORE, and that is the point of
                           composing them: `code-block` already refuses to wrap and scrolls instead,
                           because a wrapped shell line reads as two commands. This page spent three
                           measurements re-deriving that rule for its own chips — `anywhere` split
                           `--recipe=notes` into «--» / «recipe=notes», `break-word` could not refuse a
                           hyphen's natural break, and the scroller then widened the document — before
                           the commands became components that already knew it (decisions/0302, 0303). */
                    </style>
                </head>
                <body>
                    <header class="hero">
                        __MARK__
                        <div>
                            <h1>__GREETING__</h1>
                            <p>This app is answering through the framework.</p>
                        </div>
                    </header>
                    <!-- WHERE THE HOUSE STANDS, DERIVED — the one thing a static page could never keep
                         true. It sits between the greeting and the door on purpose: it is the CONTEXT
                         that makes «ask the house where it stands» mean something, and it is state,
                         never a step. What to do about it belongs to the operation
                         (greenhouse decisions/0312). -->
                    __STATE__

                    <!-- THE PLUMBING IS DISCLOSED, NOT ANNOUNCED. It used to be the second thing a
                         newcomer read, in two paragraphs of class names, competing with the first
                         experience for the same attention. It is genuinely useful — to a developer
                         inspecting how the request was handled — and `<details>` is exactly the
                         element for something useful that nobody asked for yet: native, keyboard
                         reachable, no script. Architecture can be deep without making the first
                         screen explain all of it. -->
                    <details class="plumbing">
                        <summary>How this request was handled</summary>
                        <p>This response left <code>App\Plugins\HelloPlugin\Controllers\HomeController</code>,
                           dispatched by <code>Milpa\Runtime\Http\RequestHandler</code> over a kernel booted
                           with zero database.</p>
                        <p>The heading above came from <code>config/app.php</code>
                           (<code>app.greeting</code>), read by <code>HelloPlugin::boot()</code> through
                           <code>Milpa\Runtime\Config</code> — edit it and reload.</p>
                    </details>

                    <section class="next" aria-labelledby="start-here">
                        <h2 id="start-here">Start here</h2>
                        <!-- ONE SENTENCE. The second one said «every step it names is a command this app
                             offers today, and following one changes what it answers» — true, and it
                             explained the mechanism. If `house:start` really derives the next step from
                             the house's state then RUNNING IT demonstrates that sentence, so the page
                             was promising what the product proves (greenhouse decisions/0302). -->
                        <p>Ask the house where it stands and what comes next.</p>
                        __DOOR__
                        <!-- A HEADING AND NOT A SENTENCE. «Or look around first:» was tutorial voice,
                             and a colon makes a label sound like an instruction. It is also the label of
                             a group of four, so it is an `h3` the list points at rather than a loose
                             paragraph — the structure a screen reader needs, at no visual cost. -->
                        <h3 class="or" id="or-explore">Or explore</h3>
                        <ul class="ways-out" aria-labelledby="or-explore">__WAYS_OUT__</ul>
                    </section>
                    <footer class="elsewhere">__ELSEWHERE__</footer>
                    __SCRIPTS__
                </body>
                </html>
                HTML,
        );
    }
}
