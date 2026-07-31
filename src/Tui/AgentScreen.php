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

    /** @param \Closure(string): array{ok: bool, answer?: string, steps?: int, tools?: int, error?: string, hint?: string} $responder */
    public function __construct(
        private readonly \Closure $responder,
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
        $this->pensando = true;

        $respuesta = ($this->responder)($pregunta);

        $this->pensando = false;
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

    private function tree(): TuiNode
    {
        $hijos = [new TuiNode('titulo', 'text', props: ['text' => 'El agente de esta app · trabaja con tus operaciones'])];

        if ($this->conversacion === []) {
            $hijos[] = new TuiNode('vacio', 'text', props: [
                'text' => 'Pregúntale algo. Por ejemplo: «¿qué plugins están encendidos?»',
            ]);
        }

        foreach ($this->conversacion as $i => $turno) {
            $marca = $turno['quien'] === 'tú' ? '› ' : '  ';
            foreach (explode("\n", $turno['texto']) as $j => $linea) {
                $hijos[] = new TuiNode("turno:{$i}:{$j}", 'text', props: [
                    'text' => ($j === 0 ? $marca : '  ') . $linea,
                ]);
            }
        }

        $hijos[] = new TuiNode('separador', 'text', props: ['text' => str_repeat('─', 40)]);
        $hijos[] = new TuiNode('prompt', 'text', props: [
            'text' => $this->pensando ? '  pensando…' : '› ' . $this->entrada . '▏',
        ]);
        $hijos[] = new TuiNode('ayuda', 'text', props: ['text' => '  [Enter] preguntar · [Esc] volver']);

        return new TuiNode('root', 'box', props: ['title' => 'coa · agent'], children: $hijos);
    }
}
