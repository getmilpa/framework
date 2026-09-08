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

use Milpa\AppRuntime\Config\MachineOverlay;
use PHPUnit\Framework\TestCase;

/**
 * A configuration change made through the governed path (`config:set` → `.milpa/agent.json`) must reach
 * the HTTP entry the way it reaches `bin/coa` — one overlay, two doors (greenhouse decisions/0216, point 4).
 *
 * Measured by EXECUTING public/index.php in a child process with the overlay on disk, and reading the
 * booted kernel's config back; the control is the same run with the overlay removed.
 */
final class TheMachineOverlayReachesTheBrowserTest extends TestCase
{
    private string $root;

    private string $overlay;

    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 2);
        $this->overlay = $this->root . MachineOverlay::RUTA;
        if (is_file($this->overlay)) {
            $this->previous = (string) file_get_contents($this->overlay);
        }
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            file_put_contents($this->overlay, $this->previous);
        } elseif (is_file($this->overlay)) {
            unlink($this->overlay);
            @rmdir(\dirname($this->overlay));
        }
    }

    public function testWhatConfigSetWroteIsWhatTheHttpEntryBootsWith(): void
    {
        $marker = 'overlay-' . bin2hex(random_bytes(4));
        if (!is_dir(\dirname($this->overlay))) {
            mkdir(\dirname($this->overlay), 0o775, true);
        }
        file_put_contents($this->overlay, json_encode(['agent' => ['instructions' => $marker]], \JSON_THROW_ON_ERROR));

        $this->assertSame($marker, $this->instructionsTheHttpEntryBootsWith(), 'the overlay reached the browser\'s door');

        // THE CONTROL: the same entry, with the overlay gone, boots with what the human wrote (nothing).
        unlink($this->overlay);
        $this->assertNotSame($marker, $this->instructionsTheHttpEntryBootsWith());
    }

    /** Runs public/index.php as the built-in server would, and reads `agent.instructions` from the booted kernel. */
    private function instructionsTheHttpEntryBootsWith(): ?string
    {
        $script = sys_get_temp_dir() . '/milpa-fw-overlay-' . bin2hex(random_bytes(4)) . '.php';
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
            echo json_encode(['instructions' => $config->get('agent.instructions')]);
            PHP_);
        try {
            exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->root) . ' 2>&1', $out, $code);
        } finally {
            unlink($script);
        }
        $this->assertSame(0, $code, implode("\n", $out));
        $decoded = json_decode((string) end($out), true);
        $this->assertIsArray($decoded, implode("\n", $out));

        return \is_string($decoded['instructions'] ?? null) ? $decoded['instructions'] : null;
    }
}
