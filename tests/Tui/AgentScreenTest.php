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
        return new AgentScreen($responder, 74, 16, false);
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
}
