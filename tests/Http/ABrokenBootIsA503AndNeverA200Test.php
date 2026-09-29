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

namespace App\Tests\Http;

use Milpa\AppRuntime\Support\BrokenBootAnswer;
use PHPUnit\Framework\TestCase;

/**
 * `public/index.php` answers a boot that fails with `503` and the reason — never `200` and the fatal (greenhouse decisions/0512).
 *
 * Measured on fresh cattle (greenhouse evidence/1035 F1, evidence/1039): `php -S` and FrankenPHP classic answered
 * a house that did not boot with HTTP 200 and `Fatal error: … in /home/…` in the body. Here THIS skeleton's front
 * controller runs under a real `php -S` (the router `coa serve` uses), in a copy of the skeleton whose
 * `config/app.php` is broken two ways: it throws, and it declares a class missing an interface method (a compile
 * fatal nobody can catch). The positive control is the same copy with the watch's two lines taken out.
 *
 * @guards a boot that throws or dies of a compile fatal answers 503 with `Milpa-House-Does-Not-Boot`, the reason
 *         and no absolute path; the house that boots is served 200 as before
 *
 * @refuses nothing — the unwatched front controller is the control, and it answers 200 with the fatal
 */
final class ABrokenBootIsA503AndNeverA200Test extends TestCase
{
    private string $copy = '';

    protected function setUp(): void
    {
        if (!class_exists(BrokenBootAnswer::class)) {
            self::markTestSkipped('milpa/app-runtime has no BrokenBootAnswer (it needs 0.198 or later)');
        }
        $root = \dirname(__DIR__, 2);
        $this->copy = sys_get_temp_dir() . '/milpa-fw-broken-boot-' . bin2hex(random_bytes(4));
        mkdir($this->copy . '/var', 0o777, true);
        // What the front controller reads by path; `App\` itself resolves through the linked vendor to this repository.
        foreach (['config', 'public', 'storage'] as $dir) {
            exec('cp -R ' . escapeshellarg($root . '/' . $dir) . ' ' . escapeshellarg($this->copy . '/'));
        }
        symlink($root . '/vendor', $this->copy . '/vendor');
    }

    protected function tearDown(): void
    {
        if ($this->copy !== '' && is_dir($this->copy)) {
            exec('rm -rf ' . escapeshellarg($this->copy));
        }
    }

    public function testABootThatFailsIsA503WithTheReasonAndNoPath(): void
    {
        $app = (string) file_get_contents($this->copy . '/config/app.php');
        [$server, $port] = $this->serve();
        try {
            self::assertSame(200, $this->get($port)[0], 'the house that boots is served');

            file_put_contents($this->copy . '/config/app.php', "<?php\nthrow new \\RuntimeException('broken on purpose');\n");
            [$status, $body, $headers] = $this->get($port);
            self::assertSame(503, $status, $body);
            self::assertSame('RuntimeException: broken on purpose', $headers['milpa-house-does-not-boot'] ?? null);
            self::assertStringStartsWith('This house does not boot: RuntimeException: broken on purpose', $body);
            self::assertStringNotContainsString($this->copy, $body);

            file_put_contents($this->copy . '/config/app.php', "<?php\nfinal class BrokenOnPurpose implements \\Countable {}\nreturn [];\n");
            [$status, $body, $headers] = $this->get($port);
            self::assertSame(503, $status, $body);
            self::assertStringStartsWith('Fatal error: Class BrokenOnPurpose contains 1 abstract method', $headers['milpa-house-does-not-boot'] ?? '');
            self::assertStringContainsString('in config/app.php on line 2', $body);
            self::assertStringNotContainsString($this->copy, $body);

            // POSITIVE CONTROL: the same front controller without the watch is the defect of 1035 F1.
            $index = (string) file_get_contents($this->copy . '/public/index.php');
            $bare = str_replace(['$watch?->booted();', '$watch = class_exists('], ['', '$watch = null && class_exists('], $index);
            self::assertNotSame($index, $bare);
            file_put_contents($this->copy . '/public/index.php', $bare);
            [$status, $body] = $this->get($port);
            self::assertSame(200, $status, 'unwatched, a compile fatal at boot is a 200');
            self::assertStringContainsString('Fatal error', $body);
            self::assertStringContainsString($this->copy, $body, 'and the page carries the house\'s path');

            file_put_contents($this->copy . '/public/index.php', $index);
            file_put_contents($this->copy . '/config/app.php', $app);
            self::assertSame(200, $this->get($port)[0], 'fixed, it serves again');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /** @return array{0: resource, 1: int} */
    private function serve(): array
    {
        for ($try = 0; $try < 5; ++$try) {
            $port = random_int(20000, 45000);
            $server = proc_open(
                [\PHP_BINARY, '-d', 'display_errors=1', '-d', 'opcache.enable_cli=0', '-S', '127.0.0.1:' . $port, '-t', $this->copy . '/public', $this->copy . '/public/router.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            self::assertIsResource($server);
            for ($wait = 0; $wait < 50; ++$wait) {
                usleep(40_000);
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($socket !== false) {
                    fclose($socket);

                    return [$server, $port];
                }
                if (!proc_get_status($server)['running']) {
                    break;
                }
            }
            proc_terminate($server);
            proc_close($server);
        }
        self::markTestSkipped('php -S could not bind a port here');
    }

    /** @return array{0: int, 1: string, 2: array<string, string>} */
    private function get(int $port): array
    {
        $body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]));
        $headers = [];
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return [$status, (string) $body, $headers];
    }
}
