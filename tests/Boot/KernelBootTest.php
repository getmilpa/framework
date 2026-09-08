<?php

declare(strict_types=1);

namespace App\Tests\Boot;

use App\Plugins\HelloPlugin\HelloPlugin;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton's own boot smoke test: the exact thing `composer create-project` + `php -S` +
 * `curl /` proves manually, pinned as a real assertion — the kernel boots from
 * `config/plugins.php`'s configured plugin list, `GET /` dispatches to `HomeController::index()`,
 * and the `config/app.php` greeting reaches the page through `Milpa\Runtime\Config`, zero database.
 */
final class KernelBootTest extends TestCase
{
    public function testTheKernelBootsWithTheConfiguredPluginList(): void
    {
        $kernel = Kernel::boot($this->bootConfig());

        /** @var list<class-string> $configuredPlugins */
        $configuredPlugins = $this->bootConfig()['plugins'];

        $this->assertContains(HelloPlugin::class, $configuredPlugins);
        $this->assertSame($configuredPlugins, \array_map(static fn (object $p): string => $p::class, $kernel->plugins()));
        $this->assertContains('HelloPlugin', $kernel->bootedPluginNames());
    }

    public function testGetSlashDispatchesToTheHomeControllerAndReturns200(): void
    {
        $kernel = Kernel::boot($this->bootConfig());
        $handler = new RequestHandler($kernel, new Psr17Factory());

        $response = $handler->handle(new ServerRequest('GET', '/'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('Milpa is running', $body);
        $this->assertStringContainsString('Your first five minutes', $body);
    }

    /**
     * Every command the welcome page recommends has to exist in this app.
     *
     * The page used to be pinned by two `assertStringContainsString('php bin/coa wow', …)` calls,
     * and it stayed green for as long as the page kept saying `wow` — which no version of this app
     * has ever offered. Five of the six commands under «Your first five minutes» did not exist:
     * `wow`, `doctor` before the dev tools arrive, `inspect:routes`, `inspect:tools`,
     * `make:controller` and `agent:enable`. A test that asserts the text is a test that guarantees
     * the text, right or wrong.
     *
     * So the expectation is not written here and it is not looked up in a registry either: each
     * recommended command is RUN, exactly as a reader would run it. Half of what `coa` answers to
     * are declared operations and half are built into the console — `list`, `shell`, `doctor` —
     * and a check that consults only the operation registry reports the built-ins missing. Asking
     * the registry is asking a list; asking the process is asking the app.
     */
    public function testEveryCommandTheWelcomePageRecommendsExists(): void
    {
        $kernel = Kernel::boot($this->bootConfig());
        $handler = new RequestHandler($kernel, new Psr17Factory());

        $body = (string) $handler->handle(new ServerRequest('GET', '/'))->getBody();

        // `&#10;` is the newline the page uses inside <pre>, so commands are separated by it.
        $plain = \str_replace('&#10;', "\n", $body);
        \preg_match_all('/php bin\/coa ([a-z][a-z0-9:._-]*)/', $plain, $matches);

        /** @var list<string> $recommended */
        $recommended = \array_values(\array_unique($matches[1]));
        $this->assertNotEmpty($recommended, 'the page stopped recommending anything at all');

        // THE CONTROL, FIRST: a name no app offers has to come back rejected. Without this the
        // whole test degrades into "nothing looked like a rejection", which is also what a broken
        // detector says — and a detector that cannot fail proves every page correct.
        $this->assertTrue(
            $this->isRejected('definitely-not-a-command'),
            'the rejection detector no longer detects rejections, so this test proves nothing',
        );

        foreach ($recommended as $name) {
            $this->assertFalse(
                $this->isRejected($name),
                "the welcome page tells the reader to run `php bin/coa {$name}`, and this app "
                . 'answers that no such command exists. Either the page is wrong or the command '
                . 'was removed.',
            );
        }
    }

    /** @var list<string>|null the names `coa` lists, read once per test run */
    private static ?array $offered = null;

    /**
     * Whether the app refuses to recognise `$command` — read from what `coa` LISTS, never by running it.
     *
     * The page now recommends `serve`, which hands the process to a server and returns only when that
     * server stops; a probe that executed each recommended command would hang on it. The catalogue `coa`
     * prints without arguments is the same set the dispatcher recognises, so listing is the honest probe.
     */
    private function isRejected(string $command): bool
    {
        if (self::$offered === null) {
            $root = \dirname(__DIR__, 2);
            $process = \proc_open(
                [\PHP_BINARY, $root . '/bin/coa'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
            );
            if (!\is_resource($process)) {
                self::fail('could not run bin/coa to read the commands it offers');
            }
            $output = (string) \stream_get_contents($pipes[1]) . (string) \stream_get_contents($pipes[2]);
            \array_map('fclose', $pipes);
            \proc_close($process);

            // Each offered command is one indented line: two spaces, the name, then its description.
            \preg_match_all('/^  ([a-z][a-z0-9:._-]*)\s{2,}/mu', $output, $matches);
            self::$offered = \array_values(\array_unique($matches[1]));
            self::assertNotEmpty(self::$offered, 'coa listed no commands at all: ' . $output);
        }

        return !\in_array($command, self::$offered, true);
    }

    public function testTheConfigBagGreetingReachesThePage(): void
    {
        $root = \dirname(__DIR__, 2);
        /** @var array<string, mixed> $appConfig */
        $appConfig = require $root . '/config/app.php';
        $greeting = (new \Milpa\Runtime\Config($appConfig))->get('app.greeting');
        $this->assertIsString($greeting);

        $kernel = Kernel::boot($this->bootConfig());
        $handler = new RequestHandler($kernel, new Psr17Factory());

        $response = $handler->handle(new ServerRequest('GET', '/'));

        // The greeting the page renders is the one HelloPlugin::boot() read from config/app.php,
        // not a hard-coded string — proof the Config seam is wired end to end.
        $this->assertStringContainsString('<h1>' . $greeting . '</h1>', (string) $response->getBody());
    }

    public function testAnUnmatchedPathReturns404(): void
    {
        $kernel = Kernel::boot($this->bootConfig());
        $handler = new RequestHandler($kernel, new Psr17Factory());

        $response = $handler->handle(new ServerRequest('GET', '/does-not-exist'));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Boots exactly the way `public/index.php` and `bin/coa` do — through `config/boot.php`,
     * which resolves the container and the plugins that actually boot together. Assembling those
     * two separately here would test an app no entry point produces.
     *
     * @return array{root: string, plugins: list<class-string>, config: array<string, mixed>, container: \Milpa\Interfaces\Di\DIContainerInterface}
     */
    private function bootConfig(): array
    {
        $root = \dirname(__DIR__, 2);
        /** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
        $boot = require $root . '/config/boot.php';
        /** @var array<string, mixed> $config */
        $config = require $root . '/config/app.php';

        return ['root' => $root, 'plugins' => $boot['plugins'], 'config' => $config, 'container' => $boot['container']];
    }
}
