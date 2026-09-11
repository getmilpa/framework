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
use Milpa\Live\Rendering\BrandMarkHtmlRenderer;
use Milpa\Live\Rendering\CodeBlockHtmlRenderer;
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
        ['does' => 'Enter the house', 'command' => 'php bin/coa shell'],
    ];

    public function __construct(private readonly string $greeting)
    {
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
        $html = '';

        foreach (self::WAYS_OUT as $way) {
            $html .= '<li><span class="does">' . $way['does'] . '</span>'
                . '<code>' . htmlspecialchars($way['command'], \ENT_QUOTES, 'UTF-8') . '</code></li>';
        }

        return $html;
    }

    private function html(): string
    {
        $assets = self::assets();

        return \str_replace(
            ['__GREETING__', '__MARK__', '__DOOR__', '__WAYS_OUT__', '__STYLES__', '__SCRIPTS__'],
            [
                htmlspecialchars($this->greeting, \ENT_QUOTES, 'UTF-8'),
                // READY, not `sown`: the mark reports what the surface is doing, and this page has
                // finished doing it. A mark left growing on a page that is already painted is the
                // loader that never goes away.
                self::compose(new BrandMarkComponent(), new BrandMarkHtmlRenderer(), ['state' => 'ready'], 'mark'),
                self::door(),
                self::waysOut(),
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
                    <link rel="stylesheet" href="/design/milpa-tokens.css">
                    <link rel="stylesheet" href="/design/milpa-fonts.css">
                    <link rel="icon" type="image/svg+xml" href="/design/milpa-app-icon.svg">
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
                        p code, .ways-out code {
                            /* 🚨 `--surface-raised`, BECAUSE `--surface` IS WHAT THE PANEL IS PAINTED.
                               Measured: of the ten chips on this page, the four inside the panel sat at
                               1.000:1 against their own ground — the identical colour, so no chip at all
                               — while the six outside reached 1.416:1. The same markup rendered as a chip
                               in one half of the page and as plain text in the other.

                               An earlier note here argued for `--surface` on the grounds that «an inline
                               tint is not a raised block». That reasoning was about the token's NAME; a
                               chip needs a ground that differs from every surface it can land on, and on
                               this page `--surface` is one of them. */
                            background: var(--surface-raised);
                            color: var(--text);
                            padding: 0.15em 0.4em;
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
                        .or {
                            color: var(--text-muted);
                            font-family: var(--font-body);
                            font-size: var(--text-base);
                            font-weight: 400;
                            margin: var(--space-6) 0 var(--space-3);
                        }

                        /* THE WAYS OUT ARE NOT STEPS, so they carry no number and no rule between them:
                           they are alternatives, and numbering alternatives is exactly what the eight-step
                           list did wrong. A sentence and the command that does it. */
                        .ways-out { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--space-3); }
                        /* ONE LINE EACH: the concept, then the command. `wrap` because the longest of
                           them is the `--dry-run`, and a row that cannot wrap is the page scrolling
                           sideways again. */
                        .ways-out > li {
                            display: flex;
                            flex-wrap: wrap;
                            align-items: baseline;
                            gap: var(--space-2);
                            /* 🚨 THE ROW IS THE ONE THAT HAS TO AGREE TO SHRINK. It is a GRID item, and a
                               grid item's `min-width` is `auto` — min-content — which includes the
                               unwrappable chip inside it. Measured: the row stood 532px wide inside a
                               402px list and pushed the document to 581px in a 500px viewport, while
                               the chip's own `min-width: 0` changed nothing because it was inheriting
                               the row's refusal. Two guesses cost me two measurements; walking the
                               ancestor chain and reading `minWidth` at each level answered it at once. */
                            min-width: 0;
                        }
                        .ways-out .does { color: var(--text-muted); }
                        /* The dash is a separator, so it is drawn rather than written: a literal one in
                           the markup would be read out between every label and command. */
                        .ways-out .does::after { content: ' —'; }

                        /* 🚨 A COMMAND DOES NOT WRAP — IT SCROLLS. Same rule `code-block` applies to its
                           own body, and for the same reason: a wrapped shell line reads as two
                           commands.

                           It took two measurements to get here. `anywhere` split
                           `recipe:apply --recipe=notes` as «--» / «recipe=notes» — a break that invents
                           an argument. `break-word` fixed two of the three chips and left that one,
                           because a HYPHEN is a natural break opportunity in CSS line breaking, not an
                           overflow break: no `overflow-wrap` value can refuse it. So the chip stops
                           wrapping at all and carries its own scroller, which is what the block beside
                           it already does. The prose chips keep `anywhere`: a namespaced class name has
                           no break opportunity, and letting IT set the page's width was the original
                           defect. */
                        .ways-out code {
                            white-space: pre;
                            overflow-x: auto;
                            /* 🚨 `min-width: 0` IS THE LOAD-BEARING LINE. A flex item's `min-width` is
                               `auto`, which resolves to min-content — and min-content for
                               `white-space: pre` is the WHOLE command, so the chip refused to shrink
                               and pushed the document to 581px in a 500px viewport. Measured
                               immediately after adding the scroller: I had traded a misleading break
                               for the sideways scroll this page already fixed once. */
                            min-width: 0;
                            max-width: 100%;
                        }
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
                    __SCRIPTS__
                </body>
                </html>
                HTML,
        );
    }
}
