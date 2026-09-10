<?php

/**
 * This file is part of milpa/framework — the skeleton a Milpa app is created from.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace App\Tests\Http;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Operation;
use App\Plugins\OperationsHttpPlugin\OperationsHttpPlugin;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 AN OPERATION THAT DEMANDS CONSENT MUST NOT BE PUBLISHED WITHOUT SOMEBODY TO JUDGE WHO IS CALLING.
 *
 * This check read exactly two things — `scopes` and `permission` — and `provider:declare` declared
 * neither. It demanded a SIGNATURE, which is what the CLI reads. So the boot guard walked past it and
 * the HTTP projector turned «needs your signature» into «needs a token I will hand you». Measured on
 * cattle: TWO SAME-ORIGIN POSTS with no session, no principal and no signature wrote a provider
 * credential (greenhouse decisions/0274).
 *
 * A gate that is right on one surface and absent on the other is a gate at the height of the lower one.
 * If the CLI demands a signature, an HTTP host must have somebody who can say who is calling.
 *
 * AND THIS GUARD CANNOT SEE A HANDLER THAT REFUSES ON ITS OWN. `session:own` needs no policy — its
 * handler reads the granted authorization and refuses without it, because there the signature is the
 * payload rather than a gate — but that lives in the BODY, not in the declaration. So it refuses on
 * what is declared, and the cost to a host that wants to expose such an operation is one line of
 * config instead of a surprise in production (greenhouse decisions/0275).
 */
final class ConsentDemandsIdentityAtBootTest extends TestCase
{
    /** Consent alone, nothing else: the guard must still refuse. */
    public function testAnOperationThatOnlyDemandsConsentIsRefusedWithoutAPolicy(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exigen identidad o consentimiento (secret:write)');

        $this->assertGuarded([$this->consenting('secret:write')], hayPolitica: false);
    }

    /** With a policy to judge it, the same operation is publishable — that is what a policy is for. */
    public function testWithAPolicyTheSameOperationIsPublished(): void
    {
        $this->assertGuarded([$this->consenting('secret:write')], hayPolitica: true);

        self::assertTrue(true, 'no refusal: somebody can judge who is calling');
    }

    /** A scope alone still counts, as it always did — this widened the rule, it did not replace it. */
    public function testAScopeAloneStillCounts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scoped:op');

        $this->assertGuarded([
            new Operation(
                name: 'scoped:op',
                description: 'demands a scope and no consent',
                handler: static fn (): array => ['ok' => true],
                scopes: ['some:scope'],
            ),
        ], hayPolitica: false);
    }

    /**
     * AND AN OPERATION THAT DEMANDS NEITHER IS STILL FREE TO PUBLISH.
     *
     * The control for the widening: a read that asks nothing of the caller must not start needing a
     * policy, or every app that exposes one breaks on upgrade for no gain.
     */
    public function testAnOperationThatDemandsNothingNeedsNoPolicy(): void
    {
        $this->assertGuarded([
            new Operation(
                name: 'plain:read',
                description: 'asks nothing of the caller',
                handler: static fn (): array => ['ok' => true],
            ),
        ], hayPolitica: false);

        self::assertTrue(true, 'no refusal: nothing to protect');
    }

    private function consenting(string $name): Operation
    {
        return new Operation(
            name: $name,
            description: 'demands a signature and declares no scope — the shape that shipped as a hole',
            handler: static fn (): array => ['ok' => true],
            effects: new EffectProfile(mutation: Mutation::Persistent, authority: Authority::Privileged),
            mutating: true,
            requiresConfirmation: true,
        );
    }

    /** @param list<Operation> $exposed */
    private function assertGuarded(array $exposed, bool $hayPolitica): void
    {
        $method = new \ReflectionMethod(OperationsHttpPlugin::class, 'assertGuarded');
        $method->setAccessible(true);
        $method->invoke($this->plugin(), $exposed, $hayPolitica);
    }

    private function plugin(): OperationsHttpPlugin
    {
        $reflected = new \ReflectionClass(OperationsHttpPlugin::class);

        return $reflected->newInstanceWithoutConstructor();
    }
}
