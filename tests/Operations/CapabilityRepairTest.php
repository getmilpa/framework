<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Tests\Support\OptIn;
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
     * Un paquete que esta app DE VERDAD tiene — derivado, nunca clavado (evidence/0104).
     *
     * ── POR QUÉ IMPORTA CUÁL SE NOMBRA ───────────────────────────────────────────────────────────
     *
     * `Repair::apply` verifica, después de que composer contesta 0, que el paquete **aparezca** en
     * `vendor/composer/installed.json`, porque *«el código de salida es una afirmación del subproceso
     * sobre sí mismo, no sobre esta app»*. Los tres casos que llegan a esa guarda inyectan un corredor
     * falso —así que nada se instala— y clavaban `milpa/mcp-server`, que un recién nacido no trae: la
     * app se negaba con razón y la prueba lo leía como defecto suyo. evidence/0103 lo publicó como
     * «tres defectos reales de `repair`» y era esto.
     *
     * Se deriva del MISMO archivo que la guarda consulta, así que no puede desalinearse con ella. Se
     * prefiere `milpa/devtools` porque el `OptIn::needs()` de cada caso ya garantiza su presencia: si
     * no estuviera, el método habría saltado antes de llegar aquí.
     */
    private function paqueteQueSiEsta(): string
    {
        $archivo = \dirname(__DIR__, 2) . '/vendor/composer/installed.json';
        self::assertFileExists($archivo, 'sin installed.json no se puede derivar nada');

        /** @var array{packages?: list<array{name?: string}>} $json */
        $json = json_decode((string) file_get_contents($archivo), true, 512, JSON_THROW_ON_ERROR);
        $nombres = array_column($json['packages'] ?? [], 'name');

        self::assertContains('milpa/devtools', $nombres, 'la compuerta exige devtools, así que tiene que estar');

        return 'milpa/devtools';
    }

    /**
     * EL OBJETIVO LO NOMBRA EL HUMANO (ADR-0044), y en reparar es donde más importa.
     *
     * Es la operación con más tentación de decidir sola: el diagnóstico ya «sabe» qué hacer. Instalar
     * algo que nadie pidió es cambiar la app por una conclusión propia.
     *
     * ── POR QUÉ ESTE OPT-IN LLEGÓ DESPUÉS QUE LOS DE SUS NUEVE HERMANOS ─────────────────────────
     *
     * Porque hasta `app-runtime` 0.10 este caso no necesitaba el paquete: `repair` se DECLARABA
     * siempre y sólo su implementación era opcional, así que leer la declaración bastaba. Desde B3
     * la declaración misma es condicional —lo que no se puede hacer no se ofrece— y examinarla
     * pasó a exigir lo que la implementa.
     *
     * La lección no es el opt-in que faltaba: es que **gatear una declaración cambia quién puede
     * mirarla**. Un caso que sólo leía metadatos se volvió dependiente sin que su cuerpo cambiara
     * una línea, y no lo encontró revisar el diff — lo encontró correr la suite contra el paquete
     * nuevo.
     */
    public function testTheTargetIsDeclaredSoTheHumanHasToNameIt(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

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
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $r = $this->reparar(['package' => 'vendor/lo-que-sea'], ['milpa/mcp-server']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no está entre lo que el diagnóstico recomienda', (string) $r['error']);
    }

    /** Y la negativa no es un callejón: dice lo que SÍ se puede reparar. */
    public function testTheRefusalSaysWhatCanBeRepaired(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $r = $this->reparar(['package' => 'vendor/otro'], ['milpa/mcp-server', 'milpa/data']);

        self::assertSame(['milpa/mcp-server', 'milpa/data'], $r['recommended']);
        self::assertIsString($r['hint']);
    }

    /** Con un diagnóstico que no recomienda nada, lo dice así — no como «ese paquete no existe». */
    public function testWithNothingToRepairItSaysThatAndNotSomethingElse(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

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
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

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
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

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
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        // EL DOBLE DISTINGUE LOS DOS COMANDOS, porque son dos hechos distintos: composer sí pudo, y
        // el arranque posterior no. Un doble que contestara igual a los dos mediría uno solo.
        $r = $this->reparar(
            ['package' => $paquete = $this->paqueteQueSiEsta()],
            [$paquete],
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
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $r = $this->reparar(
            ['package' => $paquete = $this->paqueteQueSiEsta()],
            [$paquete],
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
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $visto = null;
        $this->reparar(
            ['package' => $paquete = $this->paqueteQueSiEsta()],
            [$paquete],
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
