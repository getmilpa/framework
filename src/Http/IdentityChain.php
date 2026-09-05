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

namespace App\Http;

use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Interfaces\Di\DIContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The identity chain `public/index.php` runs before the request reaches the operations surface: WHO
 * the caller is, resolved from every principal this app wired, in a fixed order — never WHAT the
 * caller may do, which stays the operation's policy (greenhouse decisions/0208).
 *
 * ── TWO PRINCIPALS, ONE ORDER ────────────────────────────────────────────────────────────────────
 *
 *   1. `AuthenticateMiddleware` (milpa/auth) — the Bearer channel. Present when the app registered a
 *      `CredentialVerifier`; `config/boot.php` does, once milpa/auth and milpa/data are installed.
 *   2. `PasskeySessionMiddleware` (milpa/app-runtime) — the passkey session cookie. Present when the
 *      passkey door is wired: `PasskeyPlugin` registers the instance under its own class name.
 *
 * Bearer goes FIRST on purpose. The session middleware yields to a context the Bearer already decided
 * — authenticated or rejected — so a revoked token is never laundered by a cookie that happens to
 * ride along. Both are producers, not guards: neither answers 401. A request with no credential at
 * all reaches the handler anonymous, and the operation's own policy decides what that means.
 *
 * ── WHY EVERY SLOT IS OPTIONAL ───────────────────────────────────────────────────────────────────
 *
 * A fresh app has no identity package and runs the bare pipeline; an app that adds one gains its
 * principal without editing the entry point. Each slot is decided by what the container HOLDS, not by
 * what is installed: the verifier is an interface the container cannot invent, and the session
 * middleware takes a scalar cookie name its autowiring cannot fabricate — so only a registration
 * turns a slot on. Something registered under a slot's name that is not what the slot needs — a
 * non-verifier, a non-middleware — is said loudly rather than skipped: a chain that quietly drops a
 * principal is a chain nobody can reason about.
 */
final class IdentityChain
{
    /**
     * The container name the passkey door registers its session middleware under —
     * `Milpa\AppRuntime\Web\PasskeySessionMiddleware` (milpa/app-runtime ≥ 0.120).
     *
     * Spelled as a string, not `::class`, because this skeleton's floor is `milpa/app-runtime >=0.4`:
     * a class constant on a class the installed version does not ship is fine at runtime, but a static
     * analyser cannot resolve it. Raise the floor past 0.120 and this may become the constant. The
     * Bearer slot's two symbols are opt-in too (`milpa/auth`), and are guarded the way `config/boot.php`
     * guards its own — `interface_exists` / `class_exists`, which is how the same analyser learns on a
     * bare app that they are optional rather than missing.
     */
    public const PASSKEY_SESSION = 'Milpa\\AppRuntime\\Web\\PasskeySessionMiddleware';

    /**
     * @param list<MiddlewareInterface> $middlewares outermost first — the first one sees the request first
     */
    public function __construct(
        private readonly array $middlewares,
    ) {
    }

    /**
     * The chain this app actually wired: each slot present only when the container holds it, in the
     * order the class docblock fixes.
     *
     * @throws \RuntimeException when a slot's name holds something that is not what the slot needs
     */
    public static function fromContainer(DIContainerInterface $container): self
    {
        $chain = [];

        if (interface_exists(CredentialVerifier::class) && class_exists(AuthenticateMiddleware::class) && $container->has(CredentialVerifier::class)) {
            $verifier = $container->get(CredentialVerifier::class);
            if (!$verifier instanceof CredentialVerifier) {
                throw new \RuntimeException(\sprintf(
                    'public/index.php: the service registered under %s is %s, not a CredentialVerifier — the Bearer cannot be a principal.',
                    CredentialVerifier::class,
                    get_debug_type($verifier),
                ));
            }
            $chain[] = new AuthenticateMiddleware($verifier);
        }

        if ($container->has(self::PASSKEY_SESSION)) {
            $session = $container->get(self::PASSKEY_SESSION);
            if (!$session instanceof MiddlewareInterface) {
                throw new \RuntimeException(\sprintf(
                    'public/index.php: the service registered under %s is %s, not a PSR-15 middleware — the passkey session cannot be a principal.',
                    self::PASSKEY_SESSION,
                    get_debug_type($session),
                ));
            }
            $chain[] = $session;
        }

        return new self($chain);
    }

    /**
     * The principals in the order they run, outermost first. Empty on an app with no identity wired.
     *
     * @return list<MiddlewareInterface>
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Run the request through every principal and then into `$handler`.
     *
     * Composed by folding from the innermost out: each middleware receives, as its `$handler`, the rest
     * of the chain ending in the real one. With nothing wired the handler simply runs on its own.
     */
    public function handle(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $next = $handler;
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = self::link($middleware, $next);
        }

        return $next->handle($request);
    }

    /** One link of the chain: a handler that runs `$middleware` with `$next` as its continuation. */
    private static function link(MiddlewareInterface $middleware, RequestHandlerInterface $next): RequestHandlerInterface
    {
        return new class ($middleware, $next) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $middleware,
                private readonly RequestHandlerInterface $next,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }
}
