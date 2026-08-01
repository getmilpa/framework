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

namespace App\Operations;

use App\Support\Capabilities;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;

/**
 * What this app can do today, and how it grows.
 *
 * ── TWO OPERATIONS, AND THE SECOND ONE IS THE POINT ─────────────────────────────────────────────
 *
 * `capabilities` lists what is installed and what is not. `capabilities:enable` **turns one on**.
 *
 * Handing back the string `composer require milpa/agent milpa/ai-gateway` and stopping there looks
 * safer, and it is not: it leaves the operator to compose a shell command, run it in the right
 * directory, and work out which packages a capability actually needs. That is three decisions the
 * system already knows the answer to — and four rounds of measurement in this programme say every
 * decision handed to the agent costs correctness.
 *
 * **Controlling the environment is how you align the operator.** One operation instead of three steps
 * is not a shortcut: it is the system doing the part it is authoritative about.
 *
 * ── WHY THIS IS NOT A HOLE ──────────────────────────────────────────────────────────────────────
 *
 * An earlier version of this file said the catalogue must never install, because changing an app's
 * dependencies without authorisation belongs to a policy. The premise was right and the conclusion
 * was wrong: what must not happen is installing **without authorisation** — not installing.
 *
 * So this declares `mutating: true` and goes through the same gate every other mutating tool does:
 * the agent proposes, a human consents. And `dry_run` prints the exact command without running it, so
 * consent is given to something legible rather than to a name.
 *
 * ── AND WHY NOT OVER HTTP ───────────────────────────────────────────────────────────────────────
 *
 * Installing a package runs code from the network on the host. That is a different risk class from
 * reading a session, and a scope does not hold it: an HTTP surface reachable from anywhere turns one
 * leaked token into arbitrary code on the box. It stays where whoever invokes it is already on the
 * machine.
 */
final readonly class CapabilityOperations implements CommandProvider
{
    // No constructor, on purpose: `Support\Operations::declared()` builds a provider with the
    // container as soon as it takes one parameter, so a "just for tests" parameter would receive the
    // container in production. The provider contract decides this class's shape.

    /**
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'capabilities',
                description: 'What this app can do today, and the command that grows it',
                handler: fn (array $input): array => Capabilities::answer(),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
                // EVERY SURFACE. If the agent cannot call it, the system shows the way only to
                // whoever already knew where to look.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'capabilities:enable',
                description: 'Install an opt-in capability by name — one step instead of three',
                handler: fn (array $input): array => $this->enable($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'capability' => [
                            'type' => 'string',
                            'description' => 'The package name `capabilities` lists under `available`',
                        ],
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'Print the exact command instead of running it',
                        ],
                    ],
                    'required' => ['capability'],
                ],
                // MUTATING, AND NO SIGNATURE. It goes through the agent's permission gate like every
                // other mutating tool, but a signature is for what cannot be undone: this is reversed
                // with `composer remove`, and whoever runs it is already on the terminal of this app —
                // the same power. It is the argument `token:new` makes, for the same reason. Asking
                // for a signature here would make it the kind of prompt people approve without
                // reading, which is how the ones that mattered stop being read.
                mutating: true,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function enable(array $input): array
    {
        return Capabilities::install(
            \is_string($input['capability'] ?? null) ? $input['capability'] : '',
            dryRun: ($input['dry_run'] ?? false) === true,
        );
    }
}
