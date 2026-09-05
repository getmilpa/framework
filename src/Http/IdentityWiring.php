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

use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Http\AuthOperationHttpPolicy;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Data\RepositoryFactory;
use Milpa\Interfaces\Di\DIContainerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * What `config/boot.php` registers for identity — each piece gated by the package it is made of,
 * and by nothing else (greenhouse decisions/0208, slice 1 pt. 6).
 *
 * ── THE POLICY DOES NOT DEPEND ON THE TOKENS ─────────────────────────────────────────────────────
 *
 * Two registrations, two gates:
 *
 *   - {@see self::policy()} — the `OperationHttpPolicy` that decides whether an actor may run THIS
 *     operation. It needs `milpa/auth` alone. It used to be registered only together with the token
 *     store, so a house with a passkey door and no `milpa/data` had no policy at all — and
 *     `config/http.php` refused to boot with any scoped operation exposed, even though the passkey
 *     session (`PasskeySessionMiddleware`, registered as the container's `AuthContextFactory`) was a
 *     complete auth chain in the policy's eyes.
 *   - {@see self::bearer()} — the token repository and the `CredentialVerifier` that turns
 *     `Authorization: Bearer …` into an actor. It needs `milpa/data` (and `milpa/auth`, whose
 *     interface it implements).
 *
 * The policy asks the container for a `CredentialVerifier` OR an `AuthContextFactory` when it judges a
 * request, not when it is built; so a house may register it now and gain its chain later — the passkey
 * plugin registers the factory in `boot()`, after this file ran. A house with `milpa/auth` and neither
 * a token store nor a passkey door boots, and a scoped operation over HTTP answers a 500 that names
 * what is missing (`MILPA_AUTH_MIDDLEWARE_NOT_INSTALLED`) — a server configuration error said as one.
 *
 * Each method asks the autoloader, never `composer.json`: `composer remove` leaves the manifest tidy
 * and the vendor without the classes, and it is the vendor that decides whether this wires.
 */
final class IdentityWiring
{
    /**
     * The policy: registered whenever `milpa/auth` is installed, whether or not a verifier ever is.
     *
     * @return bool whether it was wired — false when `milpa/auth` is absent
     */
    public static function policy(DIContainerInterface $container): bool
    {
        if (!class_exists(AuthOperationHttpPolicy::class)) {
            return false;
        }

        $psr17 = new Psr17Factory();
        $container->registerService(OperationHttpPolicy::class, new AuthOperationHttpPolicy($container, $psr17, $psr17));

        return true;
    }

    /**
     * The Bearer: the token repository, and the verifier that reads it — only with `milpa/data`.
     *
     * @param array<string, mixed> $storage the token store's config (`storage` in config/app.php, or the file default)
     *
     * @return bool whether it was wired — false when `milpa/data` or `milpa/auth` is absent
     */
    public static function bearer(DIContainerInterface $container, array $storage): bool
    {
        if (!class_exists(RepositoryFactory::class) || !interface_exists(CredentialVerifier::class)) {
            return false;
        }

        $tokens = RepositoryFactory::fromConfig($storage, ApiToken::class);
        $container->registerService(TokenVerifier::class . '.repository', $tokens);
        $container->registerService(CredentialVerifier::class, new TokenVerifier($tokens));

        return true;
    }
}
