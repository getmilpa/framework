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

use Milpa\AppRuntime\Support\Operations;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Every operation provider milpa/app-runtime ships is either declared in `config/operations.php`, or
 * named here with the reason it is not.
 *
 * ── WHY IT EXISTS: THE THIRD TIME ───────────────────────────────────────────────────────────────
 *
 * `make`, run by a governed session, scaffolds into a trial workspace and answers «apply it with
 * `sandbox:promote`». `TrialOperations` offers that — and this skeleton never declared it. So the
 * scaffolder named a command the app did not have. Measured three times: greenhouse decisions/0227
 * («no cableadas en el skeleton pero sí en tres apps de laboratorio»), decisions/0331 (two authoring
 * attempts invalidated, «a check of the whole catalogue» added — to the LAB mount), and evidence/0994,
 * where the local resident removed every other obstacle and stopped on exactly this, naming it as a
 * house debt. Each time it was patched where it was found and never here.
 *
 * So this does not check `TrialOperations` by name. It checks the SHAPE of the defect: a provider
 * the runtime ships that nobody decided about. The next one fails here, in this repository, instead
 * of in somebody's session three weeks later.
 *
 * @guards every milpa/app-runtime operation provider is declared or excused in writing
 *
 * @fires  on every test run of the skeleton
 *
 * @refuses a provider the runtime ships that this list neither declares nor excuses
 *
 * @subject-in milpa/framework
 */
final class EveryProviderTheRuntimeShipsIsDeclaredOrExcusedTest extends TestCase
{
    /**
     * Providers that are deliberately NOT in `config/operations.php`, each with its reason.
     *
     * A reason is required rather than a bare list so that «left out» and «forgotten» cannot look
     * the same: an entry here is a decision somebody can read, argue with and undo.
     */
    private const EXCUSED = [
        'Milpa\AppRuntime\Operations\PresentationOverrideOperations' => 'Wired by LivePlugin, whose constructor '
            . 'hands it a PresentationOverrideStore; a config line would construct it with a container and fail. '
            . 'It arrives with the live wire, exactly like ScreenOperations.',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 2);
    }

    public function testEveryProviderIsDeclaredOrExcused(): void
    {
        /** @var list<class-string> $declared */
        $declared = require $this->root . '/config/operations.php';

        $undecided = [];
        foreach ($this->providersTheRuntimeShips() as $provider) {
            if (\in_array($provider, $declared, true) || \array_key_exists($provider, self::EXCUSED)) {
                continue;
            }
            $undecided[] = $provider;
        }

        $this->assertSame(
            [],
            $undecided,
            "milpa/app-runtime ships these operation providers and this skeleton neither declares nor excuses them:\n  "
                . implode("\n  ", $undecided)
                . "\nDeclare each in config/operations.php, or add it to EXCUSED with the reason it is absent.",
        );
    }

    public function testAnExcuseNamesAProviderThatStillExists(): void
    {
        // An excuse outliving its provider is the same silence one step later: it reads as a
        // decision about something that is no longer there.
        foreach (array_keys(self::EXCUSED) as $provider) {
            $this->assertContains($provider, $this->providersTheRuntimeShips(), "EXCUSED names «{$provider}», which the runtime no longer ships");
        }
    }

    public function testTheDoorBackFromATrialIsOffered(): void
    {
        // The direct pin on the defect of evidence/0994: the command `make`'s guidance names exists.
        $this->assertContains('sandbox:promote', $this->offered(), '`make` tells its caller to promote with sandbox:promote');
        $this->assertContains('sandbox:list', $this->offered());
    }

    /**
     * Every concrete CommandProvider under milpa/app-runtime's `Operations` namespace, found by
     * REFLECTION rather than by grepping its source — a class that implements the interface through
     * a parent, or declares it on a line a pattern did not expect, still counts.
     *
     * @return list<class-string>
     */
    private function providersTheRuntimeShips(): array
    {
        $dir = $this->root . '/vendor/milpa/app-runtime/src/Operations';
        $this->assertDirectoryExists($dir, 'milpa/app-runtime is installed');

        $found = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $class = 'Milpa\\AppRuntime\\Operations\\' . basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->implementsInterface(CommandProvider::class)) {
                continue;
            }
            $found[] = $class;
        }
        sort($found);

        // A positive control on the enumeration itself: an empty result would make every assertion
        // above pass while checking nothing.
        $this->assertContains('Milpa\AppRuntime\Operations\AgentOperations', $found, 'the enumeration sees the runtime');

        return $found;
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
