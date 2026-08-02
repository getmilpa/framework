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

namespace App\Tui;

use Milpa\Agent\Session;
use Milpa\Agent\TodoStatus;
use Milpa\Live\Tui\NodeRenderers\BoxRenderer;
use Milpa\Live\Tui\NodeRenderers\StatusBarRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use App\Agent\SurfaceBroadcaster;
use Milpa\Live\Contracts\Tui\TerminalInterface;
use Milpa\Live\Tui\RetainedTuiLoop;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * La conversación con el agente de esta app, en la terminal.
 *
 * Escribes lo que quieres, Enter, y el agente trabaja con las operaciones de esta app. Lo que esta
 * pantalla agrega sobre `coa agent "…"` no es cosmético: se VE la conversación acumulada y se ve
 * cuántos pasos lleva, así que preguntar dos cosas seguidas no obliga a volver a explicar la primera.
 *
 * ── EL BUCLE NO VIVE AQUÍ ───────────────────────────────────────────────────────────────────────
 *
 * La pantalla recibe una función que responde. `AgentOperations` es quien sabe armar el orquestador,
 * elegir proveedor y catálogo, y decir qué falta cuando no hay llave — repetirlo aquí sería un
 * segundo camino a lo mismo, que es como se llega a que la terminal y el TUI contesten distinto.
 *
 * ── ES SÍNCRONA, Y SE DICE ──────────────────────────────────────────────────────────────────────
 *
 * Mientras el agente piensa, la terminal no repinta: PHP no tiene hilos aquí y este bucle es de un
 * solo camino. Por eso el frame ANTES de preguntar dice «pensando…», y no un spinner que no gira:
 * una interfaz que finge actividad que no ocurre entrena a no creerle.
 */
final class AgentScreen implements SurfaceBroadcaster
{
    private readonly RetainedTuiLoop $loop;

    private string $entrada = '';

    /** @var list<array{quien: string, texto: string}> */
    private array $conversacion = [];

    /**
     * En qué está la pantalla AHORA: `null` cuando espera al humano, o la línea que describe el
     * trabajo en curso.
     *
     * Deja de ser un booleano porque «pensando» y «corriendo `plugins_list`» son hechos distintos, y
     * el segundo es el que le dice a quien mira que el sistema NO está colgado: un nombre de
     * herramienta cambiando es actividad observable, un texto fijo no.
     */
    private ?string $actividad = null;

    /**
     * Cuántos granos de la M van encendidos. Trece es la M completa.
     *
     * Arranca completa a propósito: la animación se ve si alguien la mira crecer, y quien abre el
     * chat ya estaba mirando. Lo que germina en cada frame es el spinner del trabajo, no el logo —
     * una marca que se re-dibuja sola cada vez distrae de lo que sí está cambiando.
     */
    private int $granos = 13;

    /** Los tokens que esta sesión lleva gastados, tal como los reporta cada vuelta. */
    private int $tokensGastados = 0;

    /** Si ya se trajo a la pantalla lo que la sesión traía — una sola vez por pantalla. */
    private bool $rehidratada = false;

    /** Si la pantalla está en el selector de `/sessions`. */
    private bool $eligiendoSesion = false;

    /** Cuál fila del selector está marcada. */
    private int $cursorSesiones = 0;

    /**
     * Cómo se pinta un frame sin salir del bucle.
     *
     * Existe porque esta pantalla es SÍNCRONA: mientras el agente trabaja, el bucle no vuelve a
     * pasar. Sin esto, el frame que dice «pensando…» se calcula y nunca se escribe — que es
     * exactamente lo que pasaba: el docblock de arriba lo afirmaba y el código lo desmentía, con la
     * bandera puesta y quitada dentro del mismo tick.
     */
    private ?\Closure $repintar = null;

    /** Lo que costó la última vuelta, ya redactado — `null` mientras no haya habido ninguna. */
    private ?string $ultimoCosto = null;

    /**
     * @param \Closure(string): array{ok: bool, answer?: string, steps?: int, tools?: int, compacted?: bool, error?: string, hint?: string} $responder
     * @param \Closure(): (Session|null)|null                                                                                               $sesion    la sesión en curso, releída
     *                                                                                                                                                 en cada frame — cambia
     *                                                                                                                                                 después de cada vuelta
     * @param \Closure(string): array{ok: bool, granted?: string|null, error?: string}|null                                                 $contestar cómo se responde una
     *                                                                                                                                                 pregunta pendiente
     */
    public function __construct(
        private readonly \Closure $responder,
        private readonly ?\Closure $sesion = null,
        private readonly ?\Closure $contestar = null,
        int $width = 80,
        private readonly int $height = 24,
        bool $ansi = true,
        /**
         * Con qué se abre: modelo, herramientas disponibles, id de la sesión.
         *
         * Se recibe armado y no se deduce aquí a propósito — quién es el proveedor y cuántas
         * herramientas tiene esta app lo sabe quien montó el agente, y esta pantalla sólo lo pinta.
         *
         * @var array{model?: string, tools?: int, session?: string, nueva?: bool}
         */
        private readonly array $bienvenida = [],
        /** Cómo se enumeran las sesiones para `/sessions`; `null` si esta app no guarda ninguna. */
        private readonly ?\Closure $catalogo = null,
        /** Cómo se cambia de sesión al elegir una en `/sessions`. */
        private readonly ?\Closure $continuar = null,
    ) {
        $this->loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), self::renderers()),
            fn (): TuiNode => $this->tree(),
            ['prompt'],
            'prompt',
            $width,
            $this->height,
            $ansi,
            fn (string $key, RetainedTuiLoop $loop): bool => $this->handleKey($key, $loop),
            // Sin `q` entre las teclas de salida: el default del tier la incluye —lo que un dashboard
            // quiere— y aquí se teclea texto. Con ella, una `q` escrita en un campo cerraba la
            // pantalla en vez de escribirse, y no había forma de teclear «query» ni «plugin».
            quitKeys: ['escape', 'ctrl+c'],
        );
    }

    private static function renderers(): TuiNodeRendererRegistry
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        // La M de Milpa germinando: sin su renderer, el nodo existe y se pinta como nada — el
        // defecto silencioso de siempre, un árbol que declara algo que ningún renderer atiende.
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\MilpaLogoRenderer());
        $registry->register(new \Milpa\Live\Tui\NodeRenderers\SpacerRenderer());
        $registry->register(new BoxRenderer());
        // La barra de estado viene del catálogo de componentes de `milpa/live-tui`, no de aquí: una
        // pantalla que dibuja su propia barra es una barra que se ve distinta en cada pantalla.
        $registry->register(new StatusBarRenderer());

        return $registry;
    }

    /** El loop armado, para correrlo contra una terminal. */
    public function loop(): RetainedTuiLoop
    {
        return $this->loop;
    }

    /** La pantalla completa como texto, sin necesitar una terminal. */
    /**
     * Un hecho de la sesión, ya traducido, mientras está pasando.
     *
     * ── ES LA MISMA TUBERÍA QUE EL TABLERO ──────────────────────────────────────────────────────
     *
     * Esta pantalla no tiene un canal propio: se registra como {@see SurfaceBroadcaster} y recibe lo
     * que `BroadcastingEventStore` empuja al apendar cada hecho — exactamente lo que recibiría una
     * página web suscrita al mismo tópico. Una segunda vía para «lo que el agente está haciendo»
     * sería la copia que la spec del tablero prohíbe, y divergiría en el hecho que nadie probó.
     *
     * Filtra `activity` y **ignora lo demás** a propósito. Una tarjeta que se mueve es del tablero;
     * aquí no hay dónde pintarla, y fingir que sí sería inventar una vista.
     */
    public function broadcast(string $topic, array $payload): void
    {
        if (($payload['kind'] ?? null) !== 'activity') {
            return;
        }

        $estado = $payload['activity']['state'] ?? null;
        $detalle = $payload['activity']['detail'] ?? null;

        $this->anunciar(match ($estado) {
            'tool' => '  ' . (\is_string($detalle) ? $detalle : 'herramienta')
                . (($payload['activity']['mutating'] ?? false) === true ? ' · toca algo' : '') . '…',
            'ready' => 'ordenando la respuesta…',
            // `thinking` y cualquier estado que este paquete todavía no conozca caen aquí: decir «el
            // modelo tiene la palabra» ante algo desconocido es menos preciso que un nombre, pero es
            // cierto — el hecho llegó, así que algo está pasando.
            default => 'el modelo tiene la palabra…',
        });
    }

    /**
     * Decir en qué se está, y ENSEÑARLO.
     *
     * Las dos mitades son una sola cosa: cambiar el estado sin pintar es lo que hacía la versión
     * anterior, y desde afuera es indistinguible de no haber cambiado nada. Si nadie dio con qué
     * pintar —una tubería, una prueba— el estado igual queda escrito y `render()` lo muestra.
     */
    private function anunciar(string $que): void
    {
        $this->actividad = $que;

        if ($this->repintar !== null) {
            ($this->repintar)();
        }
    }

    /** Quien tiene la terminal dice cómo escribir un frame; la pantalla no la conoce (ADR-0025). */
    public function paintWith(\Closure $repintar): void
    {
        $this->repintar = $repintar;
    }

    /**
     * La forma normal de lo anterior: pintar en ESTA terminal.
     *
     * Recibe la terminal, no la busca — la pantalla sigue sin saber si hay una, que es lo que la hace
     * probable sin ella. Y usa `nextFrameBytes()`, el mismo diff que escribe el bucle en cada vuelta:
     * un frame pintado a media espera no pelea con el siguiente, ES el siguiente, adelantado.
     */
    public function paintOn(TerminalInterface $terminal): void
    {
        $this->paintWith(function () use ($terminal): void {
            $bytes = $this->loop->nextFrameBytes();
            if ($bytes !== '') {
                $terminal->write($bytes);
            }
        });
    }

    public function render(): string
    {
        return $this->loop->renderScreen();
    }

    /** Manda una tecla, como si alguien la hubiera tecleado. */
    public function press(string $key): bool
    {
        return $this->loop->dispatchKey($key);
    }

    /**
     * Lo dicho hasta ahora, por turnos.
     *
     * @return list<array{quien: string, texto: string}>
     */
    public function conversation(): array
    {
        return $this->conversacion;
    }

    private function handleKey(string $key, RetainedTuiLoop $loop): bool
    {
        // EL SELECTOR MANDA MIENTRAS ESTÁ ABIERTO. Escribir con una lista enfrente sería teclear a
        // ciegas contra dos destinos a la vez.
        if ($this->eligiendoSesion) {
            $filas = $this->catalogo !== null ? ($this->catalogo)() : [];

            if ($key === 'escape') {
                $this->eligiendoSesion = false;
                $this->loop->repintarTodo();

                return true;
            }
            if ($key === 'down') {
                $this->cursorSesiones = min(max(0, \count($filas) - 1), $this->cursorSesiones + 1);

                return true;
            }
            if ($key === 'up') {
                $this->cursorSesiones = max(0, $this->cursorSesiones - 1);

                return true;
            }
            if ($key === 'enter') {
                $elegida = $filas[$this->cursorSesiones]['id'] ?? null;
                if (\is_string($elegida) && $this->continuar !== null) {
                    ($this->continuar)($elegida);
                    // La conversación se limpia: lo que se ve tiene que ser de la sesión que se
                    // acaba de abrir. Dejar los turnos de la anterior mezclaría dos trabajos en una
                    // pantalla, que es justo lo que separar las sesiones vino a evitar.
                    $this->conversacion = [];
                    $this->tokensGastados = 0;
                    // Y se vuelve a permitir traer lo que la sesión elegida ya vivió: sin esto, la
                    // pantalla mostraría vacía una conversación que sí existe.
                    $this->rehidratada = false;
                }
                $this->eligiendoSesion = false;
                $this->loop->repintarTodo();

                return true;
            }

            return true;
        }

        if ($key === 'enter') {
            $this->preguntar();

            return true;
        }

        if ($key === 'backspace') {
            $this->entrada = mb_substr($this->entrada, 0, -1);

            return true;
        }

        // El crudo, no el nombre canónico: éste viene en minúscula para que un atajo declarado como
        // `l` case con `L`, y una pregunta al agente sin mayúsculas se lee como un telegrama.
        $crudo = $loop->lastRawKey();
        if (mb_strlen($crudo) === 1 && preg_match('/^[[:print:]]$/u', $crudo) === 1) {
            $this->entrada .= $crudo;

            return true;
        }

        return false;
    }

    private function preguntar(): void
    {
        $pregunta = trim($this->entrada);
        if ($pregunta === '') {
            return;
        }

        // `/sessions` es de la PANTALLA, no del agente: preguntarle a un modelo cuáles sesiones hay
        // sería pedirle que adivine algo que el stream sabe con certeza.
        if ($pregunta === '/sessions' || $pregunta === '/sesiones') {
            $this->entrada = '';
            $this->eligiendoSesion = true;
            $this->cursorSesiones = 0;
            // El árbol cambia de FORMA, no sólo de contenido: la lista tiene menos filas que la
            // conversación que tapa, y el pintado por diferencias dejaría las de abajo intactas —
            // el selector saldría montado sobre la charla anterior.
            $this->loop->repintarTodo();

            return;
        }

        $this->conversacion[] = ['quien' => 'tú', 'texto' => $pregunta];
        $this->entrada = '';

        // CONTESTAR es lo primero. Con una pregunta abierta, la sesión no es corrible: mandarla al
        // agente devolvería «está esperando una respuesta» y habría que salirse del TUI a correr
        // `coa agent:answer`. El lugar natural para contestar es donde te la están preguntando.
        $sesion = $this->sesionActual();
        if ($sesion?->question !== null && $this->contestar !== null) {
            $eco = ($this->contestar)($pregunta);
            $this->conversacion[] = [
                'quien' => 'agente',
                'texto' => ($eco['ok'])
                    ? '✓ contestado' . (($eco['granted'] ?? null) !== null ? ' · autorizado: ' . $eco['granted'] : '')
                        . ' — pídeme que siga'
                    : '✗ ' . ($eco['error'] ?? ''),
            ];

            return;
        }

        // UN SOLO ESTADO, Y ES EL QUE SE PUEDE SOSTENER.
        //
        // Lo que hace falta de verdad son cuatro —enviando, esperando al modelo, corriendo tal
        // herramienta, listo— y los tres primeros ya son hechos que el stream escribe. Pero llegan
        // por el `EventStore`, no por el valor de retorno, y traerlos hasta aquí es el siguiente
        // corte (promesa `tui-says-what-it-is-doing`).
        //
        // Se anuncia UNO en vez de fingir cuatro: un estado que no cambia mientras el agente llama
        // tres herramientas dice menos de lo que quisiéramos, pero no miente. Un spinner que gira
        // sin saber nada sí — y entrena a no creerle a la pantalla.
        $this->anunciar('preguntando al agente… (Ctrl-C para salir)');

        $respuesta = ($this->responder)($pregunta);

        $this->actividad = null;

        // REPINTAR ENTERO AL VOLVER, y no es paranoia: mientras el agente trabajaba corrió código de
        // terceros —herramientas, plugins, el cliente del modelo— y cualquiera pudo escribir en la
        // terminal. El bucle pinta por diferencias contra lo que cree que hay; una línea ajena deja
        // esas filas «sin cambios» y por tanto sin repintar nunca. Aquí es donde se sabe que hubo
        // riesgo, así que aquí se olvida lo que se creía y se vuelve a pintar todo.
        $this->loop->repintarTodo();

        if ($respuesta['ok']) {
            // ESTIMADOS, y por eso se dicen con «≈». El proveedor sabe el número exacto y la
            // operación no lo devuelve; inventar precisión sería peor que dar la magnitud. Cuatro
            // caracteres por token es la regla de dedo de siempre — sirve para saber si una sesión
            // va por cien o por cien mil, que es la pregunta real.
            $this->tokensGastados += (int) ceil((mb_strlen($pregunta) + mb_strlen((string) ($respuesta['answer'] ?? ''))) / 4);
            $this->ultimoCosto = 'última vuelta: ' . (int) ($respuesta['steps'] ?? 0) . ' paso(s) · '
                . (int) ($respuesta['tools'] ?? 0) . ' herramientas'
                . (($respuesta['compacted'] ?? false) ? ' · se compactó' : '');
        }

        $this->conversacion[] = $respuesta['ok']
            ? [
                'quien' => 'agente',
                'texto' => (string) ($respuesta['answer'] ?? '')
                    . '   [' . (int) ($respuesta['steps'] ?? 0) . ' paso(s) · ' . (int) ($respuesta['tools'] ?? 0) . ' herramientas]',
            ]
            : [
                'quien' => 'agente',
                // El motivo y la pista, tal cual vienen: quien las lee necesita esa frase para saber
                // qué arreglar, y reformularla la empeora.
                'texto' => '✗ ' . ($respuesta['error'] ?? 'no pudo contestar')
                    . (($respuesta['hint'] ?? null) !== null ? "\n  " . $respuesta['hint'] : ''),
            ];
    }

    /**
     * Si la sesión no tiene todavía nada que mostrar.
     *
     * La portada reemplaza a la pantalla vacía, NO al estado: una sesión retomada con plan,
     * pendientes o una pregunta esperando tiene que enseñar eso en cuanto abre — es lo que hace
     * retomable una jornada larga, y taparlo con una bienvenida sería perder el hilo por decorar.
     */
    private function sesionEnBlanco(): bool
    {
        $sesion = $this->sesionActual();

        return $sesion === null
            || ($sesion->turns === []
                && $sesion->question === null
                && $sesion->plan === null
                && $sesion->todos === []);
    }

    /** La portada: la M germinando, con qué se está trabajando, y por dónde empezar. */
    private function portada(): TuiNode
    {
        $modelo = (string) ($this->bienvenida['model'] ?? 'sin modelo declarado');
        $herramientas = (int) ($this->bienvenida['tools'] ?? 0);
        $id = (string) ($this->bienvenida['session'] ?? '');
        $nueva = (bool) ($this->bienvenida['nueva'] ?? true);

        // SE ARMA POR PRESUPUESTO, no por deseo. Esta pantalla mide lo que mide, y una portada que
        // no cabe colapsa el árbol entero — el mismo defecto que este archivo ya tuvo con las
        // respuestas largas, cometido otra vez al decorar. Lo que cede primero es el adorno: el
        // logo y el aire; lo que nunca cede es el estado, el prompt y con qué se está trabajando.
        $datos = [
            new TuiNode('m-modelo', 'text', props: ['text' => '  modelo        ' . $modelo]),
            new TuiNode('m-tools', 'text', props: ['text' => '  herramientas  ' . $herramientas . ' operaciones de esta app']),
            new TuiNode('m-tokens', 'text', props: ['text' => '  gastado       ≈' . $this->tokensGastados . ' tokens (estimado) en esta sesión']),
            new TuiNode('m-sesion', 'text', props: [
                'text' => '  sesión        ' . ($id !== '' ? $id : '—') . ($nueva ? '  (nueva)' : '  (retomada)'),
            ]),
        ];

        $pie = [
            new TuiNode('estado', 'text', props: ['text' => $this->actividad === null ? '○ listo' : '◆ ' . $this->actividad]),
            new TuiNode('prompt', 'text', props: ['text' => '› ' . $this->entrada . '▏'], focusable: true),
            new TuiNode('pie', 'text', props: ['text' => '[Enter] preguntar · [Esc] salir']),
        ];

        $lema = [
            new TuiNode('lema', 'text', props: [
                'text' => 'El agente de esta app · trabaja con TUS operaciones',
                'align' => 'center',
            ]),
        ];

        $ayudas = [
            new TuiNode('sep-2', 'text', props: ['text' => str_repeat('─', 44)]),
            new TuiNode('ayuda-1', 'text', props: ['text' => '  escribe y Enter para preguntar']),
            new TuiNode('ayuda-2', 'text', props: ['text' => '  /sessions  elegir otra sesión y continuarla']),
            new TuiNode('ayuda-3', 'text', props: ['text' => '  coa chat --continue  retoma la última al abrir']),
        ];

        // La M germinando: cinco filas de granos que crecen de las esquinas de abajo al centro. Es
        // lo primero que se sacrifica si la terminal es chica — una marca que tapa el estado deja
        // de ser marca y pasa a ser estorbo.
        $logo = [
            // `height: 5` porque la M es una rejilla de 5×5 y el renderer RECORTA a lo que el
            // layout le dé: sin declararlo le tocaban tres filas y la marca salía descabezada.
            new TuiNode('logo', 'milpa-logo', props: [
                'frame' => $this->granos,
                'grain' => '◆',
                'empty' => ' ',
                'align' => 'center',
                'height' => 5,
                'lines' => 5,
            ]),
            new TuiNode('aire-2', 'spacer', props: ['height' => 1, 'lines' => 1]),
        ];

        $fijo = \count($datos) + \count($pie) + \count($lema) + 1;
        $hijos = $lema;
        $hijos[] = new TuiNode('sep', 'text', props: ['text' => str_repeat('─', 44)]);

        // 5 filas del logo más su aire; sólo si sobra sitio para él Y para las ayudas.
        if ($this->height >= $fijo + 6 + \count($ayudas)) {
            $hijos = [new TuiNode('aire-1', 'spacer', props: ['height' => 1, 'lines' => 1]), ...$logo, ...$hijos];
        }

        $hijos = [...$hijos, ...$datos];

        if ($this->height >= \count($hijos) + \count($ayudas) + \count($pie) + 1) {
            $hijos = [...$hijos, ...$ayudas];
        }

        return new TuiNode('raiz', 'container', props: ['direction' => 'vertical'], children: [...$hijos, ...$pie]);
    }

    /** El selector de `/sessions`: cuál retomar, con lo que hace falta para reconocerla. */
    private function selectorDeSesiones(): TuiNode
    {
        $filas = $this->catalogo !== null ? ($this->catalogo)() : [];
        $hijos = [
            new TuiNode('sel-titulo', 'text', props: ['text' => 'sesiones de esta app — ↑↓ para elegir, Enter para continuar, Esc para volver']),
            new TuiNode('sel-sep', 'text', props: ['text' => str_repeat('─', 60)]),
        ];

        if ($filas === []) {
            $hijos[] = new TuiNode('sel-vacio', 'text', props: ['text' => '  (ninguna todavía — la de ahora es la primera)']);
        }

        foreach (\array_slice($filas, 0, max(3, $this->height - 8)) as $i => $fila) {
            $marca = $i === $this->cursorSesiones ? '›' : ' ';
            $objetivo = (string) $fila['goal'];
            $hijos[] = new TuiNode("sel:{$i}", 'text', props: [
                'text' => sprintf(
                    '%s %-22s %-14s %2d turnos  %s',
                    $marca,
                    (string) $fila['id'],
                    (string) $fila['state'],
                    (int) $fila['turns'],
                    mb_strlen($objetivo) > 40 ? mb_substr($objetivo, 0, 39) . '…' : $objetivo,
                ),
            ]);
        }

        $hijos[] = new TuiNode('sel-pie', 'text', props: ['text' => '[Enter] continuar · [Esc] volver']);

        return new TuiNode('raiz', 'container', props: ['direction' => 'vertical'], children: $hijos);
    }

    /**
     * Trae a la pantalla lo que la sesión ya vivió — retomar sin ver es no retomar.
     *
     * La conversación es del PROCESO y los turnos son del STREAM: al abrir sobre una sesión que ya
     * existe, la pantalla arrancaba en blanco y `--continue` se sentía igual que empezar de cero,
     * aunque el agente sí llevara su historial. Se lee del stream porque es la única fuente — y se
     * traen los últimos, no todos: lo que cabe es la cola, igual que en el resto de esta pantalla.
     */
    private function rehidratar(): void
    {
        $sesion = $this->sesionActual();
        if ($sesion === null || $this->conversacion !== [] || $this->rehidratada) {
            return;
        }

        $this->rehidratada = true;
        // Los turnos de HERRAMIENTA no se traen como conversación. El stream los guarda —y hace
        // bien, son la evidencia de lo que el agente miró— pero su contenido es el JSON crudo que la
        // herramienta contestó: pegado en la charla es ruido que tapa lo que el humano vino a leer.
        // La sesión no pierde nada; `agent:show` y `agent:timeline` siguen enseñándolos enteros.
        $conversables = array_values(array_filter(
            $sesion->turns,
            static fn (array $t): bool => \in_array($t['role'], ['user', 'assistant'], true) && trim($t['content']) !== '',
        ));

        foreach (\array_slice($conversables, -6) as $turno) {
            $this->conversacion[] = [
                'quien' => $turno['role'] === 'user' ? 'tú' : 'agente',
                'texto' => $turno['content'],
            ];
        }
    }

    /** La sesión ahora mismo, o `null` si esta pantalla no corre sobre una. */
    private function sesionActual(): ?Session
    {
        if ($this->sesion === null) {
            return null;
        }

        $sesion = ($this->sesion)();

        return $sesion instanceof Session ? $sesion : null;
    }

    private function tree(): TuiNode
    {
        $this->rehidratar();

        // LA PORTADA, mientras nadie haya escrito nada. No es decoración: es donde se contesta «¿con
        // qué modelo estoy hablando y qué puede tocar?» sin teclear un comando. Un agente que no
        // dice con qué está pensando obliga a confiar sin poder verificar.
        if ($this->conversacion === [] && !$this->eligiendoSesion && $this->actividad === null && $this->sesionEnBlanco()) {
            return $this->portada();
        }

        if ($this->eligiendoSesion) {
            return $this->selectorDeSesiones();
        }

        $sesion = $this->sesionActual();

        $hijos = [new TuiNode('titulo', 'text', props: [
            'text' => $sesion !== null
                ? "sesión {$sesion->id} · {$sesion->mode->value}"
                : 'El agente de esta app · trabaja con tus operaciones',
        ])];

        // EL ESTADO ANTES QUE LA CONVERSACIÓN, y eso es P16.7 entero. Lo que hace usable una corrida
        // de cuarenta pasos no es releer lo que dijo: es ver en qué va. La transcripción es lo que
        // sobra cuando la pantalla es chica, no lo que se conserva.
        if ($sesion !== null) {
            foreach ($this->estado($sesion) as $i => $linea) {
                $hijos[] = new TuiNode("estado:{$i}", 'text', props: ['text' => $linea]);
            }
            $hijos[] = new TuiNode('sep-estado', 'text', props: ['text' => str_repeat('─', 40)]);
        }

        if ($this->conversacion === []) {
            $hijos[] = new TuiNode('vacio', 'text', props: [
                'text' => $sesion !== null && $sesion->question !== null
                    ? 'Está esperando tu respuesta.'
                    : 'Pregúntale algo. Por ejemplo: «¿qué plugins están encendidos?»',
            ]);
        }

        // Sólo la COLA de la conversación. Una sesión larga no cabe en la pantalla, y la parte que
        // importa es la de abajo — la de arriba ya está resumida en el estado.
        // RECORTADA POR LÍNEAS Y NO POR TURNOS, y ésa era la falla: seis turnos caben o no según lo
        // que midan, y una sola respuesta de modelo trae veinte a cuarenta líneas de markdown.
        // Pasado el alto del terminal el árbol colapsaba y la pantalla quedaba con la línea de ayuda
        // y nada más — sin excepción y sin stderr. El trabajo se había hecho bien y quien miraba
        // veía una pantalla en blanco, que es el peor modo de falla que puede tener una superficie.
        //
        // Se conserva el FINAL: lo último que dijo el agente es lo que hay que leer. Lo que se va no
        // se pierde —está en el stream, que es la bitácora— pero la pantalla tiene un tamaño.
        $lineas = [];
        foreach (\array_slice($this->conversacion, -6) as $i => $turno) {
            $marca = $turno['quien'] === 'tú' ? '› ' : '  ';
            foreach (explode("\n", $turno['texto']) as $j => $linea) {
                $lineas[] = ["turno:{$i}:{$j}", ($j === 0 ? $marca : '  ') . $linea];
            }
        }

        // El presupuesto: el alto menos lo que SIEMPRE se pinta —título, estado, separadores, prompt
        // y ayuda— con holgura. Nunca menos de tres: una pantalla diminuta debe mostrar algo, no
        // vaciarse por aritmética.
        $presupuesto = max(3, $this->height - \count($hijos) - 8);
        foreach (\array_slice($lineas, -$presupuesto) as [$id, $texto]) {
            $hijos[] = new TuiNode($id, 'text', props: ['text' => $texto]);
        }

        $hijos[] = new TuiNode('separador', 'text', props: ['text' => str_repeat('─', 40)]);

        // Si está esperando, la PREGUNTA ocupa el lugar del prompt: es lo que hay que hacer, no un
        // aviso al costado.
        $pendiente = $sesion?->question;
        if ($pendiente !== null) {
            $hijos[] = new TuiNode('pregunta', 'text', props: ['text' => '⏸ ' . $pendiente->question]);
            if ($pendiente->why !== null) {
                $hijos[] = new TuiNode('pregunta-por', 'text', props: ['text' => '  con: ' . $pendiente->why]);
            }
            if ($pendiente->options !== []) {
                $hijos[] = new TuiNode('pregunta-op', 'text', props: [
                    'text' => '  ' . implode(' · ', $pendiente->options),
                ]);
            }
        }

        // LA ACTIVIDAD SUBE A SU PROPIA BARRA, y el renglón de entrada deja de prestarse.
        //
        // Compartirlo tenía un costo que no se ve hasta que pasa: mientras el agente trabaja, lo que
        // uno escribió desaparece de la pantalla. La barra dice en qué está el sistema; el renglón de
        // abajo sigue siendo tuyo.
        $hijos[] = new TuiNode('estado-barra', 'status-bar', props: [
            'indicator' => $this->actividad !== null ? '◆' : '○',
            'left' => $this->actividad ?? 'listo',
            // A la derecha lo que costó la última vuelta, que es la otra pregunta que uno se hace
            // mirando una pantalla: no sólo «¿está viva?», también «¿cuánto lleva?».
            'right' => $this->ultimoCosto ?? '',
        ]);

        $hijos[] = new TuiNode('prompt', 'text', props: [
            'text' => '› ' . $this->entrada . '▏',
        ]);
        $hijos[] = new TuiNode('ayuda', 'text', props: [
            'text' => $pendiente !== null
                ? '  [Enter] contestar · [Esc] volver'
                : '  [Enter] preguntar · [Esc] volver',
        ]);

        return new TuiNode('root', 'box', props: ['title' => 'coa · agent'], children: $hijos);
    }

    /**
     * Las líneas del estado: objetivo, plan, pendientes, permisos y qué costó la última vuelta.
     *
     * Los pendientes van con su marca —`[x]`, `[~]`, `[!]`, `[ ]`— porque lo que se busca de un
     * vistazo es cuáles faltan, y un estado escrito con palabras obliga a leer cada renglón para
     * saberlo.
     *
     * @return list<string>
     */
    private function estado(Session $sesion): array
    {
        $lineas = ['  ' . $sesion->goal];

        if ($sesion->plan !== null && trim($sesion->plan) !== '') {
            foreach (explode("\n", trim($sesion->plan)) as $linea) {
                $lineas[] = '  ' . $linea;
            }
        }

        foreach ($sesion->todos as $todo) {
            $marca = match ($todo->status) {
                TodoStatus::Done => '[x]',
                TodoStatus::InProgress => '[~]',
                TodoStatus::Blocked => '[!]',
                TodoStatus::Pending => '[ ]',
            };
            $lineas[] = "  {$marca} {$todo->text}";
        }

        if ($sesion->permissions !== []) {
            $lineas[] = '  autorizado: ' . implode(', ', $sesion->permissions);
        }

        // Lo que se está gastando. Los pasos son el presupuesto de una vuelta y las herramientas son
        // el alcance que tuvo — sin los dos, «el agente contestó» no distingue entre haber trabajado
        // y haber contestado de memoria.
        if ($this->ultimoCosto !== null) {
            $lineas[] = '  ' . $this->ultimoCosto;
        }

        if ($sesion->compactedThrough > 0) {
            // Que se haya compactado se DICE: la sesión empieza a contestar sobre un resumen y no
            // sobre lo que se lee arriba, y no saberlo se depura durante una hora.
            $lineas[] = '  (compactada — el registro completo sigue en la sesión)';
        }

        return $lineas;
    }
}
