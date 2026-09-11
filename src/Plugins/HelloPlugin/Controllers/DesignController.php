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

use Milpa\Http\Routing\RouteResult;
use Milpa\Live\Support\DesignTokens;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * SERVES MILPA'S DESIGN SYSTEM FROM THIS HOUSE — so a page never has to invent a colour.
 *
 * 🚨 THE SYSTEM WAS ALREADY INSTALLED AND NOTHING SERVED IT. `milpa/live-web` ships the tokens, the
 * fonts and the marks under `resources/design/`, and `DesignTokens` already answers `path()` and
 * `contentType()` for exactly this. A fresh house had them in `vendor/` and no route to reach them —
 * so the welcome page hand-rolled six hex values, and two of them were unreadable: a `<code>` chip at
 * 1.07:1 inside a dark `<pre>`, and a `body` with a text colour and no background, which in a
 * dark-scheme browser put near-black text on the browser's own dark ground at 1.02:1.
 *
 * Rod's question when he saw it — «¿para eso la familia tiene un Design System, no? ¿por qué
 * reinventar la rueda?» — is the whole reason this file exists. The wheel was there; nobody could
 * reach it (greenhouse decisions/0298).
 *
 * ── WHY IT LIVES IN THE STARTER PLUGIN ──────────────────────────────────────────────────────────────
 *
 * Because this is the file a house copies. A route that serves the design system is the first thing an
 * app that paints anything will need, and here it sits next to the page that uses it, deletable with
 * it. A house that keeps the design system and drops the starter should move this route where its own
 * pages live — that is a move, not a rewrite.
 */
final class DesignController
{
    /**
     * One design file, by the name `DesignTokens` knows it under.
     *
     * A name the package does not recognise answers 404 rather than reading a path off the URL: the
     * file is resolved by `DesignTokens::path()`, which only answers for the names it ships, so nothing
     * here can be talked into serving an arbitrary file.
     */
    public function file(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serve(self::parameter($request, 'file'));
    }

    /**
     * One font face.
     *
     * A second route because `milpa-fonts.css` points at its files RELATIVELY — `url('fonts/x.woff2')` —
     * so a browser that loaded the stylesheet at `/design/milpa-fonts.css` asks for
     * `/design/fonts/x.woff2`. And `DesignTokens::path()` takes the face's bare name, not that prefix.
     * Two explicit routes rather than one placeholder assumed to span a slash: the assumption was never
     * measured, and a font that 404s degrades in silence to a fallback nobody chose.
     */
    public function face(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serve(self::parameter($request, 'face'));
    }

    /**
     * A route parameter, which arrives inside the `RouteResult` and NOT as a request attribute.
     *
     * Read from the wrong place this answers an empty string, `DesignTokens::path()` resolves nothing,
     * and every design file 404s while the routes are plainly registered — measured exactly that way
     * before this method existed (greenhouse decisions/0298).
     */
    private static function parameter(ServerRequestInterface $request, string $name): string
    {
        $result = $request->getAttribute(RouteResult::ATTRIBUTE);

        return $result instanceof RouteResult ? (string) ($result->parameters[$name] ?? '') : '';
    }

    /** Resolves a design file by the name the package knows it under, or 404. */
    private function serve(string $name): ResponseInterface
    {
        $psr17 = new Psr17Factory();

        $path = DesignTokens::path($name);
        if ($path === null) {
            return $psr17->createResponse(404)->withHeader('Content-Type', 'text/plain');
        }

        $response = $psr17->createResponse(200)
            ->withHeader('Content-Type', DesignTokens::contentType($name))
            // A released package's asset never changes under the same version, and the fonts are the
            // heavy part of this: a year is what «immutable» means in a cache header.
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
        $response->getBody()->write((string) file_get_contents($path));

        return $response;
    }
}
