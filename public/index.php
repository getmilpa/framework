<?php

declare(strict_types=1);

use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Runtime\Http\RequestHandler;
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

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
echo $response->getBody();
