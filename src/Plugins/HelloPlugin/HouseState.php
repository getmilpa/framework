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

namespace App\Plugins\HelloPlugin;

/**
 * WHAT AN ANONYMOUS VISITOR MAY KNOW ABOUT THIS HOUSE — the fields are the decision.
 *
 * The welcome page used to be a static tutorial: true the day it was written and stale from then on.
 * `house:start` already derives the next step from state, so the page can report the state itself and
 * stop maintaining a second copy of the answer. What it must NOT do is report everything the operation
 * knows, and that is why this type exists rather than an array: the dangerous fields are absent BY
 * CONSTRUCTION, so a later edit cannot pass them through by forgetting to filter (greenhouse decisions/0312).
 *
 * 🚨 THREE FIELDS OF THAT ANSWER NEVER REACH A STRANGER, and each is a different kind of leak:
 *
 * - `app.root` is a filesystem path. It is the reason `house:start` declares `surfaces: ['cli','tui','mcp']`
 *   and NOT http: an ops route published under `expose: ['*']` would answer it without a principal.
 *   This page is not an http client of the operation — it is a controller inside the same app calling
 *   the same handler — so the boundary has to be held HERE instead.
 * - `routes.paths` ENUMERATES the surface. On a fresh app that is `/` and the design assets; on a house
 *   with the panel and the passkey door it is `/milpa/admin`, `/webauthn/enroll`, `/milpa/admin/live` —
 *   a map, handed to whoever asked for the front page. The COUNT is not a map.
 * - `capabilities.installed` names the doors: «identity», «the admin panel». A count says the house is
 *   equipped; a list says which locks to go and read about.
 *
 * What is left is what a visitor can already see or could not misuse: that it runs, whether it has been
 * FOUNDED (the app's own declaration of what it is for), and two counts.
 *
 * 🚨 AND NOT THE SENTENCE THE FOUNDATION AUTHORITY COMPUTED, though it was here first and reads well.
 * `foundation_says` is `teach.how` — «Call `foundation:found` with the domain the HUMAN named…» — and
 * that is a STEP. `decisions/0302` cut this page's second sentence for exactly this reason: if
 * `house:start` really derives the next step from state, the page promising it is the page maintaining
 * a second copy of an answer that moves. STATE is the page's; the CRITERION is the operation's. The
 * page says where the house stands and hands over the door; what to do about it is what running the
 * door is for.
 *
 * 🚨 AND «I COULD NOT READ IT» IS A STATE, not a zero. `Capabilities::state()` has no manifest guard —
 * only `houseStart()` does — so a page deriving the registry itself would print «0 installed» as a FACT
 * after an interrupted `composer install`, which is the exact defect `decisions/0307` paid to remove
 * from the CLI. Calling the same handler is what buys the guard; {@see $cannotSay} is what carries it.
 */
final readonly class HouseState
{
    /**
     * @param bool        $known        whether the house could say what it is — false leaves every field below unusable
     * @param string|null $cannotSay    why it could not, in the authority's own words, when `$known` is false
     * @param bool        $founded      whether a domain and a boundary have been declared
     * @param int         $capabilities how many capabilities are installed — a count, never the names
     * @param int         $routes       how many routes this app publishes — a count, never the paths
     */
    private function __construct(
        public bool $known,
        public ?string $cannotSay,
        public bool $founded,
        public int $capabilities,
        public int $routes,
    ) {
    }

    /**
     * The projection of one `house:start` answer, keeping only what a stranger may know.
     *
     * Reads the answer defensively at every step rather than trusting its shape: this page renders on
     * the first request to a house nobody has configured, and an answer that changed shape must make
     * the page say less, never make it fail. A surface that 500s because an operation grew a field is
     * a surface that turned someone else's addition into its own outage.
     *
     * @param array<string, mixed> $answer what `AgentOperations::houseStart()` returned
     */
    public static function fromAnswer(array $answer): self
    {
        if (($answer['ok'] ?? false) !== true) {
            $why = $answer['error'] ?? null;

            return new self(
                known: false,
                cannotSay: \is_string($why) && $why !== '' ? $why : null,
                founded: false,
                capabilities: 0,
                routes: 0,
            );
        }

        $app = \is_array($answer['app'] ?? null) ? $answer['app'] : [];
        $capabilities = \is_array($answer['capabilities'] ?? null) ? $answer['capabilities'] : [];
        $routes = \is_array($answer['routes'] ?? null) ? $answer['routes'] : [];

        return new self(
            known: true,
            cannotSay: null,
            founded: ($app['foundation'] ?? null) === 'founded',
            capabilities: \count(\is_array($capabilities['installed'] ?? null) ? $capabilities['installed'] : []),
            routes: (int) ($routes['count'] ?? 0),
        );
    }

    /** The state of a house that was never asked — the page renders no strip at all. */
    public static function unasked(): self
    {
        return new self(known: false, cannotSay: null, founded: false, capabilities: 0, routes: 0);
    }
}
