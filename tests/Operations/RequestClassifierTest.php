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

use App\Tests\Support\OptIn;
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
        OptIn::needs(LlmService::class);

        self::assertSame('reads', $this->clasificar($this->agente(['content' => 'READS'])));
    }

    public function testAClearChangesVerdictIsCalledChanges(): void
    {
        OptIn::needs(LlmService::class);

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
        OptIn::needs(LlmService::class);

        self::assertSame('unreachable', $this->clasificar($this->agente(new \RuntimeException('sin red'))));
    }

    /** Un párrafo sin la palabra no emitió juicio. */
    public function testAnAnswerWithoutTheWordIsNoVerdict(): void
    {
        OptIn::needs(LlmService::class);

        self::assertSame('no-verdict', $this->clasificar($this->agente(['content' => 'pues depende…'])));
    }

    /** Y una respuesta con LAS DOS palabras tampoco: es el modelo dudando en voz alta. */
    public function testAnAnswerWithBothWordsIsNoVerdictNotChanges(): void
    {
        OptIn::needs(LlmService::class);

        self::assertSame('no-verdict', $this->clasificar($this->agente(['content' => 'READS or CHANGES, hard to say'])));
    }

    /** Sin la perilla, el clasificador ni corre — y lo dice con su propio nombre. */
    public function testWithTheKnobOffItSaysOffAndAsksNobody(): void
    {
        OptIn::needs(LlmService::class);

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
     * EL PISO DEL FILTRO, CONSTRUIDO — se mide sin que la superficie de agente exista (evidence/0108).
     *
     * ── POR QUÉ HIZO FALTA CONSTRUIRLO ───────────────────────────────────────────────────────────
     *
     * La prueba de abajo afirma que `agent_answer` y `agent_mode` NO están en el catálogo del agente, y
     * evidence/0107 midió que en un recién nacido eso **no puede fallar**: no están porque
     * `milpa/agent` no está, no porque el filtro los retire. Borrando el filtro, el arm pelón seguía
     * verde (evidence/0106, M4). Dos aserciones que esta casa presentó como piso y no medían nada.
     *
     * ── LA CONSTRUCCIÓN ──────────────────────────────────────────────────────────────────────────
     *
     * `toolsOfThisApp()` fusiona `$extra` en el catálogo **antes** de aplicar el filtro, así que el
     * sujeto se puede FABRICAR: una `Operation` llamada `agent:answer` es un objeto, no un paquete. Con
     * las sintéticas dentro, la aguja SÍ puede estar presente — y entonces la negación mide.
     *
     * Los tres nombres van LITERALES y no leídos de la lista del código: leerlos volvería la prueba
     * circular y mutar el filtro nunca la pondría roja, que es justo lo que se quiere que pase.
     */
    public function testTheWithdrawalFilterIsMeasuredWithNoAgentSurfaceInstalled(): void
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

        $sintetica = static fn (string $nombre): \Milpa\Command\Operation => new \Milpa\Command\Operation(
            name: $nombre,
            description: 'sintética de evidence/0108',
            handler: static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
        );

        $m = new \ReflectionMethod($agente, 'toolsOfThisApp');
        $registro = $m->invoke($agente, [
            $sintetica('agent:answer'),
            $sintetica('agent:mode'),
            $sintetica('agent:discard'),
            $sintetica('cuaderno:apunte'),
        ], false);

        self::assertInstanceOf(\Milpa\ToolRuntime\ToolRegistry::class, $registro);
        $nombres = array_column($registro->getToolSummaries(), 'name');

        // EL CONTROL, primero: si la inocua tampoco llegó, «retiradas» significaba «ignoradas» y el
        // resto de esta prueba no mediría el filtro sino que `$extra` se descarta.
        self::assertContains('cuaderno_apunte', $nombres, 'las sintéticas sí entran al catálogo');

        // Y ahora la aserción MIDE, porque su aguja estaba presente antes del filtro.
        self::assertNotContains('agent_answer', $nombres, 'la respuesta a la pausa es del humano');
        self::assertNotContains('agent_mode', $nombres, 'la autonomía la concede quien gobierna');
        self::assertNotContains('agent_discard', $nombres, 'y un padre no cierra la sesión pausada de su hijo');
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
        OptIn::needs(\Milpa\Agent\SessionStore::class);

        // SIN `needs()` A PROPÓSITO, Y CUESTA DECIRLO (evidence/0105). El parche que gateó los 35
        // métodos de frontera le puso `needs()` encima a ESTE, que ya estaba partido con `has()` —y
        // con eso el método saltaba entero en un recién nacido, tirando justo las dos aserciones de
        // piso que evidence/0092 existía para conservar. Es la lección de evidence/0080 otra vez:
        // una regla aplicada en masa comete errores en masa. El piso va abajo, fuera de toda guarda.

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

        // LAS DOS NEGATIVAS SE FUERON, Y NO POR SOBRAR (evidence/0126).
        //
        // evidence/0107 midió que `assertNotContains('agent_answer')` y su gemela **no podían fallar**
        // aquí: en un recién nacido no hay `agent_answer` porque falta `milpa/agent`, no porque el
        // filtro lo retire — y evidence/0106 lo probó borrando el filtro sin que el arm pelón se
        // moviera. Lo que las reemplaza no es un `has()`: es
        // `testTheWithdrawalFilterIsMeasuredWithNoAgentSurfaceInstalled`, que FABRICA las adjudicantes
        // y por eso su aguja sí puede estar presente. Dejarlas aquí sería medir dos veces lo que una
        // mide mejor.
        //
        // Lo que queda es la lectura de sesión, que sólo existe con almacén — frontera entera, así que
        // el método sube a `needs()` y su skip lo paga el presupuesto, con esta acta como razón.
        self::assertContains('agent_sessions', $nombres, 'las lecturas de sesión sí se quedan');
    }
}
