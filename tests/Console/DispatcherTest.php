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

namespace App\Tests\Console;

use App\Console\Application;
use PHPUnit\Framework\TestCase;

/**
 * El despachador EN PROCESO, complementando las pruebas que salen a un `bin/coa` real.
 *
 * Las dos hacen falta y miden cosas distintas. {@see ApplicationTest} corre un proceso de verdad:
 * es lo único que prueba la promesa de un `create-project` —que arranca sin tocar un archivo—, y por
 * eso no se sustituye. Pero un subproceso no reporta cobertura, así que el CI veía 0% sobre el
 * archivo más importante del paquete y su piso lo rechazaba con razón: nada garantizaba que las
 * ramas del despachador —traducir nombres, rearmar tokens, elegir renderer— estuvieran ejercitadas.
 *
 * Éstas las ejercitan. Que la salida se capture con un buffer es la consecuencia de que el
 * despachador escriba a STDOUT directo, y eso es correcto para un binario: un `create-project` no
 * debería necesitar una capa de abstracción de salida para imprimir una línea.
 */
final class DispatcherTest extends TestCase
{
    private function correr(string ...$args): array
    {
        ob_start();
        $codigo = (new Application(\dirname(__DIR__, 2)))->run(['coa', ...$args]);

        return ['texto' => (string) ob_get_clean(), 'codigo' => $codigo];
    }

    /** Sin argumentos, la ayuda — derivada de los átomos, no escrita. */
    public function testWithNoArgumentsItListsWhatItCanDo(): void
    {
        $r = $this->correr();

        self::assertSame(0, $r['codigo']);
        self::assertStringContainsString('Cada comando es una operación declarada', $r['texto']);
        self::assertStringContainsString('plugins:list', $r['texto']);
    }

    /** `help`, `--help`, `-h` y `list` llegan al mismo lugar. */
    public function testTheFourWaysToAskForHelpAgree(): void
    {
        $base = $this->correr('help')['texto'];

        foreach (['--help', '-h', 'list'] as $forma) {
            self::assertSame($base, $this->correr($forma)['texto'], "«{$forma}» debería decir lo mismo");
        }
    }

    /**
     * Un `.` del átomo se teclea `:`.
     *
     * `milpa/plugin` nombra sus operaciones con punto y este host las invoca con dos puntos. Sin esa
     * traducción, adoptar un paquete obligaría a renombrar sus átomos o a teclear su convención.
     */
    public function testADottedAtomIsTypedWithColons(): void
    {
        $r = $this->correr('plugins:list', '--json');

        self::assertSame(0, $r['codigo'], $r['texto']);
        /** @var array{ok: bool, result: array{plugins: list<array<string, mixed>>}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($json['ok']);
    }

    /**
     * Lo obligatorio se escribe posicional y en orden.
     *
     * `validate HelloPlugin` sin bandera: el despachador sabe cuál entrada obligatoria va primero
     * porque el modelo proyectado se lo dice, no porque lo tenga escrito.
     */
    public function testRequiredInputIsPositional(): void
    {
        $r = $this->correr('validate', 'HelloPlugin', '--json');

        /** @var array{result: array{target: string}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('HelloPlugin', $json['result']['target']);
    }

    /**
     * `--dry-run` en la terminal es `dry_run` en el esquema.
     *
     * Se comprueba por el EFECTO —que no escriba— y no por la traducción en sí: si el token llegara
     * mal, el generador escribiría de verdad y el disco lo diría.
     */
    public function testAKebabFlagReachesTheSchemaAsSnakeCase(): void
    {
        $r = $this->correr('make', 'entity', 'HelloPlugin', 'CosaQueNoDebeExistir', '--fields=t:string', '--dry-run', '--json');

        /** @var array{result: array{ok: bool, files: list<array{path: string, action: string}>}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($json['result']['ok'], $r['texto']);
        self::assertNotSame([], $json['result']['files']);
        foreach ($json['result']['files'] as $archivo) {
            self::assertSame('would-create', $archivo['action']);
            self::assertFileDoesNotExist($archivo['path']);
        }
    }

    /**
     * `--json` no llega al esquema: es del materializador.
     *
     * Si viajara como entrada, el coercer lo rechazaría por no estar declarado — y la operación
     * fallaría por una bandera que no es suya.
     */
    public function testTheOutputFlagIsNotAnInput(): void
    {
        $r = $this->correr('plugins:list', '--json');

        self::assertSame(0, $r['codigo'], $r['texto']);
        self::assertStringStartsWith('{', trim($r['texto']));
    }

    /** Un comando inexistente lo dice, enseña los que hay, y NO sale 0. */
    public function testAnUnknownCommandIsSaidAndNotSilentlyIgnored(): void
    {
        $r = $this->correr('esto-no-existe');

        self::assertStringContainsString('no existe el comando', $r['texto']);
        self::assertStringContainsString('plugins:list', $r['texto'], 'y enseña lo que sí hay');
    }

    /**
     * La ayuda separa lo que consulta de lo que cambia algo, y marca lo que pide firma.
     *
     * Las dos son la misma decisión: quien lee está por escribir un comando, y saber de antemano si
     * va a mutar o a pedir la tarjeta es lo que evita descubrirlo a medio camino.
     */
    public function testTheHelpSeparatesReadingFromChanging(): void
    {
        $texto = $this->correr()['texto'];

        self::assertStringContainsString('Consultan:', $texto);
        self::assertStringContainsString('Cambian algo:', $texto);
        self::assertLessThan(
            (int) strpos($texto, 'plugins:enable'),
            (int) strpos($texto, 'Cambian algo:'),
            'habilitar un plugin va del lado que cambia',
        );
    }

    /**
     * Con identidad cableada, exponer una operación protegida YA no detiene el arranque.
     *
     * Es la prueba de que las tres piezas están conectadas: almacén, verificador y política. Antes de
     * que esta plantilla cableara `milpa/auth`, `plugins.list` —que declara `plugins:read`— no se
     * podía servir por HTTP de ninguna manera, y el arranque lo decía. Ahora se puede.
     *
     * La negativa sigue probada donde corresponde: {@see \App\Tests\Http\OperationsHttpTest} arma un
     * contenedor SIN política y comprueba que ahí sí se niega.
     */
    public function testWithIdentityWiredAProtectedOperationCanBeExposed(): void
    {
        $http = \dirname(__DIR__, 2) . '/config/http.php';
        $original = (string) file_get_contents($http);
        file_put_contents($http, "<?php\n\nreturn ['expose' => ['plugins.list']];\n");

        try {
            $r = $this->correr('plugins:list', '--json');
        } finally {
            file_put_contents($http, $original);
        }

        self::assertSame(0, $r['codigo'], $r['texto']);
        self::assertStringNotContainsString('no registró un', $r['texto']);
    }
}
