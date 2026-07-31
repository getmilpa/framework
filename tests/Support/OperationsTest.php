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

namespace App\Tests\Support;

use App\Operations\AgentOperations;
use App\Support\Operations;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Las dos formas de preguntar qué declara esta app, y que digan lo mismo.
 *
 * Hay dos porque las superficies preguntan en momentos distintos: la terminal después de arrancar
 * (tiene el kernel) y HTTP durante el arranque (todavía no existe, y las rutas se arman ahí). Dos
 * respuestas a la misma pregunta es como se llega a que `coa` ofrezca algo que la web no — así que lo
 * que esta prueba cuida es que coincidan sobre lo declarado.
 */
final class OperationsTest extends TestCase
{
    private static ?Kernel $kernel = null;

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** El mismo kernel arrancado, compartido — arrancar cuesta y la app no cambia entre pruebas. */
    public static function bootedKernel(): Kernel
    {
        return (new self('bootedKernel'))->kernel();
    }

    private function kernel(): Kernel
    {
        if (self::$kernel !== null) {
            return self::$kernel;
        }

        /** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
        $boot = require $this->root() . '/config/boot.php';
        /** @var array<string, mixed> $config */
        $config = require $this->root() . '/config/app.php';

        $kernel = Kernel::boot([
            'root' => $this->root(),
            'plugins' => $boot['plugins'],
            'config' => $config,
            'container' => $boot['container'],
            'toolRegistry' => new ToolRegistry(new NullLogger()),
        ]);
        $boot['container']->registerService(Kernel::class, $kernel);

        return self::$kernel = $kernel;
    }

    /**
     * Lo que se declara y lo que arranca coinciden sobre los plugins declarados.
     *
     * No es una igualdad total a propósito: un plugin instalado en tiempo de ejecución aparece en la
     * lista del kernel y no en la declarada, y eso está dicho en `Operations::declared()`. Lo que no
     * puede pasar es que lo DECLARADO no llegue al arranque.
     */
    public function testWhatIsDeclaredAlsoBootsWithTheSameNames(): void
    {
        $kernel = $this->kernel();
        /** @var list<class-string> $declarados */
        $declarados = require $this->root() . '/config/plugins.php';

        $delArranque = array_map(static fn ($op): string => $op->name, Operations::all($kernel, $this->root()));
        $declaradas = array_map(
            static fn ($op): string => $op->name,
            Operations::declared($kernel->container(), $declarados, $this->root()),
        );

        self::assertNotSame([], $declaradas);
        foreach ($declaradas as $nombre) {
            self::assertContains($nombre, $delArranque, "«{$nombre}» se declara y no llega al arranque");
        }
    }

    /** La app declara las operaciones de plugins y las de devtools, y `coa` las ve todas. */
    public function testTheAppOffersThePluginAndDevtoolsAtoms(): void
    {
        $nombres = array_map(static fn ($op): string => $op->name, Operations::all($this->kernel(), $this->root()));

        foreach (['plugins.list', 'plugins.deps', 'plugins.simulate', 'validate', 'make', 'agent'] as $esperada) {
            self::assertContains($esperada, $nombres);
        }
    }

    /**
     * El agente ve exactamente las herramientas que un cliente MCP: los átomos de esta app.
     *
     * Un segundo catálogo de «herramientas del agente» sería el mismo defecto que dos gestores de
     * plugins — dos fuentes de verdad que se separan sin que nadie lo note.
     */
    public function testTheAgentSeesTheSameToolsAnMcpClientWould(): void
    {
        $kernel = $this->kernel();

        $agente = new AgentOperations($kernel->container());
        $reflexion = new \ReflectionMethod($agente, 'toolsOfThisApp');
        /** @var ToolRegistry|null $registry */
        $registry = $reflexion->invoke($agente);

        self::assertInstanceOf(ToolRegistry::class, $registry);
        $herramientas = array_map(
            static fn ($d): string => $d->name,
            $registry->getToolDefinitions(),
        );

        self::assertContains('plugins_list', $herramientas);
        self::assertNotContains('agent', $herramientas, 'un agente que se ofrece a sí mismo es un bucle que nadie pidió');
    }
}
