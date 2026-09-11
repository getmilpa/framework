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
     * The first five minutes, in order: what to say, and the command that says it.
     *
     * An ordered list because the steps ARE a sequence — each one is taken after the one above it,
     * and `house:start` reports different next steps once you have. Numbering content that is not a
     * sequence is decoration; numbering this is information.
     *
     * @var list<array{prose: string, command: string}>
     */
    private const array STEPS = [
        [
            'prose' => 'Ask the app where it stands and what to do next — every step it names is a command it '
                . 'offers today, and the steps change as you take them:',
            'command' => 'php bin/coa house:start',
        ],
        [
            'prose' => 'Do not guess what booted. Ask the app:',
            'command' => "php bin/coa list\nphp bin/coa plugins:list",
        ],
        [
            'prose' => 'What this app can do today — and what it could do next, each one already carrying the '
                . 'command that grows it:',
            'command' => 'php bin/coa capabilities',
        ],
        [
            'prose' => 'Take a package name it lists under <code>available</code> and grow into it. Ask first '
                . 'what it would run:',
            'command' => 'php bin/coa capabilities:enable milpa/devtools --dry-run',
        ],
        [
            'prose' => 'Or let a recipe do the first hour for you — found the house on a domain, switch on what '
                . 'it needs and scaffold the first plugin, each step through the same gate. A recipe runs '
                . 'through the governed runtime — the sessions that record its pauses — so switch that on '
                . 'first (<code>house:start</code> says so while it is missing). No model gateway is needed '
                . 'for a door you open yourself:',
            'command' => 'php bin/coa capabilities:enable milpa/agent',
        ],
        [
            'prose' => 'Then apply it (edit <code>recipes/notes.json</code> first, or copy it under another '
                . 'name). It pauses for your consent before each step that changes something — answer with '
                . '<code>agent:answer</code> and call it again:',
            'command' => 'php bin/coa recipe:apply --recipe=notes',
        ],
        [
            'prose' => 'Then every operation on one screen, including whatever a capability just added:',
            'command' => 'php bin/coa shell',
        ],
        [
            'prose' => 'And see it in a browser — the URL it prints answers while it runs:',
            'command' => 'php bin/coa serve',
        ],
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

    /**
     * The steps, each its prose and its command as a composed block.
     */
    private static function steps(): string
    {
        $renderer = new CodeBlockHtmlRenderer();
        $component = new CodeBlockComponent();
        $html = '';

        foreach (self::STEPS as $i => $step) {
            $html .= '<li><p>' . $step['prose'] . '</p>'
                . self::compose($component, $renderer, ['command' => $step['command']], 'step-' . ($i + 1))
                . '</li>';
        }

        return $html;
    }

    private function html(): string
    {
        $assets = self::assets();

        return \str_replace(
            ['__GREETING__', '__MARK__', '__STEPS__', '__STYLES__', '__SCRIPTS__'],
            [
                htmlspecialchars($this->greeting, \ENT_QUOTES, 'UTF-8'),
                // READY, not `sown`: the mark reports what the surface is doing, and this page has
                // finished doing it. A mark left growing on a page that is already painted is the
                // loader that never goes away.
                self::compose(new BrandMarkComponent(), new BrandMarkHtmlRenderer(), ['state' => 'ready'], 'mark'),
                self::steps(),
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
                        p code {
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
                        /* The step number is drawn by the list, not written into the words: the order is
                           the data, so the marker reads it rather than repeating it. */
                        .steps { list-style: none; counter-reset: step; margin: 0; padding: 0; }
                        .steps > li {
                            counter-increment: step;
                            display: grid;
                            grid-template-columns: 2rem 1fr;
                            gap: 0 var(--space-4);
                            padding-block: var(--space-6);
                            border-top: 1px solid var(--border-subtle);
                        }
                        .steps > li:first-child { border-top: 0; padding-top: 0; }
                        .steps > li::before {
                            content: counter(step);
                            grid-row: span 2;
                            font-family: var(--font-mono);
                            font-variant-numeric: tabular-nums;
                            color: var(--accent);
                            text-align: right;
                        }
                        .steps > li > p { margin: 0 0 var(--space-3); }
                    </style>
                </head>
                <body>
                    <header class="hero">
                        __MARK__
                        <div>
                            <h1>__GREETING__</h1>
                            <p>The Milpa framework is answering this request.</p>
                        </div>
                    </header>
                    <p>This response left <code>App\Plugins\HelloPlugin\Controllers\HomeController</code>,
                       dispatched by <code>Milpa\Runtime\Http\RequestHandler</code> over a kernel booted
                       with zero database.</p>
                    <p>The heading above came from <code>config/app.php</code>
                       (<code>app.greeting</code>), read by <code>HelloPlugin::boot()</code> through
                       <code>Milpa\Runtime\Config</code> — edit it and reload.</p>
                    <section class="next" aria-labelledby="next-steps">
                        <h2 id="next-steps">Your first five minutes</h2>
                        <ol class="steps">__STEPS__</ol>
                    </section>
                    __SCRIPTS__
                </body>
                </html>
                HTML,
        );
    }
}
