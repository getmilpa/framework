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

use App\Plugins\HelloPlugin\Controllers\HomeController;
use App\Plugins\HelloPlugin\HouseState;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE WELCOME PAGE REPORTS WHERE THE HOUSE STANDS, AND ONLY WHAT A STRANGER MAY KNOW.
 *
 * It was a static tutorial: true the day it was written, stale from then on. `house:start` already
 * derives the next step from state, so the page reports the STATE and hands over the door — state is
 * the surface's, the criterion is the operation's (greenhouse decisions/0312, and 0302 which cut this
 * page's second sentence for the same reason).
 */
#[CoversClass(HouseState::class)]
#[CoversClass(HomeController::class)]
final class ThePageSaysWhereTheHouseStandsTest extends TestCase
{
    /** One `house:start` answer of a real, equipped, founded house — the shape the operation returns. */
    private static function answer(): array
    {
        return [
            'ok' => true,
            'app' => [
                'name' => 'casa',
                'root' => '/home/rod/secret/path/casa',
                'foundation' => 'founded',
                'foundation_says' => 'Call `foundation:found` with the domain the HUMAN named.',
            ],
            'capabilities' => [
                'installed' => [
                    ['capability' => 'identity', 'package' => 'milpa/auth'],
                    ['capability' => 'admin', 'package' => 'milpa/admin'],
                ],
                'available' => [['package' => 'milpa/devtools', 'title' => 'The generators', 'command' => 'x']],
            ],
            'routes' => ['count' => 11, 'paths' => ['/', '/milpa/admin', '/webauthn/enroll']],
            'next' => [['step' => 'see it', 'command' => 'php bin/coa serve', 'why' => 'the dev server']],
        ];
    }

    private function page(callable $state): string
    {
        return (string) (new HomeController('Milpa is running.', '0.51.1', \Closure::fromCallable($state)))
            ->index(new ServerRequest('GET', '/'))
            ->getBody();
    }

    /** The state is on the page, in words, derived from the answer. */
    public function testItSaysWhereTheHouseStands(): void
    {
        $html = $this->page(static fn (): HouseState => HouseState::fromAnswer(self::answer()));

        self::assertStringContainsString('Founded', $html);
        self::assertStringContainsString('2 capabilities', $html);
        self::assertStringContainsString('11 routes', $html);
    }

    /**
     * 🚨 THE LEAK GUARD, AND IT IS THE POINT OF THE SLICE.
     *
     * `house:start` declares `surfaces: ['cli','tui','mcp']` and NOT http because its answer carries
     * the app's filesystem root. This page is not an http client of the operation — it is a controller
     * in the same process calling the same handler — so the boundary the surface list held has to be
     * held by the projection instead. Three different kinds of leak, asserted by absence:
     *
     * - the ROOT is a filesystem path
     * - the PATHS enumerate the surface: a map of every door, handed to whoever asked for the front page
     * - the capability NAMES say which locks to go and read about
     */
    public function testNothingAStrangerMayNotKnowReachesTheHtml(): void
    {
        $html = $this->page(static fn (): HouseState => HouseState::fromAnswer(self::answer()));

        self::assertStringNotContainsString('/home/rod/secret/path/casa', $html, 'the app root is a filesystem path');
        self::assertStringNotContainsString('/webauthn/enroll', $html, 'the paths enumerate the surface');
        self::assertStringNotContainsString('milpa/auth', $html, 'the names say which doors exist');
        self::assertStringNotContainsString('milpa/admin', $html);
        self::assertStringNotContainsString('milpa/devtools', $html, 'and neither does what is merely available');
    }

    /**
     * 🚨 AND NEITHER DOES THE STEP, though it was in the projection first and read well.
     *
     * `foundation_says` is `teach.how` — «Call `foundation:found` with the domain the HUMAN named» —
     * and that is a step. `decisions/0302` cut this page's second sentence for exactly this reason: a
     * page that copies the operation's steps is a page maintaining a second copy of an answer that
     * moves. What the page owes the reader is the door.
     */
    public function testItHandsOverTheDoorRatherThanCopyingItsSteps(): void
    {
        $html = $this->page(static fn (): HouseState => HouseState::fromAnswer(self::answer()));

        self::assertStringNotContainsString('foundation:found', $html, 'a sentence naming a command is a step');
        self::assertStringNotContainsString('php bin/coa serve', $html, 'and so is `next`');
        self::assertStringContainsString('house:start', $html, 'the door is what the page hands over');
    }

    /**
     * 🚨 «I COULD NOT READ IT» IS A STATE, NOT A ZERO — and this is why the page calls the handler.
     *
     * `Capabilities::state()` has no manifest guard; only `houseStart()` has one. A page deriving the
     * registry itself would print «0 capabilities» as a FACT after an interrupted `composer install`,
     * a Docker COPY that skips the manifest, a partial rsync — which is exactly the defect
     * `decisions/0307` paid to remove from the CLI, arriving here for free.
     */
    public function testWhenItCannotSayItSaysSoInsteadOfPrintingZeros(): void
    {
        $refusal = ['ok' => false, 'error' => 'vendor/composer/installed.json is missing or unreadable — run `composer install`'];
        $html = $this->page(static fn (): HouseState => HouseState::fromAnswer($refusal));

        self::assertStringContainsString('installed.json is missing or unreadable', $html);
        self::assertStringNotContainsString('0 capabilities', $html, 'a zero would state the opposite of what is true');
        self::assertStringNotContainsString('Not founded yet', $html, 'and so would a verdict it could not reach');
    }

    /**
     * A house nobody asked renders no strip at all — absent, not blank.
     *
     * The rule this page already learned from nine labelled blanks: a surface with no authority behind
     * a claim says nothing rather than saying it emptily.
     */
    public function testAHouseNobodyAskedIsNotAHouseWithNothing(): void
    {
        $html = $this->page(static fn (): HouseState => HouseState::unasked());

        // The STRIP's absence, not the word's: `capabilities` is one of the four ways out this page
        // offers (`php bin/coa capabilities`), so asserting the word would fail on copy the slice
        // never touched — a guard that reaches past its subject gets deleted by the next person.
        self::assertStringNotContainsString('class="state"', $html);
        self::assertStringNotContainsString('Not founded yet', $html);
        self::assertDoesNotMatchRegularExpression('/\\d+ capabilit/', $html, 'and no count either');
        // And the page still works: the greeting, the door and the footer are untouched.
        self::assertStringContainsString('Milpa is running.', $html);
        self::assertStringContainsString('house:start', $html);
    }

    /**
     * An answer that changed shape makes the page say LESS, never makes it fail.
     *
     * This renders on the first request to a house nobody configured. A surface that 500s because an
     * operation grew or lost a field turns someone else's change into its own outage.
     */
    public function testAnAnswerOfAnotherShapeDegradesInsteadOfFailing(): void
    {
        foreach ([[], ['ok' => true], ['ok' => true, 'app' => 'not an array'], ['ok' => 'yes']] as $i => $odd) {
            $state = HouseState::fromAnswer($odd);
            $html = $this->page(static fn (): HouseState => $state);
            self::assertStringContainsString('Milpa is running.', $html, 'shape ' . $i . ' still renders the page');
        }
    }

    /** A count reads as a sentence, so one capability is not «1 capabilities». */
    public function testACountIsWrittenAsASentenceAndNotAsData(): void
    {
        $answer = self::answer();
        $answer['capabilities']['installed'] = [['capability' => 'identity', 'package' => 'milpa/auth']];
        $answer['routes']['count'] = 1;
        $html = $this->page(static fn (): HouseState => HouseState::fromAnswer($answer));

        self::assertStringContainsString('1 capability', $html);
        self::assertStringContainsString('1 route', $html);
        self::assertStringNotContainsString('1 capabilities', $html);
        self::assertStringNotContainsString('1 routes', $html);
    }
}
