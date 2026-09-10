<?php

declare(strict_types=1);

use App\Http\IdentityChain;
use Milpa\Runtime\Http\ExceptionMiddleware;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Http\ResponseEmitter;
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Observability\ErrorLogLogger;
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
// What the machine wrote through a governed operation (`config:set`) lays over what the human wrote —
// the same overlay `bin/coa` applies. Without it a consented change reached the terminal and not the
// browser (greenhouse decisions/0216, point 4).
if (class_exists(\Milpa\AppRuntime\Config\MachineOverlay::class)) {
    $config = \Milpa\AppRuntime\Config\MachineOverlay::sobre($config, $root);
}
// And the machine's secrets last — the most specific thing anybody declared, and the only one that
// could not have been declared anywhere else. This file is gitignored: `config/app.php` is the file a
// person opens and `.milpa/agent.json` travels with the repository, so before this destination existed
// a credential had to go to git or to a file nothing read (greenhouse decisions/0267).
if (class_exists(\Milpa\AppRuntime\Config\SecretOverlay::class)) {
    $config = \Milpa\AppRuntime\Config\SecretOverlay::sobre($config, $root);
}

// THE APP'S LOG, which is what makes the 500 page below tell the truth. `ErrorLogLogger` writes
// through PHP's own `error_log()` — the destination this deployment already configured, whatever it
// is: php-fpm's error log, the web server's, the console under `php -S`, stdout in a container. It
// carries a `warning` floor, so a working app's narration does not bury the one `error` line a 500's
// reference points at; `new ErrorLogLogger(LogLevel::DEBUG)` if you want the narration.
//
// It is passed HERE, to the kernel, rather than only to the middleware, because this is the logger
// the whole app then shares — the kernel registers it under `Psr\Log\LoggerInterface` and a plugin
// asks the container for the contract. Swap this line for your own PSR-3 logger and everything that
// logs follows, including the failure path below (greenhouse decisions/0286).
$logger = new ErrorLogLogger();

$kernel = Kernel::boot([
    'root' => $root,
    'plugins' => $boot['plugins'],
    'config' => $config,
    'container' => $boot['container'],
    'logger' => $logger,
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
//
// The passkey session is the SECOND principal of this chain (greenhouse decisions/0208). When the
// passkey door is wired, `PasskeyPlugin` registers `PasskeySessionMiddleware` under its own class name
// and `IdentityChain` picks it up AFTER the Bearer: it yields to a context the Bearer already decided
// (a rejected token is never laundered by a cookie), drops a session whose enrollment was revoked, and
// trusts the cookie on a mutating request only when the body is JSON and the fetch is same-origin.
// `App\Http\IdentityChain` folds whatever principals the container holds so this file does not grow a
// nesting per identity package — and a fresh app, holding none, runs the bare handler.
// NOTHING ESCAPES AS A FATAL. Measured on a fresh app before this line existed (greenhouse
// decisions/0215, F3): a controller that threw answered 500 with a ZERO-BYTE body and `text/html`
// whatever the caller's `Accept` said — and with `display_errors` on, which this app does not
// control, the message, the file path and the whole stack trace went to the CLIENT.
//
// `ExceptionMiddleware` answers a rendered 500, JSON or HTML as the caller asked, and NEVER carries
// the exception's message: the detail goes to the log, joined to the response by a reference the
// body shows. `debug` in `config/app.php` adds the class, the file and the line — where it happened,
// never what it said. It wraps the identity chain rather than sitting inside it, so a failure while
// DECIDING who you are is rendered too.
//
// THE LOGGER IS NOT `null` HERE, AND THAT USED TO BE THE WHOLE DEFECT. Measured on a fresh app
// (greenhouse decisions/0286): the body said «the detail is in this app's log» and the reference it
// showed appeared in ZERO files, because this line passed `null`. A page that names a log there is no
// log for is worse than one that says nothing — it sends whoever reads it looking for a file.
$failures = new ExceptionMiddleware($psr17, $logger, (bool) ($config['app']['debug'] ?? false));

$response = $failures->process(
    $request,
    new class ($kernel, $handler) implements \Psr\Http\Server\RequestHandlerInterface {
        public function __construct(
            private readonly Kernel $kernel,
            private readonly RequestHandler $handler,
        ) {
        }

        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return IdentityChain::fromContainer($this->kernel->container())->handle($request, $this->handler);
        }
    },
);

// La emisión vive en `ResponseEmitter`: manda status + headers y luego el cuerpo. Si el cuerpo es un
// `CallbackStream` lo STREAMEA (vence el output buffering y corre el callback), así una operación puede
// servir `text/event-stream` en vivo; cualquier respuesta normal se emite igual que antes. Una línea en
// vez de tres, y con ella la app gana SSE sin tocar este archivo.
(new ResponseEmitter())->emit($response);
