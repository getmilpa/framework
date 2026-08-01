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

namespace App\Operations;

use Milpa\AiGateway\AgentOrchestrator;
use App\Agent\ArchitectureSummaryProjector;
use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\Runtime\MetadataGraphResolver;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Compactor;
use Milpa\Agent\SessionStore;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use App\Agent\SessionBookkeeping;
use App\Agent\SessionToolGate;
use App\Support\Operations;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\EventStore\EventStoreInterface;
use App\Agent\BroadcastingEventStore;
use App\Agent\MercureBroadcaster;
use App\Agent\SurfaceBroadcaster;
use Milpa\EventStore\FileEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

/**
 * El agente de esta app: le pides algo en tu idioma y lo hace con las MISMAS operaciones que ya
 * tienes.
 *
 * `milpa/ai-gateway` traía el bucle —alterna modelo ↔ herramientas— desde antes, y venía instalado
 * con el framework sin que nadie lo llamara: estaba en el árbol y no existía para quien creaba una
 * app. Esto es la línea que faltaba.
 *
 * ── LAS HERRAMIENTAS SON LOS ÁTOMOS, NO OTRA COSA ───────────────────────────────────────────────
 *
 * El agente ve exactamente lo que ve un cliente MCP, porque se arma igual: las operaciones de esta
 * app proyectadas con {@see McpProjector}. No hay un segundo catálogo de «herramientas del agente»
 * que se pueda desincronizar del primero — sería el mismo defecto que tener dos gestores de plugins.
 *
 * ── SIN LLAVE NO FINGE ──────────────────────────────────────────────────────────────────────────
 *
 * Sin API key configurada contesta qué falta y cómo ponerla. No hay modo demo ni respuesta simulada:
 * un agente que contesta algo plausible sin haber llamado a nadie es peor que uno que no arranca.
 */
class AgentOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'agent',
                description: 'Le pide al agente que haga algo usando las operaciones de esta app',
                handler: fn (array $input): array => $this->run($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Qué quieres que haga, en tu idioma'],
                        'steps' => ['type' => 'integer', 'description' => 'Tope de pasos modelo↔herramienta; 12 si no se dice'],
                        'session' => ['type' => 'string', 'description' => 'Continúa esta sesión — sin ella, cada pregunta empieza de cero'],
                        'mode' => ['type' => 'string', 'enum' => ['ask', 'acknowledge', 'auto'], 'description' => 'Autonomía: ask pregunta antes de mutar, auto sigue sola. Ninguno se salta una firma'],
                    ],
                    'required' => ['prompt'],
                ],
                // MUTA, y lo dice: el agente puede llamar operaciones que cambian cosas. Lo que NO
                // hace es pedir firma por sí mismo — la pide cada herramienta que la exige, cuando la
                // exige, y esa es la compuerta que nombra la llamada concreta. Una firma aquí
                // consentiría «lo que el agente decida», que es justo lo que no se puede consentir.
                mutating: true,
                // Fuera de la terminal: un agente que corre por HTTP con las credenciales del
                // servidor es otra decisión, y esta plantilla no la toma por nadie.
                surfaces: ['cli'],
            ),
        ];
    }

    /**
     * Corre el bucle y devuelve lo que el agente contestó.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, answer?: string, steps?: int, tools?: int, error?: string, hint?: string}
     */
    private function run(array $input): array
    {
        $prompt = \is_string($input['prompt'] ?? null) ? trim($input['prompt']) : '';
        if ($prompt === '') {
            return ['ok' => false, 'error' => 'falta `prompt`: qué quieres que haga'];
        }

        if (!class_exists(AgentOrchestrator::class) || !class_exists(ToolRegistry::class)) {
            return [
                'ok' => false,
                'error' => 'esta app no tiene la superficie de agente instalada',
                'hint' => 'composer require milpa/ai-gateway milpa/tool-runtime',
            ];
        }

        $credencial = $this->credential();
        if ($credencial === null) {
            return [
                'ok' => false,
                'error' => 'no hay API key configurada, así que no hay a quién preguntarle',
                'hint' => 'exporta ANTHROPIC_API_KEY (o OPENAI_API_KEY) y vuelve a correrlo',
            ];
        }

        [$proveedor, $llave, $modelo] = $credencial;

        $pasos = \is_int($input['steps'] ?? null) && $input['steps'] > 0 ? $input['steps'] : 12;

        // LA SESIÓN (P16.1). Sin `session`, esto sigue siendo lo que era: una pregunta con una
        // respuesta. Con ella, la conversación sobrevive al proceso — que es la diferencia entre un
        // agente al que se le pregunta algo y uno con el que se trabaja un rato.
        $almacen = $this->sessions();
        $sesionId = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        $historial = [];

        if ($sesionId !== '' && $almacen !== null) {
            // CADUCAR ANTES DE MIRAR. Una pregunta vencida deja la sesión sin poder correr para
            // siempre —`isRunnable()` es falso mientras haya pregunta— así que sin esta línea la
            // sesión olvidada no queda pausada: queda muerta y nadie lo declara ([Q-P19-B]).
            //
            // Va aquí y no en un cron porque el momento en que importa es éste: cuando alguien
            // vuelve a la sesión. Un barrido nocturno mataría sesiones que nadie estaba mirando, y
            // dejaría vivas las que sí — al revés de lo que hace falta.
            $almacen->expireIfDue($sesionId, new \DateTimeImmutable());

            $sesion = $almacen->load($sesionId);
            // `ask` cuando no se dice: una sesión que empieza pidiendo permiso enseña qué va a hacer
            // antes de hacerlo, y quien la mira sube el modo cuando ya vio de qué se trata. El default
            // contrario —empezar en `auto`— pediría confianza antes de haberla ganado.
            $modo = AutonomyMode::tryFrom(\is_string($input['mode'] ?? null) ? $input['mode'] : '');

            if ($sesion === null) {
                // Se abre con el primer prompt como objetivo: continuar una sesión que no existe es
                // empezarla, y negarse obligaría a dos comandos para lo que es una intención.
                $almacen->start($sesionId, $prompt, $modo ?? AutonomyMode::Ask);
            } elseif (!$sesion->isRunnable()) {
                // Una sesión con una pregunta abierta o ya terminada NO se sigue por accidente: se
                // contesta o se abre otra. Seguirla sería contestar por el humano que no contestó.
                return [
                    'ok' => false,
                    'error' => $sesion->question !== null
                        ? "la sesión «{$sesionId}» está esperando una respuesta: {$sesion->question->question}"
                        : "la sesión «{$sesionId}» ya terminó: {$sesion->endedBecause}",
                    'hint' => $sesion->question !== null
                        ? 'contéstala y vuelve a correrlo'
                        : 'usa otro --session para empezar una nueva',
                ];
            } else {
                // `window()` y no `turns`: si ya hubo compactación, esto es el resumen más lo reciente.
                // El stream conserva todo; lo que se acorta es lo que se le manda al modelo.
                $historial = $sesion->window();

                // Un `--mode` sobre una sesión viva la cambia, y queda apendado. Es explícito: alguien
                // lo tecleó. Lo que NO se hace es cambiarlo en silencio cuando no se dijo nada — el
                // modo es de la sesión, no del comando, y heredar el default de cada invocación
                // devolvería a `ask` una sesión que alguien puso en `auto` a propósito.
                if ($modo !== null && $modo !== $sesion->mode) {
                    $almacen->setMode($sesionId, $modo);
                }

                // COMPACTAR ANTES DE PREGUNTAR (P16.2), no después. Después sería descubrir que la
                // ventana no cabía cuando el proveedor ya la rechazó — y una sesión larga se muere
                // exactamente ahí, a la mitad, con trabajo hecho que conviene no repetir.
                $compactado = $this->compactor()->compactIfNeeded($almacen, $sesion);
                if ($compactado !== null) {
                    // Se relee: la ventana cambió, y mandar la vieja habría hecho el trabajo de
                    // compactar sin cobrar el beneficio.
                    $sesion = $almacen->load($sesionId);
                }

                $historial = $sesion?->window() ?? $historial;
            }

            $almacen->recordTurn($sesionId, 'user', $prompt);
        }

        // LA COMPUERTA (P16.4/P16.5) y LAS HERRAMIENTAS DE LA SESIÓN (P16.3). Las dos sólo con sesión:
        // pedir permiso sin sesión sería pedirlo sin dónde apuntarlo, y ofrecer un `plan` sin dónde
        // guardarlo sería peor — el modelo lo llamaría, lo vería contestar «ok», y seguiría creyendo
        // que dejó un plan.
        $compuerta = null;
        $contabilidad = [];
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if ($sesionId !== '' && $almacen !== null && $kernel instanceof Kernel) {
            $viva = $almacen->load($sesionId);
            if ($viva !== null) {
                $compuerta = new SessionToolGate(
                    $almacen,
                    $viva,
                    Operations::all($kernel, $kernel->root()),
                    permissionWindow: $this->permissionWindow(),
                );
                // ATADAS a esta sesión: el id se captura, no se le pide al modelo. Uno que el modelo
                // pudiera nombrar es uno que puede errar, y escribirle el plan a otra sesión no es una
                // equivocación recuperable — quien la lea mañana verá un plan que su agente no escribió.
                $contabilidad = (new SessionBookkeeping($almacen, $sesionId))->operations();
            }
        }

        $registry = $this->toolsOfThisApp($contabilidad);
        if ($registry === null) {
            return ['ok' => false, 'error' => 'esta app no expuso ninguna operación como herramienta'];
        }

        $vistos = 0;
        try {
            $respuesta = $this->ask($prompt, $pasos, $registry, $proveedor, $llave, $modelo, function () use (&$vistos): void {
                ++$vistos;
            }, $historial, $compuerta);
        } catch (\Throwable $e) {
            // El motivo se devuelve tal cual: viene del proveedor —una llave inválida, un modelo que
            // no existe, la red— y quien lo lee necesita esa frase, no una reformulación.
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($sesionId !== '' && $almacen !== null) {
            $almacen->recordTurn($sesionId, 'assistant', $respuesta);
        }

        $resultado = [
            'ok' => true,
            'answer' => $respuesta,
            'steps' => $vistos,
            'tools' => \count($registry->getToolDefinitions()),
        ];

        if ($sesionId !== '') {
            $resultado['session'] = $sesionId;
        }

        // Que se haya compactado se DICE. Es lo único de esta operación que cambia en silencio lo que
        // el modelo ve, y una sesión que empieza a contestar distinto sin que nadie sepa por qué es
        // la clase de cosa que se depura durante una hora.
        if (isset($compactado)) {
            $resultado['compacted'] = true;
        }

        return $resultado;
    }

    /**
     * Una vuelta del bucle: pregunta al modelo, deja que llame herramientas, devuelve la respuesta.
     *
     * Es un método aparte —y protegido— porque es la ÚNICA parte que sale a la red. Todo lo demás de
     * esta operación —qué falta, qué herramientas hay, qué forma tiene el resultado, qué pasa si el
     * proveedor truena— se puede probar sin llave y sin red sustituyendo esto. La alternativa era
     * dejar la mitad del archivo sin medir y enterarse en producción.
     *
     * @param callable():void                            $onStep
     * @param list<array{role: string, content: string}> $history lo que ya se dijo en esta sesión —
     *                                                            vacío cuando no hay sesión, que es
     *                                                            como corría antes de P16.1
     */
    protected function ask(
        string $prompt,
        int $pasos,
        ToolRegistry $registry,
        string $proveedor,
        string $llave,
        string $modelo,
        callable $onStep,
        array $history = [],
        ?ToolCallGate $gate = null,
    ): string {
        $orquestador = new AgentOrchestrator(
            new LlmService(
                $llave,
                $modelo,
                $proveedor,
                new NullLogger(),
                baseUrl: $this->baseUrl(),
                extraHeaders: $this->extraHeaders(),
            ),
            new McpClientService($registry, $gate, $gate instanceof ToolCallRecorder ? $gate : null),
            $pasos,
            new NullLogger(),
        );

        return $orquestador->run(
            $prompt,
            $this->systemPrompt(),
            $history,
            $onStep,
        );
    }

    /**
     * Dónde viven las sesiones de esta app.
     *
     * El almacén se pide al contenedor primero: una app que ya guarda eventos guarda las sesiones de
     * su agente en el MISMO log, y ahí es donde tiene que estar — un segundo almacén sería una segunda
     * verdad sobre lo que pasó. Si no hay ninguno registrado, se cae a un JSONL bajo `var/`, porque
     * una app recién creada tiene que poder tener memoria sin configurar nada.
     *
     * Devuelve `null` sólo si el paquete no está instalado: sin sesiones, `agent` sigue contestando
     * exactamente como antes. Perder la memoria es peor que no tenerla, pero no poder preguntar nada
     * es peor que las dos.
     */
    /**
     * Cuándo se compacta esta app, y con qué resumidor (P16.2).
     *
     * Los umbrales salen de `config/app.php` (`agent.compaction`) porque dependen del modelo: una
     * ventana de 8k y una de 200k no se compactan igual, y cablear un número aquí obligaría a la mitad
     * de las apps a editarlo. Los defaults —40 turnos sin resumir, 12 conservados— están pensados para
     * un modelo mediano; lo importante es que `keepRecent` sea holgado, porque el resumen contesta
     * «qué ha pasado» y sólo los turnos íntegros contestan «en qué íbamos».
     *
     * Es `protected` para poder fijarlo desde una prueba sin generar cuarenta turnos de verdad.
     */
    protected function compactor(): Compactor
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $ajustes = $config instanceof Config ? $config->get('agent.compaction') : null;

        $maximo = 40;
        $recientes = 12;
        if (\is_array($ajustes)) {
            $maximo = \is_int($ajustes['maxTurns'] ?? null) ? $ajustes['maxTurns'] : $maximo;
            $recientes = \is_int($ajustes['keepRecent'] ?? null) ? $ajustes['keepRecent'] : $recientes;
        }

        return new Compactor($maximo, $recientes);
    }

    /**
     * El almacén de sesiones de esta app, para quien lo necesite desde fuera.
     *
     * Existe para que {@see SessionOperations} lea y escriba EXACTAMENTE donde esta operación lo hace:
     * dos lugares que decidan dónde viven las sesiones son dos lugares donde pueden dejar de
     * coincidir, y el día que lo hicieran `agent:answer` contestaría en una sesión que `agent` no
     * está leyendo.
     */
    public function sessionStore(): ?SessionStore
    {
        return $this->sessions();
    }

    /**
     * Cuánto tiempo tiene un humano para contestar antes de que la sesión se declare muerta.
     *
     * Se lee de `agent.permissionWindow` como una cadena de {@see \DateInterval} —`PT4H`, `P1D`— y
     * **sin default**: si el host no la declara, las preguntas esperan para siempre, que es lo que
     * hacían antes de que esto existiera.
     *
     * No poner un default es deliberado. Cuánto tiempo es razonable depende de quién opera el agente
     * —una jornada, un turno, un fin de semana— y un número inventado aquí mataría sesiones de gente
     * que nunca lo eligió. Lo que este código garantiza es que la ventana se PUEDA poner y que
     * vencerla sea un hecho declarado ({@see SessionStore::expireIfDue()}), no que exista una.
     */
    private function permissionWindow(): ?\DateInterval
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declarada = $config instanceof Config ? $config->get('agent.permissionWindow') : null;
        if (!\is_string($declarada) || $declarada === '') {
            return null;
        }

        try {
            return new \DateInterval($declarada);
        } catch (\Throwable) {
            // Una ventana ilegible NO se convierte en una inventada: se ignora y las preguntas siguen
            // sin plazo. Adivinar aquí sería matar sesiones por un typo en un archivo de config.
            return null;
        }
    }

    protected function sessions(): ?SessionStore
    {
        if (!class_exists(SessionStore::class)) {
            return null;
        }

        // CUANDO HAY SUPERFICIE, EL ALMACÉN LO CONSTRUIMOS NOSOTROS.
        //
        // `DIContainer::has()` contesta que sí tanto a lo que alguien declaró como a lo que el
        // contenedor puede FABRICAR, y `SessionStore` es fabricable en cuanto haya un
        // `EventStoreInterface` registrado. Preguntando primero por él, el contenedor devolvería una
        // sesión armada por su cuenta —sin puente—, el agente seguiría escribiendo y el tablero se
        // quedaría quieto sin que nada fallara. Lo encontró la prueba de cableado, no el diseño.
        $superficie = $this->broadcaster();

        if ($superficie !== null && $this->container->has(EventStoreInterface::class)) {
            $eventos = $this->container->get(EventStoreInterface::class);
            if ($eventos instanceof EventStoreInterface) {
                return new SessionStore(new BroadcastingEventStore($eventos, $superficie));
            }
        }

        if ($this->container->has(SessionStore::class)) {
            $declarado = $this->container->get(SessionStore::class);
            if ($declarado instanceof SessionStore) {
                return $declarado;
            }
        }

        if ($this->container->has(EventStoreInterface::class)) {
            $eventos = $this->container->get(EventStoreInterface::class);
            if ($eventos instanceof EventStoreInterface) {
                return new SessionStore($eventos);
            }
        }

        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return null;
        }

        $directorio = $kernel->root() . '/var';
        if (!is_dir($directorio) && !mkdir($directorio, 0o775, true) && !is_dir($directorio)) {
            return null;
        }

        return new SessionStore($this->conPuente(new FileEventStore($directorio . '/agent-sessions.jsonl')));
    }

    /**
     * El mismo almacén, empujando a la superficie — si hay alguien a quien empujarle.
     *
     * ── POR QUÉ AQUÍ Y NO EN UN «BOOT» APARTE ───────────────────────────────────────────────────
     *
     * Porque éste es el ÚNICO lugar donde se decide qué almacén usa una sesión. Un puente montado en
     * otra parte tendría que adivinar cuál de los tres caminos de arriba se tomó, y el día que no
     * coincidiera dejaría de empujar sin que nada fallara: el agente seguiría escribiendo, el tablero
     * se quedaría quieto, y nadie tendría por qué sospechar. Es exactamente la forma del defecto que
     * este repositorio lleva un mes cazando —lo declarado que nunca aterriza—, y aterrizarlo aquí es
     * lo que impide que se repita.
     *
     * ── DOS FORMAS DE PRENDERLO, Y NINGUNA OBLIGA A INSTALAR NADA ───────────────────────────────
     *
     * La app puede registrar su propio {@see SurfaceBroadcaster} —cualquier transporte— o, si ya
     * tiene un hub Mercure en el contenedor, se envuelve solo. Sin ninguno de los dos, esto devuelve
     * el almacén intacto: una app sin tablero no paga nada, ni siquiera una dependencia.
     */
    private function conPuente(EventStoreInterface $eventos): EventStoreInterface
    {
        $superficie = $this->broadcaster();

        return $superficie === null ? $eventos : new BroadcastingEventStore($eventos, $superficie);
    }

    /** A quién se le empuja, si hay alguien. */
    private function broadcaster(): ?SurfaceBroadcaster
    {
        if (!class_exists(BroadcastingEventStore::class)) {
            return null;
        }

        if ($this->container->has(SurfaceBroadcaster::class)) {
            $declarado = $this->container->get(SurfaceBroadcaster::class);
            if ($declarado instanceof SurfaceBroadcaster) {
                return $declarado;
            }
        }

        // Sin puerto declarado, sirve el hub que ya esté configurado. Se pregunta por el NOMBRE y no
        // por la clase para no arrastrar `milpa/mercure` como dependencia de este proyecto.
        $hub = $this->container->has('Milpa\\Mercure\\MercureService')
            ? $this->container->get('Milpa\\Mercure\\MercureService')
            : null;

        return \is_object($hub) && method_exists($hub, 'publish') ? new MercureBroadcaster($hub) : null;
    }

    /**
     * Lo que el agente sabe de esta app antes de que nadie le pregunte nada.
     *
     * ── POR QUÉ NO ES UNA LÍNEA ─────────────────────────────────────────────────────────────────
     *
     * Era una: «usa las herramientas y no inventes resultados». Suena suficiente y no lo es, porque
     * las herramientas dicen QUÉ se puede hacer y no CÓMO está armada la app. Medido: a «quiero
     * guardar en sqlite en vez de json, dime qué cambio» el agente contestó que creara un
     * `SQLitePersistencePlugin` con `make plugin`. Inventó un plugin que no existe para resolver algo
     * que es una línea de `config/app.php` — y no estaba desobedeciendo, estaba llenando un hueco.
     * Un agente sin contexto no se calla: adivina.
     *
     * ── LAS DOS COSTURAS ────────────────────────────────────────────────────────────────────────
     *
     * `getPromptSections()` es la de siempre: vive en `ToolProviderInterface`, los stubs que este
     * framework genera ya traen su marcador `// {coa:tool-prompts}`, `PluginsManager` sabe juntarlas
     * — y NADIE las leía. Un plugin que contribuye herramientas también contribuye lo que hay que
     * saber para usarlas, y ese texto se estaba tirando. Es el quinto valor producido y descartado
     * que aparece esta semana, después de `merge`, `guidance`, el sabor del verify y el inventario.
     *
     * `agent.instructions` en `config/app.php` es la otra: lo que esta app en particular quiere que
     * su agente sepa y que no le toca a ningún plugin decir.
     *
     * Es `protected` por la misma razón que {@see ask()}: para poder mirarlo desde una prueba sin
     * salir a la red. Un prompt de sistema que nadie puede leer se convierte en el lugar donde se
     * acumulan afirmaciones que ya no son ciertas.
     */
    protected function systemPrompt(): string
    {
        $partes = [
            'Eres el agente de esta app Milpa. Usa las herramientas para responder; no inventes '
            . 'resultados. Si una herramienta contesta con `guidance`, esa guía es el siguiente paso '
            . 'real: repítela en vez de improvisar uno.',

            // Lo que un agente necesita para no inventar un plugin donde había una línea de config.
            "Cómo está armada esta app:\n"
            . "- Cada cosa que sabe hacer es una operación declarada; las herramientas que ves SON esas operaciones.\n"
            . "- Los plugins se declaran en `config/plugins.php`. Andamiar uno NO lo enciende: hay que agregar su clase a esa lista.\n"
            . "- La persistencia sale de `config/app.php`, bloque `storage`: `driver` es `file`, `sqlite`, `mysql` o `memory`\n"
            . "  (con su `path` o su `dsn`). Lo que `make entity` y `make crud` escriben ya lee ese bloque a través de\n"
            . "  `Milpa\\Data\\RepositoryFactory`, así que cambiar de backend es esa línea y nada más. NO hace falta un plugin\n"
            . "  de persistencia, y no existe uno.\n"
            . "- Doctrine es de la convención legacy, no de ésta. Las entidades que `make` escribe implementan\n"
            . '  `Milpa\\Data\\EntityInterface`: sin atributos de ORM, sin mapeo.',

            // Sin esto, las herramientas existen y no se usan. Un modelo que puede anotar su plan y no
            // sabe que le conviene, no lo anota — y el plan es lo único que sobrevive a una
            // compactación para decirle qué sigue.
            "Cuando el trabajo lleve más de dos o tres pasos:\n"
            . "- Escribe un plan con `plan` ANTES de empezar, y agrega un pendiente con `todo` por cada parte.\n"
            . "- Marca `todo` con status `done` EN CUANTO termines cada una, no al final.\n"
            . '- Si una sesión ya trae plan y pendientes, sigue ésos en vez de escribir otros: son tuyos, de antes.',
        ];

        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;

        // El resumen de arquitectura, APAGADO por default (`agent.architectureSummary`).
        //
        // Apagado no por timidez: su efecto está SIN MEDIR — Q-P17-E pregunta cuánta arquitectura
        // derivada hay que entregar sin que la pidan, y encenderlo por default antes de contestar
        // sería el verde sin medición que este repositorio lleva semanas negándose a escribir. La
        // bandera existe para que el experimento tenga sus dos condiciones sobre el MISMO binario;
        // si la medición lo sostiene, el default cambia y la bandera deja de hacer falta.
        // `true` da el resumen entero; `'pointer'` da SÓLO la línea que nombra la herramienta, en la
        // MISMA ranura del prompt. Lo segundo existe para medir de dónde viene el efecto que Q-P17-G
        // observó: si el puntero solo basta, el resumen son 68 tokens que no compran nada. Misma
        // ranura y mismo texto a propósito — en otra posición, una diferencia podría deberse a dónde
        // está y no a qué dice.
        $modo = $config instanceof Config ? $config->get('agent.architectureSummary') : null;
        if ($modo === true || $modo === 'pointer' || $modo === 'state') {
            $proyector = new ArchitectureSummaryProjector();
            $resumen = match ($modo) {
                'pointer' => $proyector->pointerOnly(),
                'state' => $proyector->stateOnly($this->architectureReport(), $this->providedCapabilities()),
                default => $proyector->section($this->architectureReport(), $this->providedCapabilities()),
            };
            if ($resumen !== '') {
                $partes[] = $resumen;
            }
        }

        $propias = $config instanceof Config ? $config->get('agent.instructions') : null;
        if (\is_string($propias) && trim($propias) !== '') {
            $partes[] = trim($propias);
        }

        // El filtro vive AQUÍ y no sólo en quien recolecta: las partes se unen con una línea en
        // blanco, así que una sección vacía se vuelve un hueco donde el modelo espera contenido.
        // `PluginsManager` intercala cadenas vacías como separador entre plugins, y cualquier host que
        // arme su propia lista puede hacer lo mismo — el ensamblador tiene que aguantarlo.
        foreach ($this->promptSectionsOfPlugins() as $seccion) {
            if (trim($seccion) !== '') {
                $partes[] = trim($seccion);
            }
        }

        return implode("\n\n", $partes);
    }

    /**
     * Las capacidades que los plugins arrancados DECLARAN proveer.
     *
     * Aparte del reporte porque el reporte no las tiene: la resolución la mueven los requisitos, así
     * que una capacidad que nadie pide no aparece ni en `resolved` ni en `missing`. Sin esta lista, el
     * punto ciego que Q-P17-C midió —provista por dos, consumida por nadie— seguiría invisible
     * también aquí.
     *
     * @return list<string>
     */
    protected function providedCapabilities(): array
    {
        $ids = [];
        foreach ($this->pluginMetadata() as $meta) {
            foreach ($meta->provides as $entrada) {
                $id = \is_string($entrada) ? $entrada : ($entrada['id'] ?? null);
                if (\is_string($id) && $id !== '' && !\in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * El `#[PluginMetadata]` de cada plugin ARRANCADO.
     *
     * @return list<PluginMetadata>
     */
    protected function pluginMetadata(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $metas = [];
        foreach ($kernel->plugins() as $plugin) {
            $atributos = (new \ReflectionClass($plugin))->getAttributes(PluginMetadata::class);
            if ($atributos !== []) {
                $metas[] = $atributos[0]->newInstance();
            }
        }

        return $metas;
    }

    /**
     * El grafo de esta app como reporte, o `null` si no se puede saber.
     *
     * Se diagnostica desde los plugins ARRANCADOS —igual que {@see promptSectionsOfPlugins()}— y no
     * desde `config/plugins.php`: lo que importa es lo que botó, porque un plugin declarado y vetado
     * no aporta capacidades y su línea hablaría de un grafo que no existe.
     *
     * Nunca lanza. Un prompt es lo último que puede tumbar una sesión: si el grafo no se puede
     * diagnosticar, el agente trabaja sin resumen igual que antes de que esto existiera, y sigue
     * teniendo `plugins_architecture` a una llamada. Devolver `null` es decir «no sé», y el proyector
     * ya sabe que eso se calla en vez de rellenar.
     */
    protected function architectureReport(): ?ResolutionReport
    {
        try {
            $registros = [];
            foreach ($this->pluginMetadata() as $meta) {
                $registros[] = [
                    'name' => $meta->name,
                    'version' => $meta->version,
                    'type' => $meta->type,
                    'provides' => array_values($meta->provides),
                    'requires' => array_values($meta->requires),
                    'suggests' => array_values($meta->suggests),
                ];
            }

            return $registros === [] ? null : (new MetadataGraphResolver())->diagnose($registros);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lo que cada plugin arrancado quiere que el agente sepa sobre sus herramientas.
     *
     * Se camina el kernel y no `PluginsManager` porque lo que importa es lo que BOOTEÓ: un plugin
     * declarado y vetado no tiene herramientas en el registro, así que su sección hablaría de algo que
     * el agente no puede llamar.
     *
     * @return list<string>
     */
    protected function promptSectionsOfPlugins(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $secciones = [];
        foreach ($kernel->plugins() as $plugin) {
            if (!$plugin instanceof ToolProviderInterface) {
                continue;
            }

            foreach ($plugin->getPromptSections() as $seccion) {
                // Las vacías se tiran: `PluginsManager` intercala cadenas vacías como separador entre
                // plugins, y un separador que llegue al prompt como sección deja un hueco donde el
                // modelo espera contenido.
                if (trim($seccion) !== '') {
                    $secciones[] = trim($seccion);
                }
            }
        }

        return $secciones;
    }

    /**
     * A dónde va el agente: el proveedor público, o el endpoint que esta app declare.
     *
     * `MILPA_AGENT_BASE_URL` apunta a cualquier cosa compatible con la API de OpenAI — un Ollama en
     * la LAN, un vLLM, un proxy. Existe por dos razones que se parecen poco: probar el bucle sin
     * gastarle tokens a un proveedor público, y correrlo con datos que no pueden salir de la casa.
     */
    private function baseUrl(): ?string
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declarado = $config instanceof Config ? $config->get('agent.baseUrl') : null;
        if (\is_string($declarado) && $declarado !== '') {
            return $declarado;
        }

        $entorno = getenv('MILPA_AGENT_BASE_URL');

        return \is_string($entorno) && $entorno !== '' ? $entorno : null;
    }

    /**
     * Encabezados extra para el endpoint — auth básica, si la hay.
     *
     * `MILPA_AGENT_BASIC_AUTH` en la forma `usuario:contraseña`. Un endpoint local detrás de auth
     * básica no acepta el Bearer del proveedor, y sin esto la única salida era no usarlo.
     *
     * @return array<string,string>
     */
    private function extraHeaders(): array
    {
        $basica = getenv('MILPA_AGENT_BASIC_AUTH');
        if (!\is_string($basica) || !str_contains($basica, ':')) {
            return [];
        }

        return ['Authorization' => 'Basic ' . base64_encode($basica)];
    }

    /**
     * La llave, el proveedor y el modelo — de las variables de entorno, o `null` si no hay ninguna.
     *
     * Anthropic primero porque es el default de la casa. El modelo se puede fijar en `config/app.php`
     * (`agent.model`) y si no se usa el de cada proveedor.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function credential(): ?array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $modeloDeclarado = $config instanceof Config ? $config->get('agent.model') : null;

        // Un endpoint propio manda: quien apuntó su agente a un modelo local no quiere que una
        // ANTHROPIC_API_KEY olvidada en el entorno lo mande a otro lado —y a cobrar.
        if ($this->baseUrl() !== null) {
            $llaveLocal = getenv('MILPA_AGENT_API_KEY');

            return [
                'openai',
                \is_string($llaveLocal) && $llaveLocal !== '' ? $llaveLocal : 'local',
                \is_string($modeloDeclarado) ? $modeloDeclarado : (getenv('MILPA_AGENT_MODEL') ?: 'qwen3-coder:30b'),
            ];
        }

        $anthropic = getenv('ANTHROPIC_API_KEY');
        if (\is_string($anthropic) && $anthropic !== '') {
            return ['anthropic', $anthropic, \is_string($modeloDeclarado) ? $modeloDeclarado : 'claude-sonnet-4-5'];
        }

        $openai = getenv('OPENAI_API_KEY');
        if (\is_string($openai) && $openai !== '') {
            return ['openai', $openai, \is_string($modeloDeclarado) ? $modeloDeclarado : 'gpt-4o'];
        }

        return null;
    }

    /**
     * Las operaciones de esta app, proyectadas como herramientas — las MISMAS que ve un cliente MCP.
     *
     * Se proyecta contra un registry nuevo y no contra el del kernel: el del kernel puede traer
     * herramientas que un plugin registró con `#[Tool]`, y las queremos también, pero armar el
     * catálogo aquí es lo que garantiza que el agente vea lo mismo que `bin/mcp-server.php` expone.
     */
    /**
     * @param list<Operation> $extra operaciones que sólo existen para esta corrida — hoy, las que
     *                               atan el plan y los pendientes a la sesión en curso
     */
    private function toolsOfThisApp(array $extra = []): ?ToolRegistry
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return null;
        }

        $registry = $kernel->toolRegistry();
        if (!$registry instanceof ToolRegistry) {
            $registry = new ToolRegistry(new NullLogger());
        }

        // TODO lo que esta app declara, no sólo lo que los plugins arrancaron: `kernel->commands()`
        // trae las operaciones de los plugins booteados y deja fuera las de `config/operations.php`.
        // Con eso, el agente veía OCHO herramientas mientras `coa` ofrecía doce — `make` y `validate`
        // entre las que faltaban, que son justo las que sirven para construir algo. Dos inventarios
        // de la misma app es exactamente lo que `Operations` existe para evitar, y aquí no se estaba
        // usando.
        $todas = [...Operations::all($kernel, $kernel->root()), ...$extra];

        // Sólo lo que TODAVÍA no está. Proyectar dos veces sobre el mismo registro lanza
        // `ToolAlreadyRegisteredException`, y eso convertía la segunda llamada al agente —en el mismo
        // proceso— en una excepción. Lo encontró una prueba que llama dos veces; en producción lo
        // habría encontrado quien le preguntara dos cosas seguidas.
        $faltantes = array_values(array_filter(
            $todas,
            static fn ($op): bool => $registry->getDefinition(McpProjector::toolName($op->name)) === null,
        ));

        if ($faltantes !== []) {
            (new McpProjector())->projectAll($faltantes, $registry, $kernel->container());
        }

        return $registry;
    }
}
