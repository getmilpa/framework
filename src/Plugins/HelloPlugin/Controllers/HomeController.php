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

namespace App\Plugins\HelloPlugin\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * This app's only controller: proves `Milpa\Runtime\Http\RequestHandler` dispatched a real
 * request end to end. Returns a plain PSR-7 {@see Response} built with `nyholm/psr7` — the
 * app's declared PSR-7 implementation (`milpa/http` ships only the routing contracts, no
 * concrete message/factory classes — every consumer picks one, see the README).
 *
 * The `$greeting` it renders is NOT read here — it is handed in by {@see \App\Plugins\HelloPlugin\HelloPlugin::boot()},
 * which pulls it out of the `config/app.php` bag via `Milpa\Runtime\Config` and constructs this
 * controller with it. The controller stays deliberately dumb: it renders what it is given.
 */
final class HomeController
{
    public function __construct(private readonly string $greeting)
    {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $this->html());
    }

    private function html(): string
    {
        $greeting = htmlspecialchars($this->greeting, \ENT_QUOTES, 'UTF-8');

        return \str_replace('__GREETING__', $greeting, <<<'HTML'
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>Milpa is running</title>
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <link rel="stylesheet" href="/design/milpa-tokens.css">
                <link rel="stylesheet" href="/design/milpa-fonts.css">
                <link rel="icon" type="image/svg+xml" href="/design/milpa-app-icon.svg">
                <style>
                    /* NOT ONE COLOUR OF ITS OWN. Milpa's design system ships the tokens inside
                       `milpa/live-web` and this house now serves them, so every value here is a
                       variable the system defines — in both themes, which is what fixed the two
                       measured bugs (greenhouse decisions/0298).

                       Before: a `code` chip painted #f2f2f2 while inheriting the dark `pre`'s light
                       text — 1.07:1, invisible. And `body` set a text colour with NO background, so a
                       dark-scheme browser put near-black text on its own dark ground at 1.02:1. Both
                       were hand-picked hexes in a light-only design that never said it was light. */
                    body {
                        font: var(--text-base)/1.5 var(--font-body);
                        max-width: 46rem;
                        margin: var(--space-16) auto;
                        padding: 0 var(--space-6);
                        /* THE GROUND IS PAINTED, ALWAYS. A body with no background borrows whatever the
                           browser puts behind it, and then a fixed text colour is a coin flip. */
                        background: var(--bg);
                        color: var(--text);
                    }
                    code {
                        /* `--surface` and not `--surface-raised`: an inline tint is not a raised block,
                           and painting both the same makes every class name in a paragraph read as a
                           stripe. The distinction is the system's, not a value picked by eye. */
                        background: var(--surface);
                        color: var(--text);
                        padding: 0.15em 0.4em;
                        border-radius: var(--radius-sm);
                        font-family: var(--font-mono);
                    }
                    /* A `code` INSIDE a `pre` is already in its own block: no chip, no second ground to
                       fight the one it sits on. This single rule is the whole invisible-command bug. */
                    pre code { background: none; padding: 0; color: inherit; }
                    pre {
                        background: var(--surface-raised);
                        color: var(--text);
                        padding: var(--space-4);
                        border-radius: var(--radius-md);
                        border: 1px solid var(--border-subtle);
                        overflow-x: auto;
                        font-family: var(--font-mono);
                    }
                    h1 { font-size: var(--text-xl); font-family: var(--font-heading); }
                    .next {
                        border: 1px solid var(--border);
                        border-radius: var(--radius-lg);
                        padding: var(--space-4);
                        background: var(--surface);
                    }
                </style>
            </head>
            <body>
                <h1>__GREETING__</h1>
                <p>This response left <code>App\Plugins\HelloPlugin\Controllers\HomeController</code>,
                   dispatched by <code>Milpa\Runtime\Http\RequestHandler</code> over a kernel booted
                   with zero database.</p>
                <p>The heading above came from <code>config/app.php</code>
                   (<code>app.greeting</code>), read by <code>HelloPlugin::boot()</code> through
                   <code>Milpa\Runtime\Config</code> — edit it and reload.</p>
                <section class="next" aria-labelledby="next-steps">
                    <h2 id="next-steps">Your first five minutes</h2>
                    <p>Ask the app where it stands and what to do next — every step it names is a
                       command it offers today, and the steps change as you take them:</p>
                    <pre><code>php bin/coa house:start</code></pre>
                    <p>Do not guess what booted. Ask the app:</p>
                    <pre><code>php bin/coa list&#10;php bin/coa plugins:list</code></pre>
                    <p>What this app can do today — and what it could do next, each one already
                       carrying the command that grows it:</p>
                    <pre><code>php bin/coa capabilities</code></pre>
                    <p>Take a package name it lists under <code>available</code> and grow into it.
                       Ask first what it would run:</p>
                    <pre><code>php bin/coa capabilities:enable milpa/devtools --dry-run</code></pre>
                    <p>Or let a recipe do the first hour for you — found the house on a domain, switch
                       on what it needs and scaffold the first plugin, each step through the same gate.
                       A recipe runs through the governed runtime — the sessions that record its pauses —
                       so switch that on first (<code>house:start</code> says so while it is missing). No
                       model gateway is needed for a door you open yourself:</p>
                    <pre><code>php bin/coa capabilities:enable milpa/agent</code></pre>
                    <p>Then apply it (edit <code>recipes/notes.json</code> first, or copy it under another
                       name). It pauses for your consent before each step that changes something — answer
                       with <code>agent:answer</code> and call it again:</p>
                    <pre><code>php bin/coa recipe:apply --recipe=notes</code></pre>
                    <p>Then every operation on one screen, including whatever a capability just
                       added:</p>
                    <pre><code>php bin/coa shell</code></pre>
                    <p>And see it in a browser — the URL it prints answers while it runs:</p>
                    <pre><code>php bin/coa serve</code></pre>
                </section>
            </body>
            </html>
            HTML);
    }
}
