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

namespace App\Tests\Plugins;

use App\Plugins\HelloPlugin\Controllers\DesignController;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Live\Support\DesignTokens;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE DESIGN SYSTEM IS REACHABLE FROM A FRESH HOUSE — which is why the welcome page can stop inventing.
 *
 * `milpa/live-web` shipped the tokens, the fonts and the marks all along, and nothing served them: a
 * house had them in `vendor/` with no route. So the welcome page hand-rolled six hex values and two of
 * them were unreadable at 1.07:1 and 1.02:1, measured in a browser (greenhouse decisions/0298).
 */
#[CoversClass(DesignController::class)]
final class TheDesignSystemIsServedTest extends TestCase
{
    /** A request as the router leaves it: the parameter rides in the RouteResult. */
    private static function asking(string $placeholder, string $value): ServerRequest
    {
        return (new ServerRequest('GET', '/design/' . $value))->withAttribute(
            RouteResult::ATTRIBUTE,
            RouteResult::matched(
                new Route(path: '/design/{' . $placeholder . '}', methods: HttpMethod::GET, name: 'design.file', handler: null),
                [$placeholder => $value],
            ),
        );
    }

    /**
     * 🚨 THE PARAMETER COMES FROM THE RouteResult, NOT FROM A REQUEST ATTRIBUTE.
     *
     * The first version read `$request->getAttribute('file')`, which is always null: every design file
     * answered 404 while `coa routes:list` showed both routes registered, so the failure read as a
     * routing problem. `milpa/admin`'s own asset controller already did it the right way.
     */
    public function testTheTokensAreServedAsCssWithTheParameterReadFromTheRouteResult(): void
    {
        $response = (new DesignController())->file(self::asking('file', DesignTokens::TOKENS));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('--bg', (string) $response->getBody(), 'the ground a page paints with');
        self::assertStringContainsString('--text', (string) $response->getBody());
    }

    /**
     * 🚨 THE SYSTEM IS DARK-FIRST AND DOES NOT READ THE BROWSER'S PREFERENCE, and that is deliberate.
     *
     * Its own header says «Dark-first», and the light half is reachable only through an explicit
     * `[data-theme="light"]` stamp — there is no `prefers-color-scheme` in the file. So this page
     * renders dark in a light-theme browser, exactly like the admin panel does, and a light-theme
     * measurement of it is only obtainable by stamping the attribute by hand.
     *
     * Asserted BOTH ways round, because the interesting half is the absence: I reported a light-theme
     * contrast column to Rod before checking which mechanism produced it, and the answer was «the one I
     * applied myself» (greenhouse decisions/0298).
     */
    public function testTheSystemIsDarkFirstAndTheLightHalfNeedsAStamp(): void
    {
        $css = (string) (new DesignController())->file(self::asking('file', DesignTokens::TOKENS))->getBody();

        self::assertStringContainsString('data-theme', $css, 'the light half is reachable by stamp');
        self::assertStringNotContainsString('prefers-color-scheme', $css, 'and NOT by the browser\'s preference — a page on these tokens is dark wherever it is opened');
    }

    /**
     * A font face answers on its own route, because the stylesheet asks for it relatively.
     *
     * `milpa-fonts.css` carries `url('fonts/x.woff2')`, so a browser that loaded it at
     * `/design/milpa-fonts.css` asks for `/design/fonts/x.woff2` — and `DesignTokens::path()` takes the
     * bare face name. A font that 404s degrades in silence to a fallback nobody chose, so the two
     * routes are separate and this asserts the second one.
     */
    public function testAFontFaceTheStylesheetAsksForIsServed(): void
    {
        $css = (string) (new DesignController())->file(self::asking('file', DesignTokens::FONTS))->getBody();
        preg_match("#url\\('fonts/([^']+)'\\)#", $css, $found);

        self::assertNotEmpty($found, 'the fonts stylesheet names its faces relatively, or this route is unnecessary');

        $response = (new DesignController())->face(self::asking('face', $found[1]));

        self::assertSame(200, $response->getStatusCode(), 'the face the stylesheet asks for: ' . $found[1]);
        self::assertSame('font/woff2', $response->getHeaderLine('Content-Type'));
    }

    /** The icon the page declares, so nothing guesses at the site root. */
    public function testTheAppIconIsServed(): void
    {
        $response = (new DesignController())->file(self::asking('file', DesignTokens::APP_ICON));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
    }

    /**
     * 🚨 A NAME THE PACKAGE DOES NOT SHIP IS A 404, and no path is ever read off the URL.
     *
     * `DesignTokens::path()` answers only for the names it knows, so traversal is not a thing this
     * route can be talked into — the refusal is structural rather than a filter that has to be right.
     */
    public function testAnythingElseIsRefusedIncludingATraversal(): void
    {
        foreach (['../../../composer.json', '..%2Fcomposer.json', 'anything.css', ''] as $name) {
            $response = (new DesignController())->file(self::asking('file', $name));

            self::assertSame(404, $response->getStatusCode(), 'refused: ' . $name);
        }
    }

    /** Long-lived caching, because a released package's asset does not change under its version. */
    public function testAServedFileIsCacheableForALongTime(): void
    {
        $response = (new DesignController())->file(self::asking('file', DesignTokens::TOKENS));

        self::assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
    }
}
