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
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * El agente de esta app, y sobre todo lo que hace cuando NO puede correr.
 *
 * `milpa/ai-gateway` venía instalado con el framework desde la 0.1.0 y nadie lo llamaba: estaba en el
 * árbol de dependencias y no existía para quien creaba una app. Lo que se prueba aquí es la línea que
 * faltaba y, más importante, que sin llave diga qué falta en vez de contestar algo plausible — un
 * agente que responde sin haber preguntado a nadie es peor que uno que no arranca.
 */
final class AgentOperationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $entornoPrevio = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Sin esto, una máquina con la llave exportada saldría a la red desde una prueba unitaria —
        // y pasaría o fallaría según quién la corra.
        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY'] as $var) {
            $this->entornoPrevio[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->entornoPrevio as $var => $valor) {
            if (\is_string($valor)) {
                putenv("{$var}={$valor}");
            }
        }
        parent::tearDown();
    }

    private function operacion(): Operation
    {
        return $this->operacionDe(new AgentOperations(new DIContainer()));
    }

    /**
     * `agent`, ASKED FOR BY NAME — y antes se pedía por posición.
     *
     * El grupo tenía una sola operación, así que `[0]` era `agent` por accidente de aritmética.
     * Desde `app-runtime` 0.11 también aporta `agent:catalogue`, que NO está detrás de la misma
     * guarda a propósito: fundir dos capacidades en una guarda hace que la instalada conteste por
     * la que falta.
     *
     * Un índice posicional dentro de un grupo que crece no falla: **devuelve la operación
     * equivocada y sigue verde**. Sin `milpa/agent` este ayudante ya estaba entregando
     * `agent:catalogue` a pruebas escritas sobre `agent`, y lo único que lo tapaba era que esos
     * casos saltan sin el paquete. Pedir por nombre convierte el crecimiento del grupo en un
     * fallo que dice cuál falta, en vez de en una prueba que mide otra cosa.
     *
     * ── Y EL OPT-IN VIVE AQUÍ, NO EN CADA CASO ──────────────────────────────────────────────────
     *
     * `agent` sólo se declara cuando la capacidad `agent-runs` está instalada, y quien la provee es
     * `milpa/ai-gateway` — no `milpa/agent`, que es la que el nombre sugiere. Cinco casos de este
     * archivo pedían opt-in por las sesiones y el almacén de eventos y **no por la compuerta que de
     * verdad hace existir la operación**, así que en una instalación con el agente y sin el gateway
     * corrían y fallaban.
     *
     * Se declara en el ayudante porque **el requisito es del valor que devuelve**, no del caso que
     * lo pide: puesto en cada caso, el sexto que se escriba lo va a olvidar igual que lo olvidaron
     * estos cinco.
     */
    private function operacionDe(AgentOperations $proveedor): Operation
    {
        OptIn::needs(\Milpa\AiGateway\OptionTable::class);

        foreach ($proveedor->operations() as $operacion) {
            if ($operacion->name === 'agent') {
                return $operacion;
            }
        }

        self::fail('el grupo del agente ya no ofrece la operación «agent»');
    }

    /**
     * Un agente con llave, con herramientas, y sin red: la única parte que sale a internet está
     * sustituida.
     *
     * @param \Closure(string): string $responder
     */
    private function agenteQueContesta(\Closure $responder): AgentOperations
    {
        putenv('ANTHROPIC_API_KEY=llave-de-prueba');

        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();

        return new class ($kernel->container(), $responder) extends AgentOperations {
            /** @param \Closure(string): string $responder */
            public function __construct(\Milpa\Interfaces\Di\DIContainerInterface $container, private readonly \Closure $responder)
            {
                parent::__construct($container);
            }

            protected function ask(
                string $prompt,
                int $pasos,
                \Milpa\ToolRuntime\ToolRegistry $registry,
                string $proveedor,
                string $llave,
                string $modelo,
                callable $onStep,
                array $history = [],
                ?\Milpa\AiGateway\ToolCallGate $gate = null,
                ?\Milpa\AiGateway\OptionTable $mesa = null,
                ?\Milpa\AiGateway\ToolCallRecorder $recorder = null,
                ?\Milpa\AiGateway\PlanBoard $tablero = null,
            ): string {
                $onStep();
                $onStep();

                return ($this->responder)($prompt);
            }
        };
    }

    /**
     * Con llave y con herramientas, la respuesta del agente ES el resultado — y viene con cuántos
     * pasos dio y cuántas herramientas tenía.
     *
     * Los dos números no son adorno: sin ellos, «el agente contestó» no distingue entre haber usado
     * las herramientas de esta app y haber contestado de memoria.
     */
    public function testTheAnswerComesBackWithTheStepsItTookAndTheToolsItHad(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $agente = $this->agenteQueContesta(static fn (string $p): string => "contesté a: {$p}");
        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, answer?: string, steps?: int, tools?: int} $r */
        $r = $handler(['prompt' => 'lista los plugins']);

        self::assertTrue($r['ok']);
        self::assertSame('contesté a: lista los plugins', $r['answer']);
        self::assertSame(2, $r['steps']);
        self::assertGreaterThan(0, $r['tools'], 'un agente sin herramientas no puede hacer nada con esta app');
    }

    /**
     * Si el proveedor truena, el motivo llega TAL CUAL.
     *
     * Viene del otro lado —una llave inválida, un modelo que no existe, la red— y quien lo lee
     * necesita esa frase para saber qué arreglar; reformularla la empeora.
     */
    public function testAProviderFailureComesBackVerbatim(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $agente = $this->agenteQueContesta(static function (string $p): string {
            throw new \RuntimeException('Anthropic API Error: HTTP 401 Unauthorized');
        });
        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string} $r */
        $r = $handler(['prompt' => 'lo que sea']);

        self::assertFalse($r['ok']);
        self::assertSame('Anthropic API Error: HTTP 401 Unauthorized', $r['error']);
    }

    /**
     * Con OPENAI_API_KEY y sin la de Anthropic, el proveedor que se usa es OpenAI — y el modelo sale
     * de `config/app.php` si lo declara.
     *
     * Importa porque la llave y el modelo tienen que viajar juntos: una llave de OpenAI con el modelo
     * default de Anthropic falla del otro lado, con un mensaje que no dice que la culpa fue de aquí.
     */
    public function testTheProviderFollowsWhichKeyIsPresent(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        putenv('OPENAI_API_KEY=llave-openai');

        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();
        // La captura va por propiedad ESTÁTICA: una readonly no se puede escribir después de
        // construirse, y una referencia a un array local tampoco (`Cannot indirectly modify`).
        $agente = new class ($kernel->container()) extends AgentOperations {
            /** @var array<string, string> */
            public static array $visto = [];

            protected function ask(
                string $prompt,
                int $pasos,
                \Milpa\ToolRuntime\ToolRegistry $registry,
                string $proveedor,
                string $llave,
                string $modelo,
                callable $onStep,
                array $history = [],
                ?\Milpa\AiGateway\ToolCallGate $gate = null,
                ?\Milpa\AiGateway\OptionTable $mesa = null,
                ?\Milpa\AiGateway\ToolCallRecorder $recorder = null,
                ?\Milpa\AiGateway\PlanBoard $tablero = null,
            ): string {
                static::$visto = ['proveedor' => $proveedor, 'llave' => $llave, 'modelo' => $modelo];

                return 'ok';
            }
        };

        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);
        $handler(['prompt' => 'algo']);

        $visto = $agente::$visto;
        self::assertSame('openai', $visto['proveedor']);
        self::assertSame('llave-openai', $visto['llave']);
        self::assertSame('gpt-4o', $visto['modelo'], 'sin `agent.model` declarado, el default del proveedor');
    }

    /**
     * Sin kernel en el contenedor no hay catálogo de herramientas, y se dice.
     *
     * Es el caso de una app que llama a esta operación fuera de su terminal: sin herramientas, un
     * agente no puede hacer nada con la app, y contestar «no tengo herramientas» es más útil que
     * contestar de memoria.
     */
    public function testWithoutAKernelThereAreNoToolsAndItSaysSo(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\EventStore\InMemoryEventStore::class);

        putenv('ANTHROPIC_API_KEY=llave-de-prueba');

        $handler = $this->operacion()->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string} $r */
        $r = $handler(['prompt' => 'algo']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('herramienta', (string) $r['error']);
    }

    /**
     * Sin llave configurada dice QUÉ falta y CÓMO ponerla, y no inventa una respuesta.
     *
     * Es la mitad que importa de esta operación: un modo demo que contesta algo verosímil sin haber
     * llamado a ningún modelo enseña a confiar en respuestas que nadie produjo.
     */
    public function testWithoutAKeyItSaysWhatIsMissingInsteadOfPretending(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\EventStore\InMemoryEventStore::class);

        $handler = $this->operacion()->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string, hint?: string, answer?: string} $r */
        $r = $handler(['prompt' => 'lista los plugins']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('API key', (string) $r['error']);
        self::assertStringContainsString('ANTHROPIC_API_KEY', (string) $r['hint']);
        self::assertArrayNotHasKey('answer', $r, 'sin llave no hay respuesta que dar');
    }

    /** Sin prompt no se le pregunta nada a nadie. */
    public function testWithoutAPromptItRefusesBeforeReachingAnybody(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\EventStore\InMemoryEventStore::class);

        $handler = $this->operacion()->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string} $r */
        $r = $handler([]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('prompt', (string) $r['error']);
    }

    /**
     * MUTA y NO pide firma, a propósito.
     *
     * El agente puede llamar operaciones que cambian cosas, así que decir que no muta sería mentir.
     * Pero la firma la pide cada herramienta que la exige, cuando la exige: una firma aquí
     * consentiría «lo que el agente decida», que es exactamente lo que no se puede consentir de
     * antemano.
     */
    public function testItMutatesAndLeavesTheSignatureToEachToolThatDemandsIt(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\EventStore\InMemoryEventStore::class);

        $op = $this->operacion();

        self::assertTrue($op->mutating);
        self::assertFalse($op->requiresConfirmation);
    }

    /**
     * Sólo en la terminal.
     *
     * Un agente corriendo por HTTP con las credenciales del servidor es otra decisión —y de quien
     * monta la app, no de esta plantilla.
     */
    public function testItIsATerminalSurfaceOnly(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\EventStore\InMemoryEventStore::class);

        $op = $this->operacion();

        self::assertTrue($op->supportsSurface('cli'));
        self::assertFalse($op->supportsSurface('http'));
        self::assertFalse($op->supportsSurface('mcp'), 'un agente que se ofrece a otro agente es un bucle que nadie pidió');
    }

    /**
     * Un endpoint declarado GANA sobre cualquier llave de proveedor que ande en el entorno.
     *
     * Quien apuntó su agente a un modelo local no quiere que una `ANTHROPIC_API_KEY` olvidada lo mande
     * a otro lado — y a cobrar. Se comprueba por lo que llega a `ask()`, que es lo que decide a dónde
     * sale la petición.
     */
    public function testADeclaredEndpointWinsOverAProviderKeyInTheEnvironment(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        putenv('ANTHROPIC_API_KEY=una-llave-olvidada');
        putenv('MILPA_AGENT_BASE_URL=https://llama.local');
        putenv('MILPA_AGENT_MODEL=qwen3-coder:30b');
        putenv('MILPA_AGENT_API_KEY=local');

        try {
            $kernel = \App\Tests\Support\OperationsTest::bootedKernel();
            $agente = new class ($kernel->container()) extends AgentOperations {
                /** @var array<string, string> */
                public static array $visto = [];

                protected function ask(
                    string $prompt,
                    int $pasos,
                    \Milpa\ToolRuntime\ToolRegistry $registry,
                    string $proveedor,
                    string $llave,
                    string $modelo,
                    callable $onStep,
                    array $history = [],
                    ?\Milpa\AiGateway\ToolCallGate $gate = null,
                    ?\Milpa\AiGateway\OptionTable $mesa = null,
                    ?\Milpa\AiGateway\ToolCallRecorder $recorder = null,
                    ?\Milpa\AiGateway\PlanBoard $tablero = null,
                ): string {
                    static::$visto = ['proveedor' => $proveedor, 'llave' => $llave, 'modelo' => $modelo];

                    return 'ok';
                }
            };

            $handler = $this->operacionDe($agente)->handler;
            self::assertIsCallable($handler);
            $handler(['prompt' => 'algo']);

            $visto = $agente::$visto;
            self::assertSame('openai', $visto['proveedor'], 'un endpoint local habla el dialecto de OpenAI');
            self::assertSame('local', $visto['llave'], 'la llave del proveedor público no se usa');
            self::assertSame('qwen3-coder:30b', $visto['modelo']);
        } finally {
            putenv('MILPA_AGENT_BASE_URL');
            putenv('MILPA_AGENT_MODEL');
            putenv('MILPA_AGENT_API_KEY');
        }
    }

    /**
     * La auth básica del endpoint viaja como encabezado, no como llave.
     *
     * Un endpoint local detrás de auth básica no acepta el Bearer del proveedor, y sin esto la única
     * salida era no usarlo.
     */
    public function testBasicAuthBecomesAnAuthorizationHeader(): void
    {
        putenv('MILPA_AGENT_BASIC_AUTH=llama:llam4');

        try {
            $agente = new AgentOperations(new DIContainer());
            $metodo = new \ReflectionMethod($agente, 'extraHeaders');
            /** @var array<string, string> $headers */
            $headers = $metodo->invoke($agente);

            self::assertSame('Basic ' . base64_encode('llama:llam4'), $headers['Authorization']);
        } finally {
            putenv('MILPA_AGENT_BASIC_AUTH');
        }
    }

    /** Sin auth básica declarada no se inventa ningún encabezado. */
    public function testWithoutBasicAuthNoHeaderIsInvented(): void
    {
        putenv('MILPA_AGENT_BASIC_AUTH');

        $metodo = new \ReflectionMethod(new AgentOperations(new DIContainer()), 'extraHeaders');

        self::assertSame([], $metodo->invoke(new AgentOperations(new DIContainer())));
    }

    /**
     * Lo que el agente SABE de esta app antes de que nadie le pregunte.
     *
     * El prompt era una línea —«usa las herramientas y no inventes»— y eso dice qué se puede hacer sin
     * decir cómo está armada la app. Medido contra un modelo de verdad: a «quiero guardar en sqlite en
     * vez de json» contestó que creara un `SQLitePersistencePlugin` con `make plugin`. Inventó un
     * plugin que no existe para resolver algo que es una línea de `config/app.php`, y no estaba
     * desobedeciendo: estaba llenando un hueco. Un agente sin contexto no se calla, adivina.
     */
    public function testTheSystemPromptSaysHowThisAppIsBuilt(): void
    {
        $prompt = $this->promptDeSistema(new AgentOperations(new DIContainer()));

        self::assertStringContainsString('config/app.php', $prompt);
        self::assertStringContainsString('storage', $prompt);
        self::assertStringContainsString('sqlite', $prompt);
        self::assertStringContainsString('RepositoryFactory', $prompt);
        self::assertStringContainsString(
            'config/plugins.php',
            $prompt,
            'andamiar un plugin no lo enciende, y esa es la confusión más cara',
        );
        self::assertStringContainsString(
            'guidance',
            $prompt,
            'las guías que devuelven las herramientas son el siguiente paso real, no adorno',
        );
    }

    /** Lo que esta app en particular quiere que su agente sepa entra por `agent.instructions`. */
    public function testTheAppCanAddItsOwnInstructions(): void
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(
            \Milpa\Runtime\Config::class,
            new \Milpa\Runtime\Config(['agent' => ['instructions' => 'Los precios de esta app van en centavos.']]),
        );

        $prompt = $this->promptDeSistema(new AgentOperations($contenedor));

        self::assertStringContainsString('van en centavos', $prompt);
    }

    /**
     * Y lo que cada plugin arrancado contribuye por `getPromptSections()` ATERRIZA en el prompt.
     *
     * Es la costura de siempre: vive en `ToolProviderInterface`, los stubs que este framework genera
     * traen su marcador `// {coa:tool-prompts}`, `PluginsManager` sabe juntarlas — y nadie las leía. Un
     * plugin que contribuye herramientas también contribuye lo que hay que saber para usarlas, y ese
     * texto se estaba tirando.
     */
    public function testEachPluginContributionLandsInThePrompt(): void
    {
        $agente = new class (new DIContainer()) extends AgentOperations {
            public function promptVisible(): string
            {
                return $this->systemPrompt();
            }

            /** @return list<string> */
            protected function promptSectionsOfPlugins(): array
            {
                return ['Los SKU de este plugin siempre traen guion.', '', '   '];
            }
        };

        $prompt = $agente->promptVisible();

        self::assertStringContainsString('Los SKU de este plugin siempre traen guion.', $prompt);
        self::assertStringContainsString('Eres el agente de esta app Milpa', $prompt, 'lo base no se pierde');

        // Y un separador vacío no es una sección: `PluginsManager` intercala cadenas vacías entre
        // plugins, y una que llegara al prompt dejaría un hueco donde el modelo espera contenido.
        //
        // Se afirma la PROPIEDAD y no un número. La versión anterior contaba cuatro secciones, y se
        // rompió el día que el prompt ganó una legítima —lo que la app trae puesto, que depende de
        // qué opt-ins estén instalados—. Un conteo fijo sobre algo que varía con el entorno obliga a
        // editar la prueba cada vez que el sistema crece bien, y una prueba que se edita por costumbre
        // deja de vigilar.
        foreach (explode("\n\n", $prompt) as $seccion) {
            self::assertNotSame('', trim($seccion), 'ninguna sección puede llegar vacía');
        }
        self::assertSame(trim($prompt), $prompt, 'sin relleno al final');
    }

    /** Se camina de verdad a los plugins arrancados: la app fresca no truena al armar su prompt. */
    public function testTheRealWalkOverBootedPluginsHolds(): void
    {
        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();

        $prompt = $this->promptDeSistema(new AgentOperations($kernel->container()));

        self::assertStringContainsString('Eres el agente de esta app Milpa', $prompt);
        self::assertStringContainsString('storage', $prompt);
    }

    private function promptDeSistema(AgentOperations $proveedor): string
    {
        return (string) (new \ReflectionMethod($proveedor, 'systemPrompt'))->invoke($proveedor);
    }

    /**
     * Un agente con sesión: apunta lo dicho y devuelve el historial en la siguiente vuelta (P16.1).
     *
     * @param \Closure(string): string $responder
     */
    private function agenteConSesion(\Closure $responder, SessionStore $almacen, ?array &$historialVisto = null): AgentOperations
    {
        putenv('ANTHROPIC_API_KEY=llave-de-prueba');

        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();

        return new class ($kernel->container(), $responder, $almacen, $historialVisto) extends AgentOperations {
            /** @param \Closure(string): string $responder */
            public function __construct(
                \Milpa\Interfaces\Di\DIContainerInterface $container,
                private readonly \Closure $responder,
                private readonly SessionStore $almacen,
                private ?array &$historialVisto,
            ) {
                parent::__construct($container);
            }

            protected function sessions(): ?SessionStore
            {
                return $this->almacen;
            }

            protected function ask(
                string $prompt,
                int $pasos,
                \Milpa\ToolRuntime\ToolRegistry $registry,
                string $proveedor,
                string $llave,
                string $modelo,
                callable $onStep,
                array $history = [],
                ?\Milpa\AiGateway\ToolCallGate $gate = null,
                ?\Milpa\AiGateway\OptionTable $mesa = null,
                ?\Milpa\AiGateway\ToolCallRecorder $recorder = null,
                ?\Milpa\AiGateway\PlanBoard $tablero = null,
            ): string {
                $this->historialVisto = $history;
                $onStep();

                return ($this->responder)($prompt);
            }
        };
    }

    /**
     * Sin `--session` se comporta EXACTAMENTE como antes.
     *
     * La memoria es opcional y no puede cambiar lo que ya funcionaba: quien sólo quiere preguntar algo
     * no tiene que aprender un concepto nuevo ni dejar un archivo en disco.
     */
    public function testWithoutASessionNothingIsRemembered(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $agente = $this->agenteConSesion(static fn (string $p): string => 'listo', $almacen);

        $r = $this->llamar($agente, ['prompt' => 'hola']);

        self::assertTrue($r['ok']);
        self::assertArrayNotHasKey('session', $r, 'no hay sesión que reportar');
        self::assertSame([], $almacen->ids(), 'y no se abrió ninguna');
    }

    /**
     * Con `--session`, los dos turnos quedan apendados y la siguiente vuelta los recibe.
     *
     * Es P16.1 entero: antes de esto el framework le pasaba `[]` de historial en cada llamada, así que
     * preguntarle dos cosas seguidas eran dos desconocidos.
     */
    public function testWithASessionBothTurnsAreRecordedAndComeBackAsHistory(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new SessionStore(new InMemoryEventStore());
        $historial = null;
        $agente = $this->agenteConSesion(static fn (string $p): string => "contesté a: {$p}", $almacen, $historial);
        $primera = $this->llamar($agente, ['prompt' => 'me llamo Rod', 'session' => 's1']);
        self::assertTrue($primera['ok']);
        self::assertSame('s1', $primera['session']);
        self::assertSame([], $historial, 'la primera vuelta no tiene pasado');

        $segunda = $this->llamar($agente, ['prompt' => '¿cómo me llamo?', 'session' => 's1']);
        self::assertTrue($segunda['ok']);

        // Lo que recibió el modelo la segunda vez: la pregunta y la respuesta de la primera.
        self::assertNotNull($historial);
        self::assertCount(2, $historial);
        self::assertSame('me llamo Rod', $historial[0]['content']);
        self::assertSame('contesté a: me llamo Rod', $historial[1]['content']);

        // Y el stream tiene los cuatro turnos, que es lo que hace auditable la sesión.
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertCount(4, $sesion->turns);
        self::assertSame('me llamo Rod', $sesion->goal, 'el primer prompt es el objetivo con el que se abrió');
    }

    /**
     * Una sesión que espera una respuesta NO se sigue por accidente.
     *
     * Seguirla sería contestar por el humano que no contestó — y peor, hacerlo en silencio: el agente
     * seguiría con la suposición que motivó la pregunta.
     */
    public function testASessionWaitingForAnAnswerRefusesToContinue(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'migrar');
        $almacen->ask('s1', new PendingQuestion('q1', '¿sqlite o mysql?', ['sqlite', 'mysql']));

        $agente = $this->agenteConSesion(static fn (string $p): string => 'no debería llegar aquí', $almacen);
        $r = $this->llamar($agente, ['prompt' => 'sigue', 'session' => 's1']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('esperando una respuesta', (string) $r['error']);
        self::assertStringContainsString('sqlite o mysql', (string) $r['error'], 'dice QUÉ se le preguntó');
    }

    /** Una sesión terminada tampoco: se abre otra, y la negativa dice cómo. */
    public function testAnEndedSessionRefusesAndSaysWhatToDo(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'migrar');
        $almacen->end('s1', 'objetivo cumplido');

        $agente = $this->agenteConSesion(static fn (string $p): string => 'x', $almacen);
        $r = $this->llamar($agente, ['prompt' => 'otra cosa', 'session' => 's1']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('ya terminó', (string) $r['error']);
        self::assertStringContainsString('objetivo cumplido', (string) $r['error']);
        self::assertStringContainsString('--session', (string) $r['hint']);
    }

    /**
     * Lo que se le manda al modelo es la VENTANA, no todo el stream.
     *
     * Es lo que hace que compactar sirva de algo: el stream conserva los cuarenta turnos y el modelo
     * recibe un resumen más lo reciente. Sin esto, compactar sería un evento que nadie honra.
     */
    public function testWhatReachesTheModelIsTheWindowAndNotTheWholeStream(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'una jornada larga');
        for ($i = 1; $i <= 10; ++$i) {
            $almacen->recordTurn('s1', 'user', "turno {$i}");
        }
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        $almacen->compact('s1', 'ya se migraron tres entidades', $sesion->turns[7]['seq']);

        $historial = null;
        $agente = $this->agenteConSesion(static fn (string $p): string => 'ok', $almacen, $historial);
        $this->llamar($agente, ['prompt' => 'sigue', 'session' => 's1']);

        self::assertNotNull($historial);
        self::assertSame('system', $historial[0]['role']);
        self::assertStringContainsString('ya se migraron tres entidades', $historial[0]['content']);
        self::assertCount(3, $historial, 'el resumen más los dos turnos que no cubre');
    }

    /**
     * Corre la operación de este proveedor con esa entrada.
     *
     * @param array<string, mixed> $entrada
     *
     * @return array<string, mixed>
     */
    private function llamar(AgentOperations $proveedor, array $entrada): array
    {
        $handler = $this->operacionDe($proveedor)->handler;
        self::assertIsCallable($handler);

        /** @var array<string, mixed> $r */
        $r = $handler($entrada);

        return $r;
    }

    /**
     * `--mode` elige la autonomía al ABRIR la sesión.
     *
     * `ask` cuando no se dice: una sesión que empieza pidiendo permiso enseña qué va a hacer antes de
     * hacerlo. El default contrario pediría confianza antes de haberla ganado.
     */
    public function testTheModeCanBeChosenWhenTheSessionOpens(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new SessionStore(new InMemoryEventStore());
        $agente = $this->agenteConSesion(static fn (string $p): string => 'ok', $almacen);

        $this->llamar($agente, ['prompt' => 'hola', 'session' => 's1', 'mode' => 'auto']);
        self::assertSame(AutonomyMode::Auto, $almacen->load('s1')?->mode);

        $this->llamar($agente, ['prompt' => 'hola', 'session' => 's2']);
        self::assertSame(AutonomyMode::Ask, $almacen->load('s2')?->mode, 'sin decir nada, ask');
    }

    /**
     * Un `--mode` sobre una sesión viva la cambia; NO decir nada no la regresa al default.
     *
     * El modo es de la sesión, no del comando. Heredar el default de cada invocación devolvería a
     * `ask` una sesión que alguien puso en `auto` a propósito — y lo haría en silencio, que es peor.
     */
    public function testAModeOnALiveSessionChangesItAndSilenceLeavesItAlone(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new SessionStore(new InMemoryEventStore());
        $agente = $this->agenteConSesion(static fn (string $p): string => 'ok', $almacen);

        $this->llamar($agente, ['prompt' => 'uno', 'session' => 's1']);
        self::assertSame(AutonomyMode::Ask, $almacen->load('s1')?->mode);

        $this->llamar($agente, ['prompt' => 'dos', 'session' => 's1', 'mode' => 'auto']);
        self::assertSame(AutonomyMode::Auto, $almacen->load('s1')?->mode);

        $this->llamar($agente, ['prompt' => 'tres', 'session' => 's1']);
        self::assertSame(AutonomyMode::Auto, $almacen->load('s1')?->mode, 'el silencio no la regresa');
    }

    /**
     * Al pasarse del umbral, la sesión se compacta ANTES de preguntar — y se DICE.
     *
     * Antes, porque compactar después sería descubrir que la ventana no cabía cuando el proveedor ya
     * la rechazó. Y se dice porque es lo único de esta operación que cambia en silencio lo que el
     * modelo ve: una sesión que empieza a contestar distinto sin que nadie sepa por qué se depura
     * durante una hora.
     */
    public function testALongSessionIsCompactedBeforeAskingAndSaysSo(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'una jornada larga');
        for ($i = 1; $i <= 30; ++$i) {
            $almacen->recordTurn('s1', 'user', "turno {$i}");
        }

        $historial = null;
        $agente = $this->agenteConSesion(static fn (string $p): string => 'ok', $almacen, $historial);
        // Umbral bajo para no generar cuarenta turnos: lo que se mide es el cableado, no el número.
        $agente = $this->conUmbral($agente, $almacen, 20, 5, $historial);

        $r = $this->llamar($agente, ['prompt' => 'sigue', 'session' => 's1']);

        self::assertTrue($r['ok']);
        self::assertTrue($r['compacted'] ?? false, 'compactó, y lo dice');

        // Y lo que recibió el modelo es la ventana NUEVA: resumen + los recientes + el prompt de ahora.
        self::assertNotNull($historial);
        self::assertSame('system', $historial[0]['role']);
        self::assertStringContainsString('una jornada larga', $historial[0]['content']);
        self::assertLessThan(30, \count($historial), 'la ventana se acortó');

        // El stream, en cambio, no perdió nada.
        self::assertCount(32, $almacen->load('s1')?->turns ?? [], 'los 30 de antes, más la pregunta y la respuesta de ahora');
    }

    /** Por debajo del umbral no compacta, y no lo dice. */
    public function testAShortSessionIsNotCompacted(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new SessionStore(new InMemoryEventStore());
        $agente = $this->agenteConSesion(static fn (string $p): string => 'ok', $almacen);

        $r = $this->llamar($agente, ['prompt' => 'hola', 'session' => 's1']);

        self::assertArrayNotHasKey('compacted', $r);
    }

    /** Un agente con el umbral de compactación fijado, para no generar cuarenta turnos en una prueba. */
    private function conUmbral(
        AgentOperations $original,
        SessionStore $almacen,
        int $maximo,
        int $recientes,
        ?array &$historialVisto = null,
    ): AgentOperations {
        putenv('ANTHROPIC_API_KEY=llave-de-prueba');
        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();

        return new class ($kernel->container(), $almacen, $maximo, $recientes, $historialVisto) extends AgentOperations {
            public function __construct(
                \Milpa\Interfaces\Di\DIContainerInterface $container,
                private readonly SessionStore $almacen,
                private readonly int $maximo,
                private readonly int $recientes,
                private ?array &$historialVisto,
            ) {
                parent::__construct($container);
            }

            protected function sessions(): ?SessionStore
            {
                return $this->almacen;
            }

            protected function compactor(): \Milpa\Agent\Compactor
            {
                return new \Milpa\Agent\Compactor($this->maximo, $this->recientes);
            }

            protected function ask(
                string $prompt,
                int $pasos,
                \Milpa\ToolRuntime\ToolRegistry $registry,
                string $proveedor,
                string $llave,
                string $modelo,
                callable $onStep,
                array $history = [],
                ?\Milpa\AiGateway\ToolCallGate $gate = null,
                ?\Milpa\AiGateway\OptionTable $mesa = null,
                ?\Milpa\AiGateway\ToolCallRecorder $recorder = null,
                ?\Milpa\AiGateway\PlanBoard $tablero = null,
            ): string {
                $this->historialVisto = $history;
                $onStep();

                return 'ok';
            }
        };
    }

    /**
     * La ventana de permiso sale de la config del host, y SIN default.
     *
     * Cuánto tiempo tiene un humano para contestar antes de que la sesión se declare muerta depende
     * de quién opera el agente — una guardia, una jornada, un fin de semana. Un número inventado en
     * el runtime mataría sesiones de gente que nunca lo eligió, así que sin declaración no hay plazo.
     *
     * Y una ventana ILEGIBLE tampoco se convierte en una inventada: se ignora. De los dos errores
     * posibles, adivinar es el caro — un typo en un archivo de configuración empezaría a matar
     * sesiones sin que nadie lo pidiera.
     */
    public function testThePermissionWindowComesFromConfigAndNeverGetsInvented(): void
    {
        $leer = static function (array $config): ?\DateInterval {
            $contenedor = new \Milpa\Container\DIContainer();
            $contenedor->registerService(\Milpa\Runtime\Config::class, new \Milpa\Runtime\Config($config));
            $ops = new AgentOperations($contenedor);
            $m = new \ReflectionMethod($ops, 'permissionWindow');

            return $m->invoke($ops);
        };

        self::assertNull($leer([]), 'sin declarar: sin plazo');
        self::assertNull($leer(['agent' => ['permissionWindow' => '']]), 'vacía: sin plazo');
        self::assertNull($leer(['agent' => ['permissionWindow' => 'mañana']]), 'ilegible: sin plazo, no inventada');

        $ventana = $leer(['agent' => ['permissionWindow' => 'PT8H']]);
        self::assertInstanceOf(\DateInterval::class, $ventana);
        self::assertSame(8, $ventana->h);
    }

    /**
     * CONTROL POSITIVO DE Q-P20-B: con la perilla encendida, el bucle SÍ recibe un tablero.
     *
     * Sin esta prueba la tanda no se puede leer. Si el cableado estuviera roto, el brazo de la
     * intervención correría idéntico al de control, la diferencia daría cero, y ese cero se leería
     * como «reproyectar el plan no sirve» — la conclusión más cara posible sobre un instrumento que
     * nunca se ejerció. ADR-0029 y ADR-0033: un cero de un instrumento no probado contra un caso
     * positivo no es un hallazgo, es silencio.
     *
     * Por eso la prueba trae su propio negativo, y por eso mira las TRES puertas: la perilla, la
     * sesión y el almacén. Cada una devuelve `null` por su cuenta, y las tres se ven igual desde
     * afuera.
     */
    public function testLaPerillaDeReproyeccionLlegaAlBucle(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\PlanBoard::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new \Milpa\Agent\SessionStore(new \Milpa\EventStore\InMemoryEventStore());

        self::assertNotNull($this->tableroCon(true, 's1', $almacen), 'con la perilla encendida');
        self::assertNull($this->tableroCon(false, 's1', $almacen), 'apagada, nada');
        self::assertNull($this->tableroCon(true, '', $almacen), 'sin sesión, nada');
        self::assertNull($this->tableroCon(true, 's1', null), 'sin almacén, nada');
    }

    /**
     * Y EL TABLERO TRAE EL PLAN DE VERDAD, no un encabezado vacío.
     *
     * La perilla cableada y un tablero que siempre contesta `null` se ven idénticos desde el bucle.
     * Ésta es la mitad que falta del control: que lo que llega tenga adentro las tarjetas.
     */
    public function testElTableroTraeLasTarjetasDeLaSesion(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\PlanBoard::class, \Milpa\EventStore\InMemoryEventStore::class);

        $almacen = new \Milpa\Agent\SessionStore(new \Milpa\EventStore\InMemoryEventStore());
        $almacen->start('s2', 'un objetivo', \Milpa\Agent\AutonomyMode::Auto);

        $tablero = $this->tableroCon(true, 's2', $almacen);
        self::assertNotNull($tablero);
        self::assertNull($tablero->current(), 'sin plan ni tarjetas todavía, no hay qué reproyectar');

        $almacen->setPlan('s2', 'primero A, luego B');
        $almacen->setTodo('s2', new \Milpa\Agent\Todo('t1', 'hacer A', \Milpa\Agent\TodoStatus::InProgress));
        $almacen->setTodo('s2', new \Milpa\Agent\Todo('t2', 'hacer B'));

        $texto = $tablero->current();
        self::assertNotNull($texto);
        self::assertStringContainsString('primero A, luego B', $texto);
        self::assertStringContainsString('hacer A', $texto);
        self::assertStringContainsString('en curso', $texto, 'el estado en palabras, no en símbolos');
        self::assertStringContainsString('pendiente', $texto);

        // LEE CADA VEZ. Si memorizara, reproduciría el defecto que arregla un nivel más abajo.
        $almacen->setTodo('s2', new \Milpa\Agent\Todo('t1', 'hacer A', \Milpa\Agent\TodoStatus::Done));
        self::assertStringContainsString('hecho', (string) $tablero->current(), 'volvió a leer el stream');
    }

    private function tableroCon(bool $encendida, string $sesion, ?\Milpa\Agent\SessionStore $almacen): ?\Milpa\AiGateway\PlanBoard
    {
        $contenedor = new \Milpa\Container\DIContainer();
        $contenedor->registerService(
            \Milpa\Runtime\Config::class,
            new \Milpa\Runtime\Config(['agent' => ['reprojectPlan' => $encendida]]),
        );

        $agente = new class ($contenedor) extends AgentOperations {
            public function tablero(string $sesion, ?\Milpa\Agent\SessionStore $almacen): ?\Milpa\AiGateway\PlanBoard
            {
                return $this->tableroDePlan($sesion, $almacen);
            }
        };

        return $agente->tablero($sesion, $almacen);
    }

    /**
     * EL CÓDIGO SE DEFIENDE DE SU PROPIO MANIFIESTO — la guarda que evita un fatal por vendor viejo.
     *
     * `PlanBoard` llegó en `milpa/ai-gateway` 0.8, y este archivo viaja con `composer create-project`:
     * convive con el vendor que su dueño tenga, no con el que su manifiesto pide. El
     * `conflict: <0.8` declara la exigencia y **no la garantiza**, porque nadie obliga a correr
     * `composer update`.
     *
     * Con la interfaz ausente, instanciar `SessionPlanBoard implements PlanBoard` sería un fatal. Esta
     * prueba fija que la guarda es `interface_exists` y no una comprobación que el análisis estático
     * pueda dar por cierta — no puede simular la ausencia de la interfaz (aquí siempre está), así que
     * verifica la única propiedad observable: que la guarda EXISTE en el camino.
     *
     * Ya pasó una vez, con `Operation::$namedTarget` en un clon real.
     */
    public function testElTableroSeDefiendeDeUnVendorSinLaInterfaz(): void
    {
        // SE LE PREGUNTA A LA CLASE DÓNDE VIVE, no se arma su ruta a mano.
        //
        // `dirname(__DIR__, 3) . '/milpa-app-runtime/src/...'` era cierto mientras el código vivía
        // al lado, en el monorepo. Desde que se mudó a `milpa/app-runtime` esa ruta sólo existe
        // AQUÍ: en el árbol exportado el archivo está en `vendor/`, y la prueba fallaba con
        // «false is of type string» — un `file_get_contents` de algo que no está, no un defecto del
        // código que dice medir. Es la tercera vez en esta mudanza que una ruta cableada sobrevive
        // al lugar para el que se escribió.
        $archivo = (new \ReflectionClass(AgentOperations::class))->getFileName();
        self::assertIsString($archivo);
        $fuente = file_get_contents($archivo);
        self::assertIsString($fuente);

        self::assertStringContainsString(
            'interface_exists(PlanBoard::class)',
            $fuente,
            'sin esta guarda, un ai-gateway anterior a 0.8 da fatal al instanciar el adaptador',
        );

        // Y EL PARÁMETRO NOMBRADO NO SE PASA CUANDO NO HAY TABLERO. Contra un 0.7, `planBoard: null`
        // truena con «Unknown named parameter» en CADA vuelta — no cuando hay plan, siempre.
        self::assertStringNotContainsString(
            'planBoard: $tablero',
            $fuente,
            'el parámetro nombrado sólo puede viajar cuando la versión que lo acepta está instalada',
        );
    }

    /**
     * INTERRUMPIR NO ES FALLAR — la vuelta se detiene y la sesión queda viva y retomable.
     *
     * El trabajo hecho hasta ahí ya está en el stream: cada llamada se apenda al ocurrir. Devolverlo
     * como error sugeriría que hay algo que arreglar, y lo que hay es una decisión del humano.
     */
    public function testAnInterruptedTurnIsReportedAsADecisionNotAFailure(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        putenv('ANTHROPIC_API_KEY=llave-de-prueba');

        $almacen = new SessionStore(new InMemoryEventStore());
        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();

        $agente = new class ($kernel->container(), $almacen) extends AgentOperations {
            public function __construct(
                \Milpa\Interfaces\Di\DIContainerInterface $container,
                private readonly SessionStore $almacen,
            ) {
                parent::__construct($container);
            }

            protected function sessions(): ?SessionStore
            {
                return $this->almacen;
            }

            protected function ask(
                string $prompt,
                int $pasos,
                \Milpa\ToolRuntime\ToolRegistry $registry,
                string $proveedor,
                string $llave,
                string $modelo,
                callable $onStep,
                array $history = [],
                ?\Milpa\AiGateway\ToolCallGate $gate = null,
                ?\Milpa\AiGateway\OptionTable $mesa = null,
                ?\Milpa\AiGateway\ToolCallRecorder $recorder = null,
                ?\Milpa\AiGateway\PlanBoard $tablero = null,
            ): string {
                // Tres pasos de trabajo, y al cuarto el humano dice «para».
                $onStep();
                $onStep();
                $onStep();

                throw \Milpa\AiGateway\RunInterrupted::porElHumano(3);
            }
        };

        $r = $this->llamar($agente, ['prompt' => 'construye todo', 'session' => 'interrumpida']);

        self::assertTrue($r['ok'], 'no es un error');
        self::assertTrue($r['interrupted'] ?? false);
        self::assertArrayNotHasKey('error', $r);
        self::assertSame(3, $r['steps'] ?? 0, 'dice cuánto alcanzó a hacer');
        self::assertStringContainsString('pídele que siga', (string) ($r['hint'] ?? ''));

        // LA SESIÓN SIGUE VIVA. Quedó apendado que se interrumpió, y nada la cerró: la vuelta
        // siguiente continúa desde donde estaba, que es todo el punto de poder interrumpir.
        $sesion = $almacen->load('interrumpida');
        self::assertNotNull($sesion);
        self::assertTrue($sesion->isRunnable(), 'se puede seguir');
        self::assertNull($sesion->endedBecause, 'y no terminó');
        self::assertStringContainsString(
            'interrumpió',
            (string) ($sesion->turns[\count($sesion->turns) - 1]['content'] ?? ''),
            'el stream lo recuerda',
        );
    }

    /**
     * EL PROMPT LLEVA LO QUE LA APP DECIDIÓ QUE LLEVE, y cada modo pone una cosa distinta.
     *
     * `agent.architectureSummary` es la perilla que Q-P17 midió: el estado derivado fija por dónde
     * empieza el agente, y los tres modos existen porque cada uno cuesta distinto en contexto.
     * `agent.planInstruction` es la de Q-P20-B — apagada, el agente no escribe un plan nunca.
     */
    public function testTheSystemPromptCarriesWhatTheAppDeclared(): void
    {
        $sinNada = $this->promptCon(['planInstruction' => false]);
        self::assertStringNotContainsString('Escribe un plan', $sinNada, 'apagada, la instrucción no va');

        $conPlan = $this->promptCon([]);
        self::assertStringContainsString('Escribe un plan', $conPlan, 'con las herramientas viajando, la orden va');
        self::assertStringContainsString('sigue ésos', $conPlan, 'incluido el renglón de continuar el plan viejo');

        // NO SE LE ORDENA LO QUE NO SE LE DIO. La orden nombra `plan` y `todo`; en un app donde no
        // se registran, no viajan, y pedirlas era una orden imposible que la medición leía como
        // desobediencia (greenhouse evidence/0172, app-runtime v0.25.0).
        if ((new \ReflectionMethod(AgentOperations::class, 'systemPrompt'))->getNumberOfParameters() > 0) {
            $sinHerramientas = $this->promptCon([], herramientas: []);
            self::assertStringNotContainsString('Escribe un plan', $sinHerramientas, 'sin las herramientas, no se ordena');
        }

        $puntero = $this->promptCon(['architectureSummary' => 'pointer']);
        self::assertNotSame($conPlan, $puntero, 'el modo cambia lo que se manda');

        $propias = $this->promptCon(['instructions' => 'Esta app habla de inventarios.']);
        self::assertStringContainsString('Esta app habla de inventarios.', $propias, 'lo de la app viaja tal cual');
    }

    /**
     * El prompt del sistema con esta configuración de `agent.*`.
     *
     * @param array<string, mixed> $agente
     */
    /**
     * @param array<string, mixed> $agente
     * @param list<string>         $herramientas
     */
    private function promptCon(array $agente, array $herramientas = ['plan', 'todo']): string
    {
        $contenedor = new \Milpa\Container\DIContainer();
        $contenedor->registerService(
            \Milpa\Runtime\Config::class,
            new \Milpa\Runtime\Config(['agent' => $agente]),
        );

        $operaciones = new class ($contenedor) extends AgentOperations {
            /**
             * EL ESQUELETO CONVIVE CON EL VENDOR QUE SU DUEÑO TENGA, no con el que su manifiesto
             * pide. `systemPrompt()` ganó un parámetro en app-runtime v0.25.0, y llamarlo con
             * argumentos contra un vendor anterior es un fatal en CADA corrida — el mismo modo de
             * falla que este archivo ya documenta para `planBoard`.
             *
             * @param list<string> $herramientas las que de verdad viajan en la corrida
             */
            public function prompt(array $herramientas = []): string
            {
                $recibeHerramientas = (new \ReflectionMethod($this, 'systemPrompt'))->getNumberOfParameters() > 0;

                return $recibeHerramientas ? $this->systemPrompt($herramientas) : $this->systemPrompt();
            }
        };

        return $operaciones->prompt($herramientas);
    }

    /**
     * EL AGENTE VE LA ARQUITECTURA QUE ESTA APP DECLARA, derivada de los plugins que arrancaron.
     *
     * No de una constante ni de un archivo aparte: sale de los `PluginMetadata` de lo que el kernel
     * booteó. Un catálogo que se lleva a mano envejece sin que nadie lo note, y el agente contestaría
     * sobre una app que ya no existe.
     */
    public function testTheArchitectureIsDerivedFromThePluginsThatBooted(): void
    {
        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();

        $agente = new class ($kernel->container()) extends AgentOperations {
            /** @return list<\Milpa\Attributes\PluginMetadata> */
            public function metas(): array
            {
                return $this->pluginMetadata();
            }

            public function reporte(): ?\Milpa\Resolver\Report\ResolutionReport
            {
                return $this->architectureReport();
            }

            /** @return list<string> */
            public function capacidades(): array
            {
                return $this->providedCapabilities();
            }
        };

        $metas = $agente->metas();
        self::assertNotSame([], $metas, 'la app de prueba trae plugins');
        foreach ($metas as $meta) {
            self::assertNotSame('', $meta->name, 'cada uno se nombra');
        }

        // EL REPORTE NO REVIENTA aunque el grafo esté como esté. Es contexto para el agente, no un
        // gate: una app con el grafo roto tiene MÁS necesidad de que el agente pueda mirarla, no
        // menos, así que aquí un fallo se traga y se contesta `null`.
        $agente->reporte();

        $capacidades = $agente->capacidades();
        foreach ($capacidades as $c) {
            self::assertIsString($c);
        }
    }

    /**
     * SIN KERNEL NO HAY PLUGINS QUE MIRAR, y eso se contesta vacío en vez de reventar.
     *
     * `pluginMetadata()` se llama al armar el prompt. Un contenedor sin kernel —una prueba, un
     * arranque a medias— no puede enumerar plugins, y hacerlo fallar ahí dejaría al agente sin poder
     * contestar nada por una razón que no tiene que ver con lo que se le preguntó.
     */
    public function testWithoutAKernelThereAreNoPluginsAndThatIsFine(): void
    {
        $agente = new class (new \Milpa\Container\DIContainer()) extends AgentOperations {
            /** @return list<\Milpa\Attributes\PluginMetadata> */
            public function metas(): array
            {
                return $this->pluginMetadata();
            }

            public function reporte(): ?\Milpa\Resolver\Report\ResolutionReport
            {
                return $this->architectureReport();
            }
        };

        self::assertSame([], $agente->metas());
        self::assertNull($agente->reporte(), 'sin plugins que resolver, no hay reporte que dar');
    }

    /**
     * AN OPERATOR'S `deny` REALLY WITHDRAWS THE TOOL.
     *
     * The mechanism was complete but only a delegating parent could reach it: whoever ran the agent
     * by hand had no way to contain a reviewer other than asking in prose — precisely what this house
     * measured does not govern. This anchors that the withdrawal reaches the table the model sees.
     */
    public function testAnOperatorDenyReachesTheTableTheModelSees(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $vista = null;
        $recibido = '';
        $agente = $this->agenteQueVeLaMesa(static function (string $p, ?\Milpa\AiGateway\OptionTable $mesa) use (&$vista, &$recibido): string {
            $vista = $mesa;
            $recibido = $p;

            return 'listo';
        });

        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool} $r */
        $r = $handler(['prompt' => 'revisa', 'session' => 'rev-test', 'deny' => 'make,plugins_enable']);

        self::assertTrue($r['ok']);
        self::assertNotNull($vista, 'an explicit deny must bring a table even when the app leaves automatic withdrawal off');
        self::assertTrue($vista->wasRemoved('make'));
        self::assertTrue($vista->wasRemoved('plugins_enable'));
        self::assertFalse($vista->wasRemoved('plugins_list'), 'only what was asked for goes away');
        self::assertStringContainsString('not in your catalogue', $recibido, 'the fact changes the world; the sentence tells it why');
    }

    /**
     * AND WITHOUT A SESSION IT REFUSES, rather than pretending it contained anything.
     *
     * The table lives in the session: without one the prohibition would not survive the first step.
     * Ignoring it silently would be worse than not having the mechanism — whoever typed it walks away
     * believing they are contained.
     */
    public function testWithoutASessionADenyIsRefusedInsteadOfIgnored(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $agente = $this->agenteQueContesta(static fn (string $p): string => 'should never get here');
        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string, hint?: string} $r */
        $r = $handler(['prompt' => 'revisa', 'deny' => 'make']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('without a session', (string) ($r['error'] ?? ''));
        self::assertStringContainsString('--session', (string) ($r['hint'] ?? ''));
    }

    /**
     * An agent that also lets the table it ran with be inspected.
     *
     * @param \Closure(string, ?\Milpa\AiGateway\OptionTable): string $responder
     */
    private function agenteQueVeLaMesa(\Closure $responder): AgentOperations
    {
        putenv('ANTHROPIC_API_KEY=llave-de-prueba');

        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();

        return new class ($kernel->container(), $responder) extends AgentOperations {
            /** @param \Closure(string, ?\Milpa\AiGateway\OptionTable): string $responder */
            public function __construct(\Milpa\Interfaces\Di\DIContainerInterface $container, private readonly \Closure $responder)
            {
                parent::__construct($container);
            }

            protected function ask(
                string $prompt,
                int $pasos,
                \Milpa\ToolRuntime\ToolRegistry $registry,
                string $proveedor,
                string $llave,
                string $modelo,
                callable $onStep,
                array $history = [],
                ?\Milpa\AiGateway\ToolCallGate $gate = null,
                ?\Milpa\AiGateway\OptionTable $mesa = null,
                ?\Milpa\AiGateway\ToolCallRecorder $recorder = null,
                ?\Milpa\AiGateway\PlanBoard $tablero = null,
            ): string {
                $onStep();

                return ($this->responder)($prompt, $mesa);
            }
        };
    }

    /**
     * WITHDRAWAL BY EFFECT CLASS COVERS WHAT A LIST OF NAMES FORGETS.
     *
     * Q-P20-P measured the leak: with five tools denied by name, 3/3 runs reached for `plugins.lock`
     * — mutating, and not on the list. This anchors that `mutating` as a CLASS takes it, that reads
     * survive, and that the session bookkeeping is not swept up with it.
     */
    public function testDenyingAnEffectClassTakesTheOperationsNobodyNamed(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $vista = null;
        $agente = $this->agenteQueVeLaMesa(static function (string $p, ?\Milpa\AiGateway\OptionTable $mesa) use (&$vista): string {
            $vista = $mesa;

            return 'listo';
        });

        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool} $r */
        $r = $handler(['prompt' => 'revisa', 'session' => 'eff-test', 'denyEffects' => 'mutating']);

        self::assertTrue($r['ok']);
        self::assertNotNull($vista);
        self::assertTrue($vista->wasRemoved('plugins_lock'), 'the leak Q-P20-P found must be covered by the class');
        self::assertTrue($vista->wasRemoved('plugins_enable'));
        self::assertFalse($vista->wasRemoved('plugins_list'), 'reading is not mutating');
        self::assertFalse($vista->wasRemoved('plan'), 'the session notebook is not swept up: taking it away makes the agent illegible, not safer');
    }

    /**
     * AND AN UNDECLARED OPERATION IS DENIED, not waved through.
     *
     * `Unknown` ranks ABOVE known-bad by construction. The opposite reading — undeclared means
     * harmless — turns every operation nobody classified into the next `plugins.lock`.
     */
    public function testAnOperationWithUnknownEffectsIsDenied(): void
    {
        $sin = new \Milpa\Command\Operation(
            name: 'mystery',
            description: 'nobody classified this',
            handler: static fn (): array => [],
        );

        self::assertSame(\Milpa\Command\Effect\Mutation::Unknown, $sin->effectCeiling()->mutation);
        self::assertNotSame(\Milpa\Command\Effect\Mutation::None, $sin->effectCeiling()->mutation);
    }

    /**
     * CONTAINMENT BY EFFECT IS SURVIVABLE EXACTLY WHERE THE CATALOGUE WAS CLASSIFIED.
     *
     * Measured 2026-08-05 on a fixture running published packages: 25 of 25 operations ceilinged at
     * `Unknown`, so «withdraw what mutates» withdrew all 25 — reads included. The withdrawal is not
     * softened, because unknown never reduces controls; what this anchors is that the CAUSE gets
     * named. An agent that comes back empty because nobody classified the catalogue looks exactly
     * like a broken agent, and whoever typed the flag would go looking anywhere but at the reason.
     */
    public function testAClassifiedCatalogueSurvivesContainmentByEveryClassAtOnce(): void
    {
        OptIn::needs(\Milpa\Agent\AutonomyMode::class, \Milpa\AiGateway\OptionTable::class, \Milpa\EventStore\InMemoryEventStore::class);

        $llamado = false;
        $vista = null;
        $agente = $this->agenteQueVeLaMesa(static function (string $p, ?\Milpa\AiGateway\OptionTable $mesa) use (&$llamado, &$vista): string {
            $llamado = true;
            $vista = $mesa;

            return 'listo';
        });

        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);

        // Every class at once takes everything: nothing survives `mutating` ∪ `external` ∪
        // `irreversible` ∪ `authority` once `Unknown` is on the deny side of each.
        /** @var array{ok: bool, error?: string, hint?: string} $r */
        $r = $handler([
            'prompt' => 'revisa',
            'session' => 'vacio-test',
            'denyEffects' => 'mutating,external,irreversible,authority',
        ]);

        // AND ON THIS CATALOGUE IT DOES NOT FIRE, which is the other half of the finding: the four
        // classes at once still leave the agent something to read, because this catalogue IS
        // classified. Containment by effect is survivable exactly where the classification was done —
        // and that is a property of the catalogue, not of the flag.
        self::assertTrue($r['ok'], 'a classified catalogue survives containment by every class at once');
        self::assertTrue($llamado);
        self::assertNotNull($vista);
        self::assertFalse($vista->wasRemoved('plugins_list'), 'a pure read survives all four classes: no mutation, no externality, reversible, read authority');
        self::assertTrue($vista->wasRemoved('plugins_enable'));
    }
}
