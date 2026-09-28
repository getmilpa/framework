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

use Milpa\AppRuntime\Config\SecretOverlay;
use Milpa\AppRuntime\Support\KernelDefinition;
use PHPUnit\Framework\TestCase;

/**
 * `public/worker.php` does not serve with a kernel whose definition changed (greenhouse decisions/0505).
 *
 * EXECUTED, not read: the worker runs in a child process where `frankenphp_handle_request()` is a stub
 * that plays a list of requests and records what each one answered. Measured in evidence/1035: the naive
 * worker kept serving 404 for a plugin installed through it and ran turns without the model written
 * through it. The change used here is the secret overlay appearing — the file `provider:declare` writes.
 */
final class TheWorkerLeavesWhenItsKernelWentStaleTest extends TestCase
{
    private string $root;

    private string $secrets;

    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 2);
        $this->secrets = $this->root . SecretOverlay::RUTA;
        if (is_file($this->secrets)) {
            $this->previous = (string) file_get_contents($this->secrets);
            unlink($this->secrets);
        }
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            file_put_contents($this->secrets, $this->previous);
        } elseif (is_file($this->secrets)) {
            unlink($this->secrets);
        }
    }

    /** Nothing changed: one kernel serves every request, and the worker stays in its loop. */
    public function testASteadyWorkerServesEveryRequestAndStays(): void
    {
        $run = $this->worker(['steady', 'steady', 'steady', 'steady']);

        self::assertSame([200, 200, 200, 200], array_column($run['served'], 'status'), $run['log']);
        self::assertSame(5, $run['calls'], 'the loop asked for every request, and once more when the plan ended');
        self::assertStringNotContainsString('changed since this kernel booted', $run['log']);
    }

    /**
     * Another process changed the definition between requests: the stale worker answers 307 and leaves.
     *
     * The third request never reaches it — FrankenPHP would hand it to a clean worker.
     */
    public function testAChangeFromOutsideIsRetriedAndTheWorkerLeaves(): void
    {
        if (!class_exists(KernelDefinition::class)) {
            self::markTestSkipped('milpa/app-runtime without KernelDefinition: the worker boots per request (see the next test).');
        }
        $run = $this->worker(['steady', 'change-before', 'steady']);

        self::assertSame([200, 307], array_column($run['served'], 'status'), $run['log']);
        self::assertSame(2, $run['calls'], 'the stale worker took no request after the one it sent back');
        self::assertStringContainsString('.milpa/secrets.json changed since this kernel booted', $run['log']);
    }

    /** This request changed the definition (installed, promoted, wrote config): it is served, and the worker leaves after it. */
    public function testAChangeMadeByTheRequestItServedEndsTheWorkerAfterIt(): void
    {
        if (!class_exists(KernelDefinition::class)) {
            self::markTestSkipped('milpa/app-runtime without KernelDefinition: the worker boots per request (see the next test).');
        }
        $run = $this->worker(['steady', 'change-during', 'steady']);

        self::assertSame([200, 200], array_column($run['served'], 'status'), $run['log']);
        self::assertSame(2, $run['calls'], 'no request after the one that changed the house');
    }

    /** Without a runtime that knows its definition, the worker boots per request: slower, never stale. */
    public function testWithoutKernelDefinitionTheWorkerBootsPerRequest(): void
    {
        if (class_exists(KernelDefinition::class)) {
            self::markTestSkipped('milpa/app-runtime has KernelDefinition; the fallback is not reachable here.');
        }
        $run = $this->worker(['steady', 'change-before', 'steady']);

        self::assertSame([200, 200, 200], array_column($run['served'], 'status'), $run['log']);
        self::assertStringContainsString('booting per request', $run['log']);
    }

    /**
     * Run public/worker.php with a stub loop that plays `$plan`, one entry per request.
     *
     * `change-before` writes the secret overlay before the request arrives (another process did it);
     * `change-during` writes it while the response is being emitted (this request did it).
     *
     * @param list<string> $plan
     *
     * @return array{served: list<array{status: int|bool}>, calls: int, log: string}
     */
    private function worker(array $plan): array
    {
        $script = sys_get_temp_dir() . '/milpa-fw-worker-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP_'
            <?php
            // The stub FrankenPHP: plays the plan, records one line per request, stops when the plan ends.
            function frankenphp_handle_request(callable $handler): bool
            {
                static $n = 0;
                $plan = json_decode((string) getenv('WORKER_PLAN'), true);
                $secrets = getenv('WORKER_ROOT') . '/.milpa/secrets.json';
                $step = $plan[$n] ?? null;
                ++$n;
                file_put_contents((string) getenv('WORKER_CALLS'), (string) $n);
                if ($step === null) {
                    return false;
                }
                if ($step === 'change-before') {
                    file_put_contents($secrets, '{}');
                }
                $_SERVER['REQUEST_URI'] = '/';
                $_SERVER['REQUEST_METHOD'] = 'GET';
                $_SERVER['HTTP_HOST'] = 'localhost';
                http_response_code(200);
                ob_start(static function (string $out) use ($step, $secrets): string {
                    if ($step === 'change-during' && !is_file($secrets)) {
                        file_put_contents($secrets, '{}');
                    }

                    return '';
                }, 1); // chunk size 1: the callback runs as the response is emitted, INSIDE the request
                $handler();
                ob_end_clean();
                fwrite(STDOUT, '@@served ' . json_encode(['status' => http_response_code()]) . "\n");

                return true;
            }
            require getenv('WORKER_ROOT') . '/public/worker.php';
            PHP_);
        $calls = $script . '.calls';
        $env = 'WORKER_ROOT=' . escapeshellarg($this->root) . ' WORKER_CALLS=' . escapeshellarg($calls)
            . ' WORKER_PLAN=' . escapeshellarg((string) json_encode($plan));
        try {
            exec($env . ' ' . escapeshellarg(\PHP_BINARY) . ' -d error_log= ' . escapeshellarg($script) . ' 2>&1', $out, $code);
            $count = (int) @file_get_contents($calls);
        } finally {
            @unlink($script);
            @unlink($calls);
        }
        $log = implode("\n", $out);
        self::assertSame(0, $code, $log);

        $served = [];
        foreach ($out as $line) {
            if (str_starts_with($line, '@@served ')) {
                /** @var array{status: int|bool} $row */
                $row = json_decode(substr($line, 9), true);
                $served[] = $row;
            }
        }

        return ['served' => $served, 'calls' => $count, 'log' => $log];
    }
}
