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
            // Dos: el catalogo y la que instala. Se afirma por NOMBRE y no por posicion — contar
            // posiciones convierte agregar una operacion en romper una prueba que no la vigilaba.
            $nombres = array_map(static fn ($o): string => $o->name, $operaciones);
            self::assertContains('capabilities', $nombres);
            self::assertContains('capabilities:enable', $nombres);
        } finally {
            @unlink($raiz . '/config/operations.php');
            @rmdir($raiz . '/config');
            @rmdir($raiz);
        }
    }

    /**
     * Sin `agent.secondOpinion`, no se envuelve nada: el comportamiento es el de hoy.
     *
     * El segundo juicio se APILA, no reemplaza — y una app que no lo pidió no paga una petición extra
     * al modelo por cada llamada.
     */
    public function testWithoutTheConfigKeyNoSecondOpinionIsWired(): void
    {
        $ops = new AgentOperations(new DIContainer());
        $m = new \ReflectionMethod($ops, 'segundaOpinion');
        $m->setAccessible(true);

        self::assertSame([], $m->invoke($ops));

        // Y con la clave puesta, sale la lista — filtrando lo que no es un nombre de herramienta.
        $contenedor = new DIContainer();
        $contenedor->registerService(\Milpa\Runtime\Config::class, new \Milpa\Runtime\Config([
            'agent' => ['secondOpinion' => ['plugins_disable', 42, 'plugins_lock']],
        ]));

        $conClave = new AgentOperations($contenedor);
        $m2 = new \ReflectionMethod($conClave, 'segundaOpinion');
        $m2->setAccessible(true);

        self::assertSame(['plugins_disable', 'plugins_lock'], $m2->invoke($conClave));
    }

    /**
     * El juez es el MISMO modelo que corre al agente, no uno aparte.
     *
     * Dos modelos serían dos configuraciones que se separan sin que nadie lo note, y el día que se
     * separaran el verificador estaría juzgando con un criterio que nadie eligió. Sin credencial no
     * hay juez, y sin juez no se envuelve nada — el comportamiento vuelve a ser el de hoy.
     */
    public function testTheJudgeIsTheSameModelThatRunsTheAgent(): void
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(\Milpa\Runtime\Config::class, new \Milpa\Runtime\Config([
            'agent' => ['baseUrl' => 'https://llama.local', 'model' => 'qwen3-coder:30b'],
        ]));

        $ops = new AgentOperations($contenedor);
        $m = new \ReflectionMethod($ops, 'llm');
        $m->setAccessible(true);

        self::assertInstanceOf(\Milpa\AiGateway\LlmService::class, $m->invoke($ops));
    }

    /**
     * La alternativa observable la declara la APP, y se filtra lo que no es un par de nombres.
     *
     * Q-P19-D midió que una negativa sin «qué sí» apaga al agente: 0 de 32 corridas volvieron a llamar
     * una herramienta. Pedirle al modelo que invente la alternativa sería mover la adivinación de
     * lugar, no quitarla.
     */
    public function testTheObservableAlternativeIsDeclaredByTheApp(): void
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(\Milpa\Runtime\Config::class, new \Milpa\Runtime\Config([
            'agent' => ['observableAlternatives' => [
                'plugins_disable' => 'plugins_simulate',
                'roto' => 42,
            ]],
        ]));

        $ops = new AgentOperations($contenedor);
        $m = new \ReflectionMethod($ops, 'alternativasObservables');
        $m->setAccessible(true);

        self::assertSame(['plugins_disable' => 'plugins_simulate'], $m->invoke($ops));

        // Y sin la clave, no hay alternativa: la negativa vuelve a ser la de antes.
        $vacio = new AgentOperations(new DIContainer());
        $m2 = new \ReflectionMethod($vacio, 'alternativasObservables');
        $m2->setAccessible(true);
        self::assertSame([], $m2->invoke($vacio));
    }

    /**
     * Y el piso sigue debajo: `SecondOpinionGate` recibe la compuerta de sesión, no la sustituye.
     *
     * Es el control 3 de Q-P19-D, y es la propiedad que hace que esto siga siendo una compuerta: a un
     * modelo se le puede persuadir, así que un verificador que pudiera revertir un `no` sintáctico
     * sería una vía de escape con forma de mejora.
     */
    public function testTheSyntacticFloorStaysUnderneath(): void
    {
        $piso = new class () implements \Milpa\AiGateway\ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return 'el piso dice que no';
            }
        };

        $modelo = new class () implements \Milpa\ToolRuntime\Contracts\LlmServiceInterface {
            public int $llamadas = 0;

            public function generateResponse(string $prompt, array $tools = [], array $messages = [], int $maxTokens = 4096): array
            {
                ++$this->llamadas;

                return ['content' => 'ALLOW'];
            }
        };

        $puerta = new \Milpa\AiGateway\SecondOpinionGate($piso, $modelo, 'lo que sea', ['plugins_disable']);

        self::assertSame('el piso dice que no', $puerta->refuse('plugins_disable', []));
        self::assertSame(0, $modelo->llamadas, 'ni siquiera se le pregunta al modelo lo que el piso ya negó');
    }

    /**
     * ENVOLVER LA COMPUERTA NO PUEDE APAGAR EL REGISTRO DE HERRAMIENTAS.
     *
     * `McpClientService` deducía la grabadora del gate final. `SessionToolGate` juega los dos papeles
     * —juzga y registra—; `SecondOpinionGate` sólo juzga. Así que declarar `agent.secondOpinion` hacía
     * que la sesión dejara de apendar `session.tool_called`, en silencio.
     *
     * Por qué importa más que un evento perdido: el stream es la evidencia con la que este programa
     * distingue «observó» de «contestó sin mirar». Con el registro apagado, una corrida que usó tres
     * herramientas se ve idéntica a una que no usó ninguna — y ese cero se lee como hallazgo. Medido en
     * una corrida real el 2026-08-02: 8 pasos, datos de tres herramientas, cero llamadas en el stream.
     */
    public function testWrappingTheGateDoesNotSilenceTheToolRecorder(): void
    {
        $registro = [];
        $piso = new class ($registro) implements \Milpa\AiGateway\ToolCallGate, \Milpa\AiGateway\ToolCallRecorder {
            /** @param list<string> $registro */
            public function __construct(public array &$registro)
            {
            }

            public function refuse(string $tool, array $arguments): ?string
            {
                return null;
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->registro[] = $tool;
            }
        };

        $modelo = new class () implements \Milpa\ToolRuntime\Contracts\LlmServiceInterface {
            public function generateResponse(string $prompt, array $tools = [], array $messages = [], int $maxTokens = 4096): array
            {
                return ['content' => 'ALLOW'];
            }
        };

        // La compuerta que llega al cliente es el envoltorio, que NO es grabadora.
        $envuelta = new \Milpa\AiGateway\SecondOpinionGate($piso, $modelo, 'x', ['plugins_disable']);
        self::assertNotInstanceOf(\Milpa\AiGateway\ToolCallRecorder::class, $envuelta);

        $registry = new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger());
        $registry->register('plugins_simulate', 'simula', [], static fn (): array => ['ok' => true]);

        // El papel de registrar viaja aparte, tomado del piso antes de envolver.
        (new \Milpa\AiGateway\McpClientService($registry, $envuelta, $piso))->callTool('plugins_simulate', []);

        self::assertSame(['plugins_simulate'], $piso->registro, 'el envoltorio no puede quitarle al piso el papel de registrar');
    }
}
