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

namespace App\Tests\Tui;

use App\Tui\AgentScreen;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Session;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use PHPUnit\Framework\TestCase;

/**
 * La conversación con el agente, en la terminal.
 *
 * Lo que esta pantalla agrega sobre `coa agent "…"` no es cosmético: se ve la conversación acumulada
 * y cuántos pasos llevó cada respuesta. Y lo que NO hace es armar el orquestador — eso lo sabe
 * `AgentOperations`, y repetirlo aquí sería el camino a que la terminal y el TUI contesten distinto.
 */
final class AgentScreenTest extends TestCase
{
    /** @param \Closure(string): array<string, mixed> $responder */
    private function pantalla(\Closure $responder): AgentScreen
    {
        return new AgentScreen($responder, null, null, 74, 16, false);
    }

    private function teclear(AgentScreen $pantalla, string $texto): void
    {
        foreach (preg_split('//u', $texto, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
            $pantalla->press($caracter);
        }
    }

    /** Se escribe, Enter, y la respuesta queda en la conversación con lo que costó. */
    public function testAskingLeavesBothTurnsWithWhatItTook(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => [
            'ok' => true, 'answer' => 'Hay 3 encendidos.', 'steps' => 2, 'tools' => 8,
        ]);

        $this->teclear($pantalla, 'Que plugins hay?');
        $pantalla->press('enter');

        $turnos = $pantalla->conversation();
        self::assertCount(2, $turnos);
        self::assertSame('Que plugins hay?', $turnos[0]['texto'], 'la pregunta se guarda tal cual, con mayúsculas');
        self::assertStringContainsString('Hay 3 encendidos.', $pantalla->render(), 'y la conversación se ve');
        self::assertStringContainsString('Hay 3 encendidos.', $turnos[1]['texto']);
        self::assertStringContainsString('2 paso(s)', $turnos[1]['texto']);
        self::assertStringContainsString('8 herramientas', $turnos[1]['texto']);
    }

    /**
     * Los dos números no son adorno.
     *
     * Sin ellos, «el agente contestó» no distingue entre haber usado las herramientas de esta app y
     * haber contestado de memoria.
     */
    public function testZeroStepsIsVisibleToo(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'de memoria', 'steps' => 0, 'tools' => 8]);
        $this->teclear($pantalla, 'hola');
        $pantalla->press('enter');

        self::assertStringContainsString('0 paso(s)', $pantalla->conversation()[1]['texto']);
    }

    /** Un fallo se muestra con su motivo y su pista, tal cual vienen. */
    public function testAFailureShowsItsReasonAndHint(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => [
            'ok' => false,
            'error' => 'no hay API key configurada',
            'hint' => 'exporta ANTHROPIC_API_KEY',
        ]);

        $this->teclear($pantalla, 'hola');
        $pantalla->press('enter');

        $respuesta = $pantalla->conversation()[1]['texto'];
        self::assertStringContainsString('no hay API key configurada', $respuesta);
        self::assertStringContainsString('ANTHROPIC_API_KEY', $respuesta);

        // Y las dos líneas se PINTAN: una pista que sólo existe en el arreglo no le sirve a nadie.
        $texto = $pantalla->render();
        self::assertStringContainsString('no hay API key configurada', $texto);
        self::assertStringContainsString('ANTHROPIC_API_KEY', $texto);
    }

    /** Enter con el campo vacío no le pregunta nada a nadie. */
    public function testEnterOnAnEmptyPromptAsksNobody(): void
    {
        $veces = 0;
        $pantalla = $this->pantalla(static function (string $q) use (&$veces): array {
            ++$veces;

            return ['ok' => true, 'answer' => 'x'];
        });

        $pantalla->press('enter');

        self::assertSame(0, $veces);
        self::assertSame([], $pantalla->conversation());
    }

    /** Preguntar limpia el campo: la segunda pregunta empieza en blanco, no encima de la primera. */
    public function testTheFieldIsClearedAfterAsking(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'ok:' . $q]);

        $this->teclear($pantalla, 'uno');
        $pantalla->press('enter');
        $this->teclear($pantalla, 'dos');
        $pantalla->press('enter');

        $turnos = $pantalla->conversation();
        self::assertCount(4, $turnos);
        self::assertSame('dos', $turnos[2]['texto']);
        self::assertStringContainsString('ok:dos', $turnos[3]['texto']);
    }

    /**
     * La letra `q` se teclea.
     *
     * El tier trae `q` entre sus teclas de salida —lo que un dashboard quiere— y aquí cerraba la
     * pantalla a media pregunta. «¿qué plugins hay?» no se puede escribir sin ella.
     */
    public function testTheLetterQDoesNotCloseTheScreen(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'ok']);

        $this->teclear($pantalla, 'que');
        $pantalla->press('enter');

        self::assertSame('que', $pantalla->conversation()[0]['texto']);
    }

    /** El loop existe para correrlo contra una terminal de verdad. */
    public function testItExposesTheLoopToRunAgainstATerminal(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'x']);

        self::assertSame('prompt', $pantalla->loop()->focusedId());
    }

    /** Antes de preguntar, la pantalla invita en vez de mostrar un panel vacío. */
    public function testAnEmptyConversationInvites(): void
    {
        self::assertStringContainsString(
            'Pregúntale algo',
            $this->pantalla(static fn (string $q): array => ['ok' => true])->render(),
        );
    }

    /**
     * @param \Closure(string): array<string, mixed>      $responder
     * @param \Closure(string): array<string, mixed>|null $contestar
     */
    private function pantallaDe(Session $sesion, \Closure $responder, ?\Closure $contestar = null): AgentScreen
    {
        return new AgentScreen(
            $responder,
            static fn (): Session => $sesion,
            $contestar,
            74,
            24,
            false,
        );
    }

    /**
     * LA SESIÓN ANTES QUE LA CONVERSACIÓN — que es P16.7 entero.
     *
     * Lo que hace usable una corrida de cuarenta pasos no es releer lo que dijo: es ver en qué va. La
     * transcripción es lo que sobra cuando la pantalla es chica, no lo que se conserva.
     */
    public function testTheScreenShowsTheSessionState(): void
    {
        $sesion = new Session(
            id: 'jornada',
            goal: 'migrar Inventario a sqlite',
            mode: AutonomyMode::Auto,
            plan: '1. entidad  2. controller',
            todos: [
                new Todo('t1', 'escribir la entidad', TodoStatus::Done),
                new Todo('t2', 'escribir el controller'),
                new Todo('t3', 'migrar los datos', TodoStatus::Blocked),
            ],
            permissions: ['make', 'test'],
        );

        $texto = $this->pantallaDe($sesion, static fn (string $q): array => ['ok' => true])->render();

        self::assertStringContainsString('jornada', $texto);
        self::assertStringContainsString('auto', $texto, 'con cuánta autonomía corre');
        self::assertStringContainsString('migrar Inventario a sqlite', $texto);
        self::assertStringContainsString('1. entidad', $texto);
        self::assertStringContainsString('[x] escribir la entidad', $texto);
        self::assertStringContainsString('[ ] escribir el controller', $texto);
        self::assertStringContainsString('[!] migrar los datos', $texto);
        self::assertStringContainsString('autorizado: make, test', $texto);
    }

    /**
     * Una sesión ESPERANDO enseña la pregunta donde iba el prompt.
     *
     * Es lo que hay que hacer, no un aviso al costado: la sesión no es corrible hasta que alguien
     * conteste, así que ofrecer el prompt normal invitaría a lo único que no va a funcionar.
     */
    public function testAWaitingSessionShowsTheQuestionWhereThePromptGoes(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'x',
            question: new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no'], '{"what":"plugin"}'),
        );

        $texto = $this->pantallaDe($sesion, static fn (string $q): array => ['ok' => true])->render();

        self::assertStringContainsString('¿autorizas make?', $texto);
        self::assertStringContainsString('sí · no', $texto, 'las opciones');
        self::assertStringContainsString('what', $texto, 'y sobre qué');
        self::assertStringContainsString('[Enter] contestar', $texto, 'la ayuda cambia con la situación');
    }

    /**
     * Y ENTER CONTESTA, en vez de mandarle la respuesta al agente.
     *
     * Con una pregunta abierta la sesión no es corrible: mandarla al agente devolvería «está esperando
     * una respuesta» y habría que salirse del TUI a correr `coa agent:answer`. El lugar natural para
     * contestar es donde te la están preguntando.
     */
    public function testEnterAnswersThePendingQuestionInsteadOfAskingTheAgent(): void
    {
        $sesion = new Session(
            id: 's1',
            goal: 'x',
            question: new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']),
        );

        $alAgente = 0;
        $contestado = null;
        $pantalla = $this->pantallaDe(
            $sesion,
            static function (string $q) use (&$alAgente): array {
                ++$alAgente;

                return ['ok' => true];
            },
            static function (string $r) use (&$contestado): array {
                $contestado = $r;

                return ['ok' => true, 'granted' => 'make'];
            },
        );

        $this->teclear($pantalla, 'sí');
        $pantalla->press('enter');

        self::assertSame('sí', $contestado);
        self::assertSame(0, $alAgente, 'no se le mandó nada al agente');
        self::assertStringContainsString('autorizado: make', $pantalla->conversation()[1]['texto']);
    }

    /** Sin sesión, la pantalla es la de antes: la memoria es opcional y no puede romper lo que servía. */
    public function testWithoutASessionTheScreenIsWhatItWas(): void
    {
        $texto = $this->pantalla(static fn (string $q): array => ['ok' => true])->render();

        self::assertStringContainsString('El agente de esta app', $texto);
        self::assertStringContainsString('[Enter] preguntar', $texto);
    }

    /**
     * Lo que costó la última vuelta se ve, y que se haya compactado también.
     *
     * Los dos números distinguen haber trabajado de haber contestado de memoria; y una compactación
     * que no se anuncia deja a la sesión contestando sobre un resumen sin que nadie sepa por qué.
     */
    public function testWhatTheLastRoundCostIsVisibleIncludingACompaction(): void
    {
        $sesion = new Session(id: 's1', goal: 'x', compactedThrough: 12);
        $pantalla = $this->pantallaDe($sesion, static fn (string $q): array => [
            'ok' => true, 'answer' => 'listo', 'steps' => 7, 'tools' => 14, 'compacted' => true,
        ]);

        $this->teclear($pantalla, 'sigue');
        $pantalla->press('enter');

        $texto = $pantalla->render();
        self::assertStringContainsString('7 paso(s)', $texto);
        self::assertStringContainsString('14 herramientas', $texto);
        self::assertStringContainsString('se compactó', $texto);
        self::assertStringContainsString('compactada', $texto, 'y la sesión lo dice de por sí');
    }

    /**
     * Backspace borra el último carácter de lo que se va escribiendo.
     *
     * Suena trivial y es la diferencia entre un campo y una máquina de escribir: sin él, un error de
     * dedo obliga a mandar la pregunta mal o a salir de la pantalla.
     */
    public function testBackspaceDeletesTheLastCharacter(): void
    {
        $pantalla = $this->pantalla(static fn (string $q): array => ['ok' => true, 'answer' => 'ok']);

        $this->teclear($pantalla, 'hola');
        $pantalla->press('backspace');
        $pantalla->press('enter');

        self::assertSame('hol', $pantalla->conversation()[0]['texto'], 'backspace borró uno');
    }

    /**
     * Mientras el agente trabaja, la pantalla LO DICE y PINTA — las dos mitades, o ninguna.
     *
     * Es el defecto que Rod reportó con una captura: la bandera existía, el docblock afirmaba que el
     * frame anterior decía «pensando…», y el código la ponía y la quitaba dentro del mismo tick. Desde
     * afuera es idéntico a no haber cambiado nada, y ~16 segundos de pantalla igual son
     * indistinguibles de un proceso colgado.
     *
     * La prueba mira las dos cosas porque son inseparables: el estado escrito sin pintar no existe
     * para quien mira, que es el único que importa.
     */
    public function testWhileTheAgentWorksTheScreenSaysSoAndPaints(): void
    {
        $frames = [];
        $visto = null;

        $pantalla = new AgentScreen(function (string $p) use (&$visto): array {
            // DENTRO de la llamada bloqueante: aquí es donde la pantalla tiene que estar diciendo algo.
            $visto = $this->render($p);

            return ['ok' => true, 'answer' => 'listo', 'steps' => 1, 'tools' => 2];
        });

        $pantalla->paintWith(static function () use (&$frames, $pantalla): void {
            $frames[] = $pantalla->render();
        });

        $this->pantallaActual = $pantalla;
        $pantalla->loop()->dispatchKey('h');
        $pantalla->loop()->dispatchKey("\r");

        self::assertNotEmpty($frames, 'no pintó nada mientras trabajaba');
        self::assertStringContainsString('preguntando al agente', (string) $frames[0]);
        self::assertStringNotContainsString('preguntando al agente', $pantalla->render(), 'y al terminar, se quita');
    }

    /**
     * Sin nadie que le diga cómo pintar, el estado IGUAL queda escrito.
     *
     * Es el caso de una tubería o una prueba: la pantalla no puede saber si hay terminal —eso lo sabe
     * quien tiene el stream (ADR-0025)— así que no puede depender de que le hayan dado con qué
     * pintar.
     */
    public function testWithoutAPainterTheStateIsStillWritten(): void
    {
        $pantalla = new AgentScreen(static fn (string $p): array => ['ok' => true, 'answer' => 'ok']);

        $pantalla->loop()->dispatchKey('x');
        self::assertStringContainsString('x', $pantalla->render());
    }

    /** Y pintando en una terminal de verdad, los bytes llegan a ella. */
    public function testPaintingOnATerminalWritesToIt(): void
    {
        $terminal = new class () implements \Milpa\Live\Contracts\Tui\TerminalInterface {
            public string $escrito = '';

            public function start(callable $onInput, callable $onResize): void
            {
            }

            public function stop(): void
            {
            }

            public function write(string $data): void
            {
                $this->escrito .= $data;
            }

            public function pollInput(): string
            {
                return '';
            }

            public function columns(): int
            {
                return 80;
            }

            public function rows(): int
            {
                return 24;
            }

            public function atEndOfInput(): bool
            {
                return true;
            }

            public function moveBy(int $lines): void
            {
            }

            public function hideCursor(): void
            {
            }

            public function showCursor(): void
            {
            }

            public function clearLine(): void
            {
            }

            public function clearFromCursor(): void
            {
            }

            public function clearScreen(): void
            {
            }

            public function moveTo(int $row, int $column): void
            {
            }

            public function setTitle(string $title): void
            {
            }
        };

        $pantalla = new AgentScreen(static fn (string $p): array => ['ok' => true, 'answer' => 'ok']);
        $pantalla->paintOn($terminal);
        $pantalla->loop()->dispatchKey('h');
        $pantalla->loop()->dispatchKey("\r");

        self::assertNotSame('', $terminal->escrito, 'la terminal no recibio un solo byte');
    }

    /**
     * La pantalla recibe la actividad POR EL MISMO PUENTE que alimentaría a una página web.
     *
     * No hay canal propio: se registra como `SurfaceBroadcaster` y `BroadcastingEventStore` le empuja
     * cada hecho ya traducido. Y filtra — una tarjeta que se mueve es del tablero; aquí no hay dónde
     * pintarla, y fingir que sí sería inventar una vista.
     */
    public function testTheScreenReceivesActivityThroughTheSameBridgeAsAnyOtherSurface(): void
    {
        $frames = [];
        $pantalla = new AgentScreen(static fn (string $p): array => ['ok' => true, 'answer' => 'ok']);
        $pantalla->paintWith(static function () use (&$frames, $pantalla): void {
            $frames[] = $pantalla->render();
        });

        // El almacén real, con la pantalla como superficie: exactamente el cableado de `coa chat`.
        $almacen = new \Milpa\Agent\SessionStore(
            new \App\Agent\BroadcastingEventStore(new \Milpa\EventStore\InMemoryEventStore(), $pantalla),
        );

        $almacen->start('chat', 'x');
        $almacen->recordToolCall('chat', 'plugins_list', [], 'ok');

        self::assertNotEmpty($frames);
        self::assertStringContainsString('plugins_list', (string) end($frames), 'el nombre de la herramienta llega a la pantalla');

        // Y una tarjeta —que es del tablero— no cambia nada aquí.
        $antes = \count($frames);
        $almacen->setTodo('chat', new \Milpa\Agent\Todo('t1', 'algo', \Milpa\Agent\TodoStatus::Pending));
        self::assertSame($antes, \count($frames), 'lo que no es actividad no repinta esta pantalla');
    }

    private ?AgentScreen $pantallaActual = null;

    private function render(string $prompt): string
    {
        return $this->pantallaActual?->render() ?? '';
    }
}
