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

use App\Http\IdentityChain;
use App\Tests\Support\OptIn;
use Milpa\Container\DIContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The composition `public/index.php` runs, EXECUTED rather than read (greenhouse decisions/0208).
 *
 * What is measured here is the skeleton's own decision: which principals sit in front of the
 * handler, in what order, and that each slot turns on by registration alone. What each principal
 * does with a credential is measured where it lives — milpa/auth for the Bearer, milpa/app-runtime
 * for the passkey session. The floor cases run on a bare app; the Bearer slot needs milpa/auth and
 * says so.
 */
final class IdentityChainTest extends TestCase
{
    /** A middleware that leaves its name on the request's trail and passes on — the order becomes data. */
    private static function tracing(string $name): MiddlewareInterface
    {
        return new class ($name) implements MiddlewareInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                /** @var list<string> $trail */
                $trail = $request->getAttribute('trail', []);
                $trail[] = $this->name;

                return $handler->handle($request->withAttribute('trail', $trail))->withAddedHeader('X-Seen-By', $this->name);
            }
        };
    }

    /** The real handler's stand-in: keeps the request it received on `->seen` and answers 200. */
    private static function recorder(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new Response(200, ['X-Handled' => 'yes']);
            }
        };
    }

    /**
     * A fresh app wires nothing: the request reaches the handler exactly as it came, and the
     * response comes back exactly as the handler made it. The bare pipeline is a chain of length zero,
     * not a special case.
     */
    public function testABareAppRunsTheHandlerStraight(): void
    {
        $chain = IdentityChain::fromContainer(new DIContainer());
        $request = new ServerRequest('GET', '/plugins');

        $handler = self::recorder();
        $response = $chain->handle($request, $handler);

        self::assertSame([], $chain->middlewares());
        self::assertSame($request, $handler->seen, 'nothing wired, nothing touched');
        self::assertSame('yes', $response->getHeaderLine('X-Handled'));
        self::assertFalse($response->hasHeader('X-Seen-By'));
    }

    /**
     * The passkey door registers its session middleware under its own class name; the chain picks it
     * up by that name alone. No class needs to exist for the slot to work — which is precisely how an
     * app on today's app-runtime and one on tomorrow's share this entry point.
     */
    public function testThePasskeySessionSlotTurnsOnByRegistration(): void
    {
        // The slot is asked for only where it can load (greenhouse evidence/0521, control F7): without
        // milpa/auth the chain does not even look, so this measures nothing on a bare app.
        OptIn::needs(IdentityChain::PASSKEY_SESSION_NEEDS);
        $container = new DIContainer();
        $container->registerService(IdentityChain::PASSKEY_SESSION, self::tracing('session'));

        $chain = IdentityChain::fromContainer($container);
        $handler = self::recorder();
        $response = $chain->handle(new ServerRequest('POST', '/agent'), $handler);

        self::assertCount(1, $chain->middlewares());
        self::assertNotNull($handler->seen);
        self::assertSame(['session'], $handler->seen->getAttribute('trail'), 'the session principal ran before the handler');
        self::assertSame('session', $response->getHeaderLine('X-Seen-By'), 'and the response flowed back through it');
    }

    /**
     * The floor of the passkey slot (greenhouse evidence/0521, control F7): on an app without milpa/auth
     * the chain must not even ASK the container for the session middleware — `has()` autoloads the
     * class to judge it, and the class implements a milpa/auth interface, so the question itself was a
     * fatal on every request of a fresh app. Here a middleware IS registered under the name, and the
     * chain still leaves it alone: the slot cannot load, so it does not exist.
     */
    public function testWithoutMilpaAuthThePasskeySlotIsNotEvenAskedFor(): void
    {
        if (interface_exists(IdentityChain::PASSKEY_SESSION_NEEDS)) {
            self::markTestSkipped('milpa/auth is installed: the floor has nothing to measure here');
        }
        $container = new DIContainer();
        $container->registerService(IdentityChain::PASSKEY_SESSION, self::tracing('session'));

        $chain = IdentityChain::fromContainer($container);
        $handler = self::recorder();
        $chain->handle(new ServerRequest('GET', '/capabilities'), $handler);

        self::assertSame([], $chain->middlewares(), 'the slot that cannot load is not asked for');
        self::assertNotNull($handler->seen);
        self::assertNull($handler->seen->getAttribute('trail'), 'the registered middleware never ran');
    }

    /**
     * Outermost first: the first middleware sees the request first and the response last. This is the
     * property the docblock promises — «Bearer goes FIRST» is only true if the fold preserves it.
     */
    public function testTheChainRunsOutermostFirst(): void
    {
        $chain = new IdentityChain([self::tracing('bearer'), self::tracing('session')]);

        $handler = self::recorder();
        $response = $chain->handle(new ServerRequest('GET', '/'), $handler);

        self::assertNotNull($handler->seen);
        self::assertSame(['bearer', 'session'], $handler->seen->getAttribute('trail'));
        self::assertSame(['session', 'bearer'], $response->getHeader('X-Seen-By'), 'the response unwinds inner to outer');
    }

    /**
     * A name that holds something other than a middleware is a mis-wiring, and it is said at the entry
     * point rather than skipped: a chain that silently drops a principal would authenticate less than
     * the app believes it does.
     */
    public function testSomethingThatIsNotAMiddlewareUnderTheSessionNameIsSaidLoudly(): void
    {
        OptIn::needs(IdentityChain::PASSKEY_SESSION_NEEDS);
        $container = new DIContainer();
        $container->registerService(IdentityChain::PASSKEY_SESSION, new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PasskeySessionMiddleware.*stdClass/');

        IdentityChain::fromContainer($container);
    }

    /** The Bearer slot holds itself to the same standard: a non-verifier under the verifier's name is said, not skipped. */
    public function testSomethingThatIsNotAVerifierUnderTheBearerNameIsSaidLoudly(): void
    {
        OptIn::needs(\Milpa\Auth\Http\AuthenticateMiddleware::class);

        $container = new DIContainer();
        $container->registerService(\Milpa\Auth\Contracts\CredentialVerifier::class, new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/CredentialVerifier.*stdClass/');

        IdentityChain::fromContainer($container);
    }

    /**
     * With a verifier in the container the Bearer slot is built from it and sits FIRST: by the time the
     * session principal runs, `milpa.auth` already carries the Bearer's verdict. That is the precedence
     * decisions/0208 fixes, measured from the skeleton's side — the session middleware's own «yield to
     * a decided context» is measured in milpa/app-runtime.
     *
     * Needs milpa/auth, so it runs where the opt-ins are installed (CI) and skips, saying so, on a bare
     * checkout — the four tests above are the floor and run everywhere.
     */
    public function testTheBearerSlotIsBuiltFromTheVerifierAndGoesFirst(): void
    {
        OptIn::needs(\Milpa\Auth\Http\AuthenticateMiddleware::class);

        $verifier = new class () implements \Milpa\Auth\Contracts\CredentialVerifier {
            public function verify(\Milpa\Auth\Credential $credential): \Milpa\Auth\AuthContext
            {
                return \Milpa\Auth\AuthContext::invalid('rejected on purpose');
            }
        };

        $container = new DIContainer();
        $container->registerService(\Milpa\Auth\Contracts\CredentialVerifier::class, $verifier);
        $container->registerService(IdentityChain::PASSKEY_SESSION, $session = new class () implements MiddlewareInterface {
            public mixed $sawAuth = 'never ran';

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->sawAuth = $request->getAttribute(\Milpa\Auth\Http\AuthenticateMiddleware::ATTRIBUTE);

                return $handler->handle($request);
            }
        });

        $chain = IdentityChain::fromContainer($container);
        $request = (new ServerRequest('POST', '/agent'))->withHeader('Authorization', 'Bearer not-a-real-token');
        $chain->handle($request, self::recorder());

        self::assertCount(2, $chain->middlewares());
        self::assertInstanceOf(\Milpa\Auth\Http\AuthenticateMiddleware::class, $chain->middlewares()[0], 'Bearer first');
        self::assertSame($session, $chain->middlewares()[1], 'the session principal second');
        self::assertInstanceOf(\Milpa\Auth\AuthContext::class, $session->sawAuth, 'the session slot receives the Bearer verdict');
        self::assertFalse($session->sawAuth->isAuthenticated(), 'a rejected Bearer arrives rejected, for the session to yield to');
    }
}
