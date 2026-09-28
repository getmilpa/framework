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

// FrankenPHP WORKER MODE — opt-in. `php -S`, `coa serve` and FrankenPHP classic never read this file:
// they run `public/index.php`, one fresh kernel per request, and nothing here changes them.
//
// A worker boots the kernel ONCE and serves many requests with it. That is its whole advantage and,
// measured on fresh cattle (greenhouse evidence/1035), its whole defect: a plugin installed through the
// panel answered 404 until the process restarted, and a model written through the panel was unknown
// to the next turn. So this loop asks, around every request, whether what defined its kernel changed
// (`Milpa\AppRuntime\Support\KernelDefinition`, greenhouse decisions/0505):
//
//   - when a request ENDS and it changed (this request installed, promoted or wrote config), the worker
//     takes no other request: it leaves the loop and FrankenPHP starts a clean one;
//   - when a request STARTS and it changed (another worker, `coa` from a terminal, a person), the stale
//     worker does not serve it: it answers `307` to the same URL — a browser and `fetch` repeat it,
//     same method and body — and leaves. The repeat reaches a worker whose kernel is current.
//
// It never re-boots in place: PHP does not redefine a loaded class, and Composer's autoloader in memory
// still holds the maps from before an install. Only a new process is a new kernel.
//
// To serve with it (the `php_server` index is what makes the mode REAL — without it requests run
// classic on the spare threads; the proof is `frankenphp_worker_request_count` growing):
//
//     {
//         frankenphp {
//             php_ini max_execution_time 0      # a turn outlives 30 s; on ZTS the limit is wall time
//             worker { file ./public/worker.php }
//         }
//     }
//     :8000 {
//         root * ./public
//         php_server { index worker.php }
//     }

use App\Http\IdentityChain;
use Milpa\AppRuntime\Support\KernelDefinition;
use Milpa\Runtime\Http\ExceptionMiddleware;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Http\ResponseEmitter;
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Observability\ErrorLogLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/../vendor/autoload.php';

$root = \dirname(__DIR__);

// Without the runtime that knows its definition, a long-lived kernel would be exactly the stale one
// measured in 1035. Then this worker boots per request instead: slower, never wrong.
$knowsItsDefinition = class_exists(KernelDefinition::class);
if (!$knowsItsDefinition) {
    error_log('[milpa] public/worker.php: milpa/app-runtime has no KernelDefinition (it needs 0.196 or later); booting per request.');
}

// The same boot as `public/index.php` — read that file for why each line is there.
$boot = static function () use ($root): array {
    /** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
    $boot = require $root . '/config/boot.php';
    /** @var array<string, mixed> $config */
    $config = require $root . '/config/app.php';
    if (class_exists(\Milpa\AppRuntime\Config\MachineOverlay::class)) {
        $config = \Milpa\AppRuntime\Config\MachineOverlay::sobre($config, $root);
    }
    if (class_exists(\Milpa\AppRuntime\Config\SecretOverlay::class)) {
        $config = \Milpa\AppRuntime\Config\SecretOverlay::sobre($config, $root);
    }
    $logger = new ErrorLogLogger();
    $kernel = Kernel::boot([
        'root' => $root,
        'plugins' => $boot['plugins'],
        'config' => $config,
        'container' => $boot['container'],
        'logger' => $logger,
    ]);
    $boot['container']->registerService(Kernel::class, $kernel);
    $psr17 = new Psr17Factory();

    return [$kernel, $psr17, new RequestHandler($kernel, $psr17), new ExceptionMiddleware($psr17, $logger, (bool) ($config['app']['debug'] ?? false))];
};

// Fingerprint BEFORE booting: a write that lands while the kernel boots reads as a change.
$definition = $knowsItsDefinition ? KernelDefinition::before($root) : null;
$booted = $knowsItsDefinition ? $boot() : null;
$stale = null;

$handle = static function () use (&$booted, &$stale, $boot, $definition): void {
    [$kernel, $psr17, $handler, $failures] = $booted ?? $boot();
    $request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

    if ($definition !== null && ($stale = $definition->staleBecause()) !== null) {
        (new ResponseEmitter())->emit($definition::retryHere($request, $psr17));

        return;
    }

    $response = $failures->process($request, new class ($kernel, $handler) implements \Psr\Http\Server\RequestHandlerInterface {
        public function __construct(private readonly Kernel $kernel, private readonly RequestHandler $handler)
        {
        }

        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return IdentityChain::fromContainer($this->kernel->container())->handle($request, $this->handler);
        }
    });
    (new ResponseEmitter())->emit($response);

    $stale = $definition?->staleBecause();
};

do {
    $keep = \frankenphp_handle_request($handle);
    gc_collect_cycles();
} while ($keep && $stale === null);

if ($stale !== null && $definition !== null) {
    $definition->forgetCompiled();
    error_log('[milpa] public/worker.php: ' . $stale . ' changed since this kernel booted; leaving so a clean worker starts.');
}
