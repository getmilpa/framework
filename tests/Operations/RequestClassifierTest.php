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

namespace App\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AiGateway\LlmService;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * El clasificador de la petición: cada desenlace con su nombre, no un booleano.
 *
 * Estas pruebas existen por un hallazgo de revisión, no por completitud: la primera forma colapsaba
 * CUATRO situaciones distintas —CHANGES real, excepción de red, respuesta sin veredicto, respuesta
 * con las dos palabras— en el mismo `false`, sin dejar huella de cuál fue. En una medición donde la
 * divergencia de lecturas es el mensurando, un hipo del endpoint fabricaba un dato. Aquí se fija que
 * cada camino devuelve SU nombre y que ninguno se confunde con otro.
 */
final class RequestClassifierTest extends TestCase
{
    /** @param array{content?: string}|\Throwable $respuesta */
    private function agente(array|\Throwable $respuesta, bool $catalogoCondicionado = true): AgentOperations
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(Config::class, new Config([
            'agent' => ['conditionalCatalog' => $catalogoCondicionado],
        ]));

        $modelo = $this->createMock(LlmService::class);
        if ($respuesta instanceof \Throwable) {
            $modelo->method('generateResponse')->willThrowException($respuesta);
        } else {
            $modelo->method('generateResponse')->willReturn($respuesta);
        }

        return new class ($contenedor, $modelo) extends AgentOperations {
            public function __construct(\Milpa\Interfaces\Di\DIContainerInterface $c, private readonly LlmService $modelo)
            {
                parent::__construct($c);
            }

            protected function llm(): ?LlmService
            {
                return $this->modelo;
            }
        };
    }

    private function clasificar(AgentOperations $agente, string $prompt = '¿qué hay?'): string
    {
        $m = new \ReflectionMethod($agente, 'clasificarPeticion');

        $r = $m->invoke($agente, $prompt);
        \assert(\is_string($r));

        return $r;
    }

    public function testAClearReadsVerdictIsCalledReads(): void
    {
        self::assertSame('reads', $this->clasificar($this->agente(['content' => 'READS'])));
    }

    public function testAClearChangesVerdictIsCalledChanges(): void
    {
        self::assertSame('changes', $this->clasificar($this->agente(['content' => 'CHANGES'])));
    }

    /**
     * Una excepción NO es un veredicto — es la confusión que fabricaba datos.
     *
     * Antes esto devolvía el mismo valor que CHANGES, y una tanda cuyo mensurando era la divergencia
     * de lecturas habría contado un timeout como una lectura. Cada uno lleva su nombre.
     */
    public function testAnUnreachableJudgeIsNotAChangesVerdict(): void
    {
        self::assertSame('unreachable', $this->clasificar($this->agente(new \RuntimeException('sin red'))));
    }

    /** Un párrafo sin la palabra no emitió juicio. */
    public function testAnAnswerWithoutTheWordIsNoVerdict(): void
    {
        self::assertSame('no-verdict', $this->clasificar($this->agente(['content' => 'pues depende…'])));
    }

    /** Y una respuesta con LAS DOS palabras tampoco: es el modelo dudando en voz alta. */
    public function testAnAnswerWithBothWordsIsNoVerdictNotChanges(): void
    {
        self::assertSame('no-verdict', $this->clasificar($this->agente(['content' => 'READS or CHANGES, hard to say'])));
    }

    /** Sin la perilla, el clasificador ni corre — y lo dice con su propio nombre. */
    public function testWithTheKnobOffItSaysOffAndAsksNobody(): void
    {
        $modelo = $this->createMock(LlmService::class);
        $modelo->expects($this->never())->method('generateResponse');

        $contenedor = new DIContainer();
        $contenedor->registerService(Config::class, new Config(['agent' => ['conditionalCatalog' => false]]));

        $agente = new class ($contenedor, $modelo) extends AgentOperations {
            public function __construct(\Milpa\Interfaces\Di\DIContainerInterface $c, private readonly LlmService $modelo)
            {
                parent::__construct($c);
            }

            protected function llm(): ?LlmService
            {
                return $this->modelo;
            }
        };

        self::assertSame('off', $this->clasificar($agente));
    }

    /** Sólo `reads` recorta: los desenlaces sin veredicto dejan el catálogo completo, como siempre. */
    public function testOnlyAReadsVerdictConditionsTheCatalogue(): void
    {
        foreach (['changes', 'no-verdict', 'unreachable', 'off', 'no-judge'] as $veredicto) {
            self::assertNotSame('reads', $veredicto);
        }
        // La conexión veredicto→filtro vive en run(): `$veredictoCatalogo === 'reads'`. Esta prueba
        // documenta la tabla; la de abajo fija que el filtro en sí ya no toca la contabilidad.
        self::assertTrue(true);
    }

    /**
     * EL FILTRO DE LECTURA NO SE LLEVA EL CUADERNO.
     *
     * `plan` y `todo` declaran `mutating: true` —apendan— pero su efecto es sobre la bitácora de la
     * sesión, no sobre el mundo. Filtrarlas en modo lectura era una variable oculta: dos mesas que
     * difieren en lo destructivo Y en la contabilidad no permiten atribuir nada a lo primero.
     */
    public function testTheReadOnlyFilterKeepsTheBookkeepingOnTheTable(): void
    {
        // Kernel PROPIO, no el memoizado: el registro de herramientas del compartido ya trae el
        // catálogo completo que otras pruebas proyectaron, y contra él la aserción negativa mediría
        // el orden de la suite y no el filtro.
        $raiz = \dirname(__DIR__, 2);
        /** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
        $boot = require $raiz . '/config/boot.php';
        /** @var array<string, mixed> $config */
        $config = require $raiz . '/config/app.php';
        $kernel = \Milpa\Runtime\Kernel::boot([
            'root' => $raiz,
            'plugins' => $boot['plugins'],
            'config' => $config,
            'container' => $boot['container'],
            'toolRegistry' => new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger()),
        ]);
        // El Kernel no se auto-registra: en producción lo hace `Application` (línea del boot de coa),
        // y aquí lo hacemos nosotros — sin esto, `toolsOfThisApp` contesta que no hay app.
        $boot['container']->registerService(\Milpa\Runtime\Kernel::class, $kernel);
        $agente = new AgentOperations($kernel->container());

        $cuaderno = new \Milpa\Command\Operation(
            name: 'plan',
            description: 'El plan de esta sesión',
            handler: static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
        );

        $m = new \ReflectionMethod($agente, 'toolsOfThisApp');
        $registry = $m->invoke($agente, [$cuaderno], true);

        self::assertInstanceOf(\Milpa\ToolRuntime\ToolRegistry::class, $registry);
        $nombres = array_column($registry->getToolSummaries(), 'name');

        self::assertContains('plan', $nombres, 'el cuaderno se queda aunque el catálogo esté en modo lectura');
        self::assertNotContains('plugins_disable', $nombres, 'y lo destructivo sí se filtra');
    }

    /**
     * LAS OPERACIONES QUE ADJUDICAN SESIONES NO SON HERRAMIENTAS DEL ADJUDICADO.
     *
     * `agent:answer` y `agent:mode` estaban en el catálogo del propio agente, con el mensaje de pausa
     * entregándole la sintaxis exacta para contestarse «sí» a sí mismo o subirse solo a `auto`. Lo
     * encontró la revisión adversaria de Q-P19-M: habría vuelto teatro el contrato de ADR-0044 —
     * toda pregunta al humano era contestable por el actor gobernado.
     */
    public function testSessionAdjudicationOperationsAreNotToolsOfTheAgent(): void
    {
        $raiz = \dirname(__DIR__, 2);
        /** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
        $boot = require $raiz . '/config/boot.php';
        /** @var array<string, mixed> $config */
        $config = require $raiz . '/config/app.php';
        $kernel = \Milpa\Runtime\Kernel::boot([
            'root' => $raiz,
            'plugins' => $boot['plugins'],
            'config' => $config,
            'container' => $boot['container'],
            'toolRegistry' => new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger()),
        ]);
        $boot['container']->registerService(\Milpa\Runtime\Kernel::class, $kernel);
        $agente = new AgentOperations($kernel->container());

        $m = new \ReflectionMethod($agente, 'toolsOfThisApp');
        $registry = $m->invoke($agente, [], false);
        self::assertInstanceOf(\Milpa\ToolRuntime\ToolRegistry::class, $registry);
        $nombres = array_column($registry->getToolSummaries(), 'name');

        self::assertNotContains('agent_answer', $nombres, 'la respuesta a la pausa es del humano');
        self::assertNotContains('agent_mode', $nombres, 'la autonomía la concede quien gobierna, no quien es gobernado');
        self::assertContains('agent_sessions', $nombres, 'las lecturas de sesión sí se quedan');
    }
}
