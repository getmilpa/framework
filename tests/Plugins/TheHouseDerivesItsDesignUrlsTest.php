<?php

/**
 * This file is part of milpa/framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace App\Tests\Plugins;

use App\Plugins\HelloPlugin\HelloPlugin;
use Milpa\Container\DIContainer;
use Milpa\Live\Support\DesignTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 THIS HOUSE DERIVES ITS DESIGN-SYSTEM URLS; IT TYPES THE PREFIX ONCE AND NOTHING ELSE.
 *
 * `decisions/0243` stopped the tokens being COPIED — three packages carried identical copies that had
 * drifted, all three missing `--space-32`, and now the file ships in `milpa/live-web`. What it did not
 * stop was the TYPING, and this house is the proof of the cost: `/design/` was invented here in the
 * same afternoon that `/webauthn/`, `/admin/assets/` and `/live/` already existed, by someone who
 * could not see the other three from where they were writing (greenhouse decisions/0308).
 *
 * A prefix per host is not the defect — it is a decision written where it is made
 * ({@see DesignTokens::iconLink()}: «each serves this file from its own asset route, with its own
 * cache policy»), because a plugin whose pages work the moment it is installed cannot depend on
 * another plugin's routes being mounted. Four prefixes nobody chose is the defect.
 */
#[CoversClass(HelloPlugin::class)]
final class TheHouseDerivesItsDesignUrlsTest extends TestCase
{
    /**
     * 🚨 NO STRING IN `src/` SPELLS A DESIGN-SYSTEM FILENAME OR REPEATS THE PREFIX.
     *
     * A guard, not an example. The three `<link>`s that used to live in the page's `<head>` were the
     * fourth copy of those filenames in this family, and the page could not have known: nothing it
     * imported mentioned the other three. What a test can see, a reader cannot.
     */
    public function testNoStringInTheSourceSpellsADesignUrl(): void
    {
        $offenders = [];

        foreach (self::phpFiles(\dirname(__DIR__, 2) . '/src') as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                $trimmed = ltrim($line);
                // Comments and docblocks name these URLs to explain them; that is prose, not a link.
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                // The one exemption: something has to say where this house mounts them, and it says it once.
                if (str_contains($line, 'DESIGN_PREFIX = ')) {
                    continue;
                }
                $spellsAFile = preg_match('#[\'"][^\'"]*milpa-(tokens|fonts|wordmark|wordmark-light|app-icon)\.(css|svg)#', $line) === 1;
                $repeatsThePrefix = preg_match("#['\"]/design#", $line) === 1;
                if ($spellsAFile || $repeatsThePrefix) {
                    $offenders[] = basename($file) . ':' . ($n + 1) . '  ' . trim($line);
                }
            }
        }

        self::assertSame([], $offenders, "derive these from DesignTokens::urls(HelloPlugin::designPrefix()) instead:\n" . implode("\n", $offenders));
    }

    /**
     * The routes this house declares for the system sit under the prefix it declares.
     *
     * The route PATTERNS (`{file}`, `{face}`) are this house's own shape and no authority can produce
     * them for it — one catch-all here, five named routes in the passkey door, and both are right for
     * their host. What moved is the prefix and the filenames; the shape stayed local.
     */
    public function testTheDesignRoutesSitUnderTheDeclaredPrefix(): void
    {
        $paths = [];
        foreach ((new HelloPlugin(new DIContainer()))->routes() as $route) {
            if (str_contains($route->path, 'design') || str_contains($route->path, 'fonts')) {
                $paths[] = $route->path;
            }
        }

        self::assertSame([
            HelloPlugin::designPrefix() . '/{file}',
            HelloPlugin::designPrefix() . '/fonts/{face}',
        ], $paths);

        // And the URLs the page links resolve INTO those patterns — the stylesheet's own relative
        // `url('fonts/…')` is why the second one exists at all.
        $urls = DesignTokens::urls(HelloPlugin::designPrefix());
        self::assertSame(HelloPlugin::designPrefix() . '/' . DesignTokens::TOKENS, $urls[DesignTokens::TOKENS]);

        $faces = array_diff_key($urls, array_flip([
            DesignTokens::TOKENS, DesignTokens::FONTS,
            DesignTokens::WORDMARK, DesignTokens::WORDMARK_LIGHT, DesignTokens::APP_ICON,
        ]));
        self::assertNotSame([], $faces, 'a stylesheet whose faces are not served renders as missing type, not as an error');
        foreach ($faces as $url) {
            self::assertStringStartsWith(HelloPlugin::designPrefix() . '/fonts/', $url);
        }
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
