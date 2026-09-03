<?php

declare(strict_types=1);

use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Http\ResponseEmitter;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/../vendor/autoload.php';

// Explicit, cwd-independent root: PHP's built-in server chdir()s into the docroot (-t public)
// for every request, so leaving this to Kernel::boot()'s own auto-detection would still work
// here (RootResolver falls back to Composer\InstalledVersions, not getcwd(), when it can) but
// passing it explicitly keeps this entry point honest about where "the app" actually is,
// exactly like config/boot.php below, which is loaded relative to the same root.
$root = \dirname(__DIR__);

/** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
$boot = require $root . '/config/boot.php';

/** @var array<string, mixed> $config */
$config = require $root . '/config/app.php';

$kernel = Kernel::boot([
    'root' => $root,
    'plugins' => $boot['plugins'],
    'config' => $config,
    'container' => $boot['container'],
]);

// The kernel goes INTO the container, here as in `bin/coa`: the operation layer resolves what
// lives under the app's root — the agent session store, for one — by asking the container for the
// kernel. Without this line every `agent:*` operation that declares an `http` surface answers
// «nowhere to store sessions» over the web while working from the terminal, and that reads as a
// broken app instead of as a missing line in this file.
$boot['container']->registerService(Kernel::class, $kernel);

$psr17 = new Psr17Factory();
$request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

$handler = new RequestHandler($kernel, $psr17);

// La cadena de autenticación, si esta app la cableó. `AuthenticateMiddleware` resuelve el
// `Authorization: Bearer …` a un contexto verificado y lo deja en el atributo que las compuertas
// leen. Es fail-open a propósito: autenticar no es autorizar — quien decide si la falta de actor es
// un 401 o una ruta pública es la política de cada operación, no esto.
//
// Sin verificador registrado el pipeline es el de antes, y una operación con scopes simplemente no
// se puede exponer (config/http.php lo dice al arrancar).
$container = $kernel->container();
$response = $container->has(CredentialVerifier::class)
    ? (new AuthenticateMiddleware($container->get(CredentialVerifier::class)))->process($request, $handler)
    : $handler->handle($request);

// La emisión vive en `ResponseEmitter`: manda status + headers y luego el cuerpo. Si el cuerpo es un
// `CallbackStream` lo STREAMEA (vence el output buffering y corre el callback), así una operación puede
// servir `text/event-stream` en vivo; cualquier respuesta normal se emite igual que antes. Una línea en
// vez de tres, y con ella la app gana SSE sin tocar este archivo.
(new ResponseEmitter())->emit($response);
