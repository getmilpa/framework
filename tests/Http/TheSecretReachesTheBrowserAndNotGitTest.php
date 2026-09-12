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

use App\Tests\Support\TemporaryDirectory;
use Milpa\AppRuntime\Config\SecretOverlay;
use PHPUnit\Framework\TestCase;

/**
 * A CREDENTIAL HAS TO REACH THE REQUEST AND NEVER REACH GIT, and both halves are measured here.
 *
 * Before this destination existed an API key had two homes and both were wrong: `config/app.php`, the
 * file a person opens and git commits, or `.milpa/agent.json`, which is committed ON PURPOSE beside the
 * constitution because it is the acta trail. This template ships no `.env`, LOADS no `.env` and does not
 * ignore one — measured, not assumed (greenhouse decisions/0267).
 *
 * So `provider:declare` writes to `.milpa/secrets.json`, and this asserts the two properties that make
 * that safe: the front controller APPLIES it — last, over both the human's file and the machine's public
 * overlay — and this repository IGNORES it. Asked of `git check-ignore` rather than of the `.gitignore`
 * text: a rule can be present and shadowed by a later negation, and only the tool that decides knows.
 */
final class TheSecretReachesTheBrowserAndNotGitTest extends TestCase
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

    /** The overlay the HTTP entry applies last, measured by booting it with the file in place. */
    public function testWhatProviderDeclareWroteIsWhatTheHttpEntryBootsWith(): void
    {
        $marker = 'https://only-on-this-machine.invalid/' . bin2hex(random_bytes(4));
        if (!is_dir(\dirname($this->secrets))) {
            mkdir(\dirname($this->secrets), 0o775, true);
        }
        file_put_contents($this->secrets, json_encode(['agent' => ['baseUrl' => $marker]], \JSON_THROW_ON_ERROR));
        chmod($this->secrets, 0o600);

        self::assertSame($marker, $this->baseUrlTheHttpEntryBootsWith(), 'the secret reached the browser\'s door');

        // THE CONTROL: the same entry, with the file gone, boots without it.
        unlink($this->secrets);
        self::assertNotSame($marker, $this->baseUrlTheHttpEntryBootsWith());
    }

    /**
     * 🚨 AND GIT REFUSES TO SEE IT — asked of `git check-ignore`, the thing that decides.
     *
     * Its sibling is the control rather than a second subject: `.milpa/agent.json` MUST stay visible to
     * git, because that overlay is what a governed change left behind and it travels with the
     * repository. A rule broad enough to hide both would erase the acta trail to protect the key.
     */
    public function testGitIgnoresTheSecretAndStillSeesTheOverlayThatTravels(): void
    {
        // create-project removes .git. Ask a disposable repository using the shipped rules, so
        // neither the checkout's index nor the developer's global ignore rules decide the result.
        $repository = new TemporaryDirectory();
        try {
            copy($this->root . '/.gitignore', $repository->path . '/.gitignore');
            mkdir($repository->path . '/.milpa');
            if (is_file($this->root . '/.milpa/.gitignore')) {
                copy($this->root . '/.milpa/.gitignore', $repository->path . '/.milpa/.gitignore');
            }
            exec('git -C ' . escapeshellarg($repository->path) . ' init --quiet --template= 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));

            self::assertTrue($this->ignored($repository->path, SecretOverlay::RUTA), 'a credential in this path would be committed');
            self::assertFalse($this->ignored($repository->path, '/.milpa/agent.json'), 'and the acta trail is not hidden with it');
        } finally {
            $repository->remove();
        }
    }

    private function ignored(string $repository, string $path): bool
    {
        exec(
            'git -C ' . escapeshellarg($repository) . ' -c core.excludesFile=/dev/null check-ignore --no-index -q ' . escapeshellarg(ltrim($path, '/')) . ' 2>&1',
            $out,
            $code,
        );
        self::assertContains($code, [0, 1], 'git could not answer, and an unverifiable answer is not a yes');

        return $code === 0;
    }

    /** Runs public/index.php as the built-in server would, and reads `agent.baseUrl` from the booted kernel. */
    private function baseUrlTheHttpEntryBootsWith(): ?string
    {
        $script = sys_get_temp_dir() . '/milpa-fw-secret-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP_'
            <?php
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['HTTP_HOST'] = 'localhost';
            ob_start();
            require $argv[1] . '/public/index.php';
            ob_end_clean();
            /** @var \Milpa\Runtime\Kernel $kernel */
            $config = $kernel->container()->get(\Milpa\Runtime\Config::class);
            echo json_encode(['baseUrl' => $config->get('agent.baseUrl')]);
            PHP_);
        try {
            exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->root) . ' 2>&1', $out, $code);
        } finally {
            unlink($script);
        }
        self::assertSame(0, $code, implode("\n", $out));
        $decoded = json_decode((string) end($out), true);
        self::assertIsArray($decoded, implode("\n", $out));

        return \is_string($decoded['baseUrl'] ?? null) ? $decoded['baseUrl'] : null;
    }
}
