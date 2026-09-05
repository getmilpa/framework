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

namespace App\Tests\Http;

use App\Http\IdentityWiring;
use App\Tests\Support\OptIn;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * The identity registrations `config/boot.php` makes, EXECUTED per package rather than read as one
 * block (greenhouse decisions/0208, slice 1 pt. 6): the policy comes with milpa/auth alone, the
 * Bearer only with milpa/data. What each piece does with a request is measured where it lives.
 */
final class IdentityWiringTest extends TestCase
{
    private const POLICY = 'Milpa\\Auth\\Http\\AuthOperationHttpPolicy';
    private const VERIFIER = 'Milpa\\Auth\\Contracts\\CredentialVerifier';
    private const REPOSITORY_FACTORY = 'Milpa\\Data\\RepositoryFactory';
    private const TOKEN_REPOSITORY = 'Milpa\\AppRuntime\\Auth\\TokenVerifier.repository';

    /**
     * THE SPLIT, executed: the policy is wired with no verifier anywhere near it. This is the state
     * a cookie-only house (passkey door, no token store) boots in — before decisions/0208 it had no
     * policy and could not expose a scoped operation at all.
     */
    public function testThePolicyComesWithAuthAloneAndBringsNoVerifier(): void
    {
        OptIn::needs(self::POLICY);

        $container = new DIContainer();

        self::assertTrue(IdentityWiring::policy($container));
        self::assertTrue($container->has(OperationHttpPolicy::class), 'the policy is registered');
        self::assertInstanceOf(self::POLICY, $container->get(OperationHttpPolicy::class));
        self::assertFalse($container->has(self::VERIFIER), 'and it brought no verifier with it');
        self::assertFalse($container->has(self::TOKEN_REPOSITORY), 'nor a token store');
    }

    /** The Bearer needs the token store: with milpa/data, the repository and the verifier that reads it. */
    public function testTheBearerComesWithTheTokenStore(): void
    {
        OptIn::needs(self::REPOSITORY_FACTORY, self::VERIFIER);

        $container = new DIContainer();
        $path = sys_get_temp_dir() . '/milpa-wiring-' . bin2hex(random_bytes(6)) . '.json';

        self::assertTrue(IdentityWiring::bearer($container, ['driver' => 'file', 'path' => $path]));
        self::assertTrue($container->has(self::VERIFIER), 'the verifier is registered');
        self::assertInstanceOf(self::VERIFIER, $container->get(self::VERIFIER));
        self::assertTrue($container->has(self::TOKEN_REPOSITORY), 'over the token repository');
        self::assertFalse($container->has(OperationHttpPolicy::class), 'the Bearer alone is not the policy');
    }

    /**
     * On a bare app neither package is installed, and both registrations say so by wiring nothing —
     * the container stays exactly as it was, and nothing throws. This is the floor, measured with
     * zero opt-ins; the two tests above measure the same methods where the packages are.
     */
    public function testWithoutThePackagesNothingIsWiredAndNothingBreaks(): void
    {
        $container = new DIContainer();

        if (!OptIn::has(self::POLICY)) {
            self::assertFalse(IdentityWiring::policy($container), 'no milpa/auth, no policy');
            self::assertFalse($container->has(OperationHttpPolicy::class));
        }
        if (!OptIn::has(self::REPOSITORY_FACTORY)) {
            self::assertFalse(IdentityWiring::bearer($container, ['driver' => 'file', 'path' => '/nonexistent/tokens.json']), 'no milpa/data, no Bearer');
            self::assertFalse($container->has(self::VERIFIER));
        }
        if (OptIn::has(self::POLICY) && OptIn::has(self::REPOSITORY_FACTORY)) {
            self::markTestSkipped('every identity opt-in is installed: the floor has nothing to measure here');
        }
    }

    /**
     * `config/boot.php` calls both, and each lands exactly when its package is present — asserted as
     * the invariant so it holds on a bare app, in CI with every opt-in, and in a house with one of them.
     */
    public function testBootPhpWiresEachPieceWhenItsPackageIsPresent(): void
    {
        /** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
        $boot = require \dirname(__DIR__, 2) . '/config/boot.php';
        $container = $boot['container'];

        self::assertSame(OptIn::has(self::POLICY), $container->has(OperationHttpPolicy::class), 'the policy tracks milpa/auth');
        self::assertSame(
            OptIn::has(self::REPOSITORY_FACTORY) && OptIn::has(self::VERIFIER),
            $container->has(self::VERIFIER),
            'the verifier tracks milpa/data',
        );
    }
}
