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

namespace App\Tests\Boot;

use Milpa\AppRuntime\Recipe\Recipe;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Command\Operation;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The first hour (greenhouse decisions/0216): what the skeleton ships so a newborn app can be
 * founded, grown and seen without hand edits.
 *
 * A recipe the app can actually apply, a router so `coa serve` reaches the kernel, and the boot-proof
 * control that none of it leaks an operation the app has not opted into.
 */
final class TheSkeletonShipsTheFirstHourTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 2);
    }

    public function testTheShippedRecipeIsOneTheAppCanApply(): void
    {
        $file = $this->root . '/recipes/notes.json';
        $this->assertFileExists($file);
        $decl = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decl);

        $recipe = Recipe::fromArray('notes', $decl);
        $this->assertNotNull($recipe->foundation, 'the recipe founds the house: without a foundation the agent can only read');
        $this->assertNotSame('', $recipe->foundation['domain']);
        $this->assertNotSame('', $recipe->foundation['objective']);

        // Every capability it requires is one the registry knows how to switch on — the recipe
        // never names a package `capabilities:enable` would refuse.
        $known = array_keys(Capabilities::knownOptIns());
        foreach ($recipe->capabilities as $package) {
            $this->assertContains($package, $known, "the recipe requires «{$package}», which no capability offers");
        }
        $this->assertContains('milpa/devtools', $recipe->capabilities, 'its work scaffolds, so it must switch the generators on first');

        $this->assertNotSame([], $recipe->work, 'a recipe with no work is a foundation, not a recipe');
        foreach ($recipe->work as $step) {
            $this->assertArrayHasKey('op', $step);
            $this->assertSame('make', $step['op'], 'the shipped recipe scaffolds; every other origin of work is a capability');
            $this->assertArrayHasKey('args', $step);
            foreach (['what', 'plugin', 'name'] as $required) {
                $this->assertArrayHasKey($required, $step['args'], "`make` requires `{$required}`");
            }
        }
    }

    public function testTheSkeletonOffersRecipeApplyAndNothingItDidNotOptInto(): void
    {
        $offered = $this->offered();

        $this->assertContains('recipe:apply', $offered, 'the recipe capability is wired in config/operations.php');
        $this->assertContains('capabilities:enable', $offered);
        // THE BOOT-PROOF CONTROL: opting a provider in must not leak the operations of packages the app
        // never installed — those arrive by `composer require`, not by a config line. CI installs every
        // opt-in as a dev dependency, so the assertion follows what THIS vendor holds, both ways.
        $this->assertSame(Capabilities::installed('agent'), \in_array('agent:sessions', $offered, true), 'agent:sessions is offered exactly when milpa/agent is installed');
        $this->assertSame(Capabilities::installed('identity'), \in_array('token:list', $offered, true), 'token:list is offered exactly when milpa/auth is installed');
    }

    public function testTheRouterServesARealFileItselfAndHandsTheRestToTheKernel(): void
    {
        $router = $this->root . '/public/router.php';
        $this->assertFileExists($router);

        $previous = $_SERVER['REQUEST_URI'] ?? null;
        try {
            // A real file under public/: the built-in server serves it (the router returns false).
            $_SERVER['REQUEST_URI'] = '/index.php?x=1';
            $this->assertFalse(require $router);
        } finally {
            $_SERVER['REQUEST_URI'] = $previous;
        }

        // Anything else reaches index.php — read, not executed: booting the kernel and emitting a
        // response inside a unit test is what the built-in server measurement on fresh cattle is for.
        $source = (string) file_get_contents($router);
        $this->assertStringContainsString("require __DIR__ . '/index.php';", $source);
        $this->assertStringContainsString('is_file(__DIR__ . $path)', $source);
    }

    /** @return list<string> */
    private function offered(): array
    {
        $boot = require $this->root . '/config/boot.php';
        $config = require $this->root . '/config/app.php';
        $kernel = Kernel::boot([
            'root' => $this->root,
            'plugins' => $boot['plugins'],
            'config' => $config,
            'container' => $boot['container'],
        ]);
        $boot['container']->registerService(Kernel::class, $kernel);

        return array_map(static fn (Operation $op): string => $op->name, Operations::all($kernel, $this->root));
    }
}
