<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use Milpa\AppRuntime\Operations\CapabilityOperations;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * `repair` — el eslabón entre el diagnóstico y la acción (P17.6).
 *
 * `coa doctor` ya imprimía las acciones recomendadas y nada las aplicaba: entre el diagnóstico y
 * `capabilities:enable` no había camino, y quien reparaba traducía a mano de una acción a un comando.
 */
final class CapabilityRepairTest extends TestCase
{
    private function operacion(string $nombre): Operation
    {
        foreach ((new CapabilityOperations())->operations() as $op) {
            if ($op->name === $nombre) {
                return $op;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }

    /**
     * @param list<string> $recomendados
     *
     * @return array<string, mixed>
     */
    private function reparar(array $entrada, array $recomendados, ?callable $corredor = null): array
    {
        /** @var array<string, mixed> $r */
        $r = (new \ReflectionMethod(CapabilityOperations::class, 'repair'))
            ->invoke(new CapabilityOperations(), $entrada, $recomendados, $corredor);

        return $r;
    }

    /**
     * EL OBJETIVO LO NOMBRA EL HUMANO (ADR-0044), y en reparar es donde más importa.
     *
     * Es la operación con más tentación de decidir sola: el diagnóstico ya «sabe» qué hacer. Instalar
     * algo que nadie pidió es cambiar la app por una conclusión propia.
     */
    public function testTheTargetIsDeclaredSoTheHumanHasToNameIt(): void
    {
        self::assertSame('package', $this->operacion('repair')->namedTarget);
        self::assertTrue($this->operacion('repair')->mutating);
    }

    /**
     * LA PUERTA SÓLO ABRE PARA LO QUE EL DIAGNÓSTICO PIDIÓ.
     *
     * Sin esto sería un instalador general con nombre de reparación — la puerta ancha que después
     * nadie se atreve a cerrar, y por la que cualquier paquete entraría a la app por la vía que existe
     * para arreglarla.
     */
    public function testItRefusesAPackageTheDiagnosisDidNotRecommend(): void
    {
        $r = $this->reparar(['package' => 'vendor/lo-que-sea'], ['milpa/mcp-server']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no está entre lo que el diagnóstico recomienda', (string) $r['error']);
    }

    /** Y la negativa no es un callejón: dice lo que SÍ se puede reparar. */
    public function testTheRefusalSaysWhatCanBeRepaired(): void
    {
        $r = $this->reparar(['package' => 'vendor/otro'], ['milpa/mcp-server', 'milpa/data']);

        self::assertSame(['milpa/mcp-server', 'milpa/data'], $r['recommended']);
        self::assertIsString($r['hint']);
    }

    /** Con un diagnóstico que no recomienda nada, lo dice así — no como «ese paquete no existe». */
    public function testWithNothingToRepairItSaysThatAndNotSomethingElse(): void
    {
        $r = $this->reparar(['package' => 'milpa/mcp-server'], []);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no recomienda instalar nada', (string) $r['error']);
    }

    /**
     * Lo recomendado sí procede, y lo aplica quien ya sabe verificarlo.
     *
     * La respuesta viene de `Capabilities::install()`, que relee el disco después de correr — aquí
     * contesta «ya instalado» porque en este paquete lo está, y esa respuesta es el punto: **no dice
     * «reparado» por haber sido llamado.** Lo que se fija es que la puerta abre para lo recomendado y
     * que quien contesta es el que verifica, no esta operación.
     */
    public function testWhatTheDiagnosisRecommendsGoesThroughToTheOneThatVerifies(): void
    {
        $r = $this->reparar(
            ['package' => 'milpa/mcp-server', 'dry_run' => true],
            ['milpa/mcp-server'],
        );

        self::assertTrue($r['ok'] ?? false, (string) ($r['error'] ?? ''));
        self::assertSame('milpa/mcp-server', $r['package'] ?? null, 'reparó lo que se le nombró');
        self::assertArrayNotHasKey('recommended', $r, 'no es la respuesta de una negativa');
    }

    /** Sin `package` se dice qué falta, en vez de reparar lo primero que encuentre. */
    public function testWithoutAPackageItSaysWhatIsMissing(): void
    {
        $r = $this->reparar([], ['milpa/mcp-server']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('package', (string) $r['error']);
    }

    /**
     * QUE LA CAPACIDAD HAYA LLEGADO NO ES QUE LA APP SIGA EN PIE.
     *
     * Instalar un paquete puede cerrar el grafo de una capacidad y abrir otro. Antes de esto la
     * operación contestaba `ok` con la app rota, que es el mismo escalón de siempre una vuelta más
     * arriba: el intento por el hecho, y ahora el hecho por sus consecuencias.
     */
    public function testARepairThatLeavesTheAppUnableToBootIsNotARepair(): void
    {
        // EL DOBLE DISTINGUE LOS DOS COMANDOS, porque son dos hechos distintos: composer sí pudo, y
        // el arranque posterior no. Un doble que contestara igual a los dos mediría uno solo.
        $r = $this->reparar(
            ['package' => 'milpa/mcp-server'],
            ['milpa/mcp-server'],
            static fn (string $cmd): array => str_contains($cmd, 'composer')
                ? [0, ['Package operations: 1 install']]
                : [1, ['MILPA_CAPABILITY_MISSING: nadie provee «x.y»']],
        );

        self::assertFalse($r['ok'], 'el ok se cae aunque la instalación funcionara');
        self::assertFalse($r['boots']);
        self::assertStringContainsString('ya no arranca', (string) $r['error']);
        self::assertStringContainsString('MILPA_CAPABILITY_MISSING', (string) $r['boot_error'], 'con el detalle real');
        self::assertStringContainsString('composer remove', (string) $r['hint'], 'y cómo deshacerlo');
    }

    /** Y cuando sí arranca, lo dice — no se calla el hecho que acaba de comprobar. */
    public function testWhenItStillBootsItSaysSo(): void
    {
        $r = $this->reparar(
            ['package' => 'milpa/mcp-server'],
            ['milpa/mcp-server'],
            static fn (string $cmd): array => [0, ['✓ el grafo cierra']],
        );

        self::assertTrue($r['ok']);
        self::assertTrue($r['boots']);
    }

    /**
     * EL DIAGNÓSTICO CORRE EN UN PROCESO NUEVO, y eso no es un detalle de implementación.
     *
     * El autoloader de este proceso se armó antes de que composer escribiera nada, así que una
     * comprobación en memoria contestaría sobre el mundo de hace un minuto con cara de actual.
     */
    public function testTheCheckRunsTheAppAgainInsteadOfAskingItsOwnMemory(): void
    {
        $visto = null;
        $this->reparar(
            ['package' => 'milpa/mcp-server'],
            ['milpa/mcp-server'],
            static function (string $cmd) use (&$visto): array {
                $visto[] = $cmd;

                return [0, []];
            },
        );

        self::assertIsArray($visto);
        self::assertStringContainsString('composer require', $visto[0], 'primero siembra');
        self::assertStringContainsString('bin/coa doctor', $visto[1] ?? '', 'y después pregunta si arranca');
    }

    /** En seco no se verifica nada: no se instaló nada que pudiera romper el arranque. */
    public function testADryRunDoesNotPretendToHaveCheckedTheBoot(): void
    {
        $r = $this->reparar(
            ['package' => 'milpa/mcp-server', 'dry_run' => true],
            ['milpa/mcp-server'],
            static fn (string $cmd): array => self::fail('un ensayo no debe verificar arranque'),
        );

        self::assertArrayNotHasKey('boots', $r);
    }
}
