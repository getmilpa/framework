<?php

declare(strict_types=1);

use App\Http\IdentityWiring;
use Milpa\Container\DIContainer;
use Milpa\Plugin\Activation\ActivePlugins;
use Milpa\Plugin\Contracts\AppRoot;

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

// ── Identidad — OPT-IN ──────────────────────────────────────────────────────────────────────────
//
// Tres piezas, y ninguna es opcional si quieres servir operaciones protegidas por HTTP:
//
//   1. el ALMACÉN de tokens — `milpa/data` con el backend que digas en config/app.php;
//   2. el VERIFICADOR, que convierte un `Authorization: Bearer …` en un actor con scopes;
//   3. la POLÍTICA, que decide si ese actor puede correr ESTA operación.
//
// Pero son opcionales para EXISTIR. Este bloque sólo corre si `milpa/auth` y `milpa/data` están
// instalados, porque una app recién creada no tiene por qué cargar con una base de datos y un
// esquema de tokens para saludar por HTTP. Sin ellas la app sigue funcionando entera por terminal y
// por MCP —que corren en la máquina de quien los invoca— y `config/http.php` no puede exponer nada
// que declare scopes. Con ellas, sí.
//
// Se pregunta por las clases y no por el composer.json porque lo que importa es si el código está,
// no lo que un archivo diga que debería estar: `composer remove` deja el manifiesto al día y el
// vendor sin las clases, y es el vendor el que decide si esto arranca.
//
// Para encenderlo:  composer require milpa/auth milpa/data     (o `coa capabilities` para verlo)
//
// THE POLICY DOES NOT DEPEND ON THE TOKENS (greenhouse decisions/0208). The three pieces used to be
// one block, gated by both packages at once — so a house with a passkey door and no `milpa/data` had
// no policy, and could not expose a scoped operation even though the passkey session is a complete
// auth chain in the policy's eyes. Now each piece is gated by the package it is made of: the POLICY
// with `milpa/auth` alone; the STORE and the VERIFIER with `milpa/data`. `App\Http\IdentityWiring`
// holds both registrations, each executed by its own test.
/** @var array<string, mixed> $configApp */
$configApp = require __DIR__ . '/app.php';
/** @var array<string, mixed> $almacen */
$almacen = \is_array($configApp['storage'] ?? null) ? $configApp['storage'] : ['driver' => 'file', 'path' => __DIR__ . '/../storage/tokens.json'];

IdentityWiring::policy($container);
IdentityWiring::bearer($container, $almacen);

return ['container' => $container, 'plugins' => $plugins];
