<?php

declare(strict_types=1);

use App\Auth\ApiToken;
use App\Auth\TokenVerifier;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Http\AuthOperationHttpPolicy;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Container\DIContainer;
use Milpa\Data\RepositoryFactory;
use Milpa\Plugin\Activation\ActivePlugins;
use Milpa\Plugin\Contracts\AppRoot;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * What every entry point needs before `Milpa\Runtime\Kernel::boot()` can run: the container, and
 * the list of plugins that actually boot.
 *
 * They come from here together on purpose. The list is resolved from two sources — what
 * `config/plugins.php` declares and what `storage/plugins.json` says is switched on — and the
 * management operations write to that same store. If an entry point built the container one way
 * and the list another, the app would boot from one answer and be managed through a different one.
 * `ActivePlugins::wire()` is a single call precisely so that cannot happen.
 *
 * Register your own long-lived services on `$container` below when a plugin needs them at
 * construction time. Everything else belongs in a plugin's `boot()`; `Kernel::boot()` registers
 * the framework's own services into this same container.
 *
 * @return array{container: DIContainer, plugins: list<class-string>}
 */
$container = new DIContainer();

// Dónde vive esta app. `milpa/plugin` lo pregunta en vez de calcularlo desde su propio archivo
// —instalado por Composer, contar directorios hacia arriba apunta adentro de `vendor/`— y con esta
// línea aparecen sus dos operaciones que tocan disco: `plugins:verify` y `plugins:lock`. Sin ella no
// se ofrecen, que es preferible a ofrecerlas apuntando a una ruta inventada.
$container->registerService(AppRoot::class, new AppRoot(\dirname(__DIR__)));

/** @var list<class-string> $declared */
$declared = require __DIR__ . '/plugins.php';

$plugins = ActivePlugins::wire($container, $declared, __DIR__ . '/../storage/plugins.json');

// ── Identidad ───────────────────────────────────────────────────────────────────────────────────
//
// Tres piezas y ninguna es opcional si quieres servir operaciones protegidas por HTTP:
//
//   1. el ALMACÉN de tokens — `milpa/data` con el backend que digas en config/app.php;
//   2. el VERIFICADOR, que convierte un `Authorization: Bearer …` en un actor con scopes;
//   3. la POLÍTICA, que decide si ese actor puede correr ESTA operación.
//
// Sin ellas la app sigue funcionando entera por terminal y por MCP —que corren en la máquina de
// quien los invoca— y `config/http.php` no puede exponer nada que declare scopes. Con ellas, sí.
/** @var array<string, mixed> $configApp */
$configApp = require __DIR__ . '/app.php';
/** @var array<string, mixed> $almacen */
$almacen = \is_array($configApp['storage'] ?? null) ? $configApp['storage'] : ['driver' => 'file', 'path' => __DIR__ . '/../storage/tokens.json'];

$tokens = RepositoryFactory::fromConfig($almacen, ApiToken::class);
$container->registerService(TokenVerifier::class . '.repository', $tokens);
$container->registerService(CredentialVerifier::class, new TokenVerifier($tokens));

$psr17 = new Psr17Factory();
$container->registerService(OperationHttpPolicy::class, new AuthOperationHttpPolicy($container, $psr17, $psr17));

return ['container' => $container, 'plugins' => $plugins];
