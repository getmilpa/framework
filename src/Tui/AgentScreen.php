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
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
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
final class AgentScreen
{
    private readonly RetainedTuiLoop $loop;

    private string $entrada = '';

    /** @var list<array{quien: string, texto: string}> */
    private array $conversacion = [];

    private bool $pensando = false;

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
        int $height = 24,
        bool $ansi = true,
    ) {
        $this->loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), self::renderers()),
            fn (): TuiNode => $this->tree(),
            ['prompt'],
            'prompt',
            $width,
            $height,
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
        $registry->register(new BoxRenderer());

        return $registry;
    }

    /** El loop armado, para correrlo contra una terminal. */
    public function loop(): RetainedTuiLoop
    {
        return $this->loop;
    }

    /** La pantalla completa como texto, sin necesitar una terminal. */
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

        $this->pensando = true;

        $respuesta = ($this->responder)($pregunta);

        $this->pensando = false;

        if ($respuesta['ok']) {
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
        $recientes = \array_slice($this->conversacion, -6);
        foreach ($recientes as $i => $turno) {
            $marca = $turno['quien'] === 'tú' ? '› ' : '  ';
            foreach (explode("\n", $turno['texto']) as $j => $linea) {
                $hijos[] = new TuiNode("turno:{$i}:{$j}", 'text', props: [
                    'text' => ($j === 0 ? $marca : '  ') . $linea,
                ]);
            }
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

        $hijos[] = new TuiNode('prompt', 'text', props: [
            'text' => $this->pensando ? '  pensando…' : '› ' . $this->entrada . '▏',
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
