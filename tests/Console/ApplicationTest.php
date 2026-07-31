<?php

declare(strict_types=1);

namespace App\Tests\Console;

use PHPUnit\Framework\TestCase;

/**
 * `coa` contra esta app REAL, por proceso.
 *
 * Se corre de verdad y no se instancia la clase: lo que esta app promete es que un
 * `composer create-project` arranca y hace algo útil sin tocar un archivo, y eso sólo lo prueba un
 * proceso que arranca el kernel de verdad. Una prueba que llamara a `Application::run()` con dobles
 * mediría el despachador y no la promesa.
 */
final class ApplicationTest extends TestCase
{
    private function coa(string $args = ''): array
    {
        $salida = [];
        $codigo = 0;
        exec('php ' . escapeshellarg(\dirname(__DIR__, 2) . '/bin/coa') . ' ' . $args . ' 2>&1', $salida, $codigo);

        return ['texto' => implode("\n", $salida), 'codigo' => $codigo];
    }

    /**
     * La ayuda se DERIVA de los átomos, así que no puede quedar desactualizada.
     *
     * Es la diferencia con el punto de entrada anterior de la familia, que enumeraba sus quince
     * comandos a mano: una ayuda escrita es el primer archivo que miente cuando alguien instala un
     * plugin.
     */
    public function testHelpIsDerivedFromTheDeclaredOperations(): void
    {
        $r = $this->coa();

        self::assertSame(0, $r['codigo'], $r['texto']);
        self::assertStringContainsString('Cada comando es una operación declarada', $r['texto']);
        // De las dos fuentes: `plugins:*` los declara un plugin arrancado, `validate` y `make` los
        // publica un paquete que esta app adopta.
        self::assertStringContainsString('plugins:list', $r['texto']);
        self::assertStringContainsString('validate', $r['texto']);
        self::assertStringContainsString('make', $r['texto']);
    }

    /** Lo que muta se lista aparte de lo que consulta, porque no se leen igual. */
    public function testTheHelpSeparatesWhatReadsFromWhatChanges(): void
    {
        $texto = $this->coa()['texto'];

        self::assertStringContainsString('Consultan:', $texto);
        self::assertStringContainsString('Cambian algo:', $texto);
        self::assertLessThan(
            strpos($texto, 'plugins:enable'),
            (int) strpos($texto, 'Cambian algo:'),
            'habilitar un plugin cambia algo y va del lado que cambia',
        );
    }

    /** Una operación de plugin corre contra el registro real de esta app. */
    public function testAPluginOperationRunsAgainstThisApp(): void
    {
        $r = $this->coa('plugins:list --json');

        self::assertSame(0, $r['codigo'], $r['texto']);

        /** @var array{ok: bool, result: array{plugins: list<array<string, mixed>>}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($json['ok']);

        // El CONJUNTO, no el orden: encender o apagar un plugin le escribe un registro, y con eso
        // cambia de lugar en la lista. Afirmar la secuencia hacía que esta prueba dependiera de
        // cuál corrió antes — y una prueba sensible al orden de sus hermanas falla por motivos que
        // no tienen nada que ver con lo que mide.
        $nombres = array_column($json['result']['plugins'], 'name');
        sort($nombres);
        self::assertSame(['HelloPlugin', 'PluginManagement'], $nombres);
    }

    /**
     * Lo obligatorio se escribe POSICIONAL, como en cualquier CLI.
     *
     * El átomo declara `target` como entrada obligatoria y aquí se teclea `validate HelloPlugin`, sin
     * bandera. Que el materializador lo traduzca es lo que permite migrar una capacidad a átomo sin
     * cambiarle a nadie la forma de invocarla.
     */
    public function testRequiredInputIsWrittenPositionally(): void
    {
        $r = $this->coa('validate HelloPlugin --json');

        /** @var array{result: array{target: string}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('HelloPlugin', $json['result']['target']);
    }

    /**
     * Lo que no se puede deshacer NO corre sin firma, y no escribe nada al negarse.
     *
     * Es la garantía que distingue a este runtime de otro agente de terminal, y por eso se comprueba
     * con la operación real y no con una declaración: que el átomo diga `requiresConfirmation` sólo
     * sirve si algo lo honra.
     */
    public function testWhatIsReversibleRunsWithoutASignature(): void
    {
        self::assertSame(0, $this->coa('plugins:disable HelloPlugin')['codigo'], 'deshabilitar es reversible');
        self::assertFalse($this->habilitado('HelloPlugin'), 'y de verdad lo deshabilitó');

        $this->coa('plugins:enable HelloPlugin');
        self::assertTrue($this->habilitado('HelloPlugin'));
    }

    /**
     * El ESTADO de un plugin, no la cadena que lo reporta.
     *
     * La primera versión de esto comparaba la salida JSON completa antes y después del ciclo, y
     * fallaba: encender un plugin le escribe un registro y con eso cambia el ORDEN de la lista. El
     * estado era idéntico y la cadena no. Comparar representaciones cuando lo que importa es el hecho
     * es una prueba que se rompe sola.
     */
    private function habilitado(string $nombre): bool
    {
        /** @var array{result: array{plugins: list<array{name: string, enabled: bool}>}} $json */
        $json = json_decode(trim($this->coa('plugins:list --json')['texto']), true, 512, JSON_THROW_ON_ERROR);

        foreach ($json['result']['plugins'] as $plugin) {
            if ($plugin['name'] === $nombre) {
                return $plugin['enabled'];
            }
        }

        self::fail("«{$nombre}» no aparece en la lista");
    }

    /** Un comando que no existe lo dice y enseña los que sí, en vez de sólo negarse. */
    public function testAnUnknownCommandShowsWhatDoesExist(): void
    {
        $r = $this->coa('no-existe-esto');

        self::assertStringContainsString('no existe el comando', $r['texto']);
        self::assertStringContainsString('plugins:list', $r['texto']);
    }
}
