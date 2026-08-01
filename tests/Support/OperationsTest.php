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
use Milpa\Container\DIContainer;
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

        // `verify` y `lock` están porque `config/boot.php` registra un `AppRoot`. Sin él no
        // aparecerían —el paquete no adivina dónde vive la app— y esta lista lo mediría.
        foreach (['plugins.list', 'plugins.deps', 'plugins.simulate', 'plugins.verify', 'plugins.lock', 'validate', 'make', 'agent'] as $esperada) {
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

    /**
     * Sin `config/operations.php`, no hay operaciones de paquete — y tampoco hay error.
     *
     * Es el estado de una app recién creada que todavía no adopta nada, y la rama que hace posible el
     * opt-in. Existía y no estaba probada, que es la forma callada de que deje de funcionar.
     */
    public function testWithoutTheConfigFileThereAreSimplyNoPackageOperations(): void
    {
        $vacia = sys_get_temp_dir() . '/milpa-ops-' . bin2hex(random_bytes(6));
        mkdir($vacia . '/config', 0o775, true);

        try {
            self::assertSame([], Operations::declared(new DIContainer(), [], $vacia));
        } finally {
            @rmdir($vacia . '/config');
            @rmdir($vacia);
        }
    }

    /**
     * Una clase declarada que NO está instalada se salta, no truena.
     *
     * Es la degradación que hace posible crecer por opt-in: quien escribió la lista afirmó una
     * intención, y una intención que todavía no se puede cumplir no debería impedir arrancar. Pasó de
     * verdad cuando `DevToolsOperations` nació después de `milpa/devtools 0.8.0`.
     */
    public function testADeclaredClassThatIsNotInstalledIsSkippedAndAnInstalledOneContributes(): void
    {
        $raiz = sys_get_temp_dir() . '/milpa-ops-' . bin2hex(random_bytes(6));
        mkdir($raiz . '/config', 0o775, true);
        file_put_contents(
            $raiz . '/config/operations.php',
            "<?php\n\nreturn ['Milpa\\\\NoExiste\\\\Operaciones', 'App\\\\Operations\\\\CapabilityOperations'];\n",
        );

        try {
            $operaciones = Operations::declared(new DIContainer(), [], $raiz);

            // La ausente se salta Y la presente contribuye: sin la segunda mitad, un cero no
            // distinguiría «se saltó lo que falta» de «no junta nada nunca».
            self::assertCount(1, $operaciones);
            self::assertSame('capabilities', $operaciones[0]->name);
        } finally {
            @unlink($raiz . '/config/operations.php');
            @rmdir($raiz . '/config');
            @rmdir($raiz);
        }
    }
}
