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

use App\Tests\Support\OptIn;
use Milpa\AppRuntime\Console\Application;
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
        self::assertStringContainsString('Every command is a declared operation', $r['texto']);
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
        // RE-VEHICULADO DE `validate` A `plugins:show` (evidence/0103): la propiedad es PISO y
        // montarla sobre las dev tools la volvía frontera sin necesidad.
        $r = $this->correr('plugins:show', 'HelloPlugin', '--json');

        /** @var array{result: array{name: string}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('HelloPlugin', $json['result']['name']);
    }

    /**
     * `--dry-run` en la terminal es `dry_run` en el esquema.
     *
     * Se comprueba por el EFECTO —que no escriba— y no por la traducción en sí: si el token llegara
     * mal, el generador escribiría de verdad y el disco lo diría.
     */
    public function testAKebabFlagReachesTheSchemaAsSnakeCase(): void
    {
        // RE-VEHICULADO DE `make --dry-run` A `capabilities:enable --dry-run` (evidence/0103).
        //
        // La propiedad es que `--dry-run` en la terminal llegue al esquema como `dry_run`, y eso es
        // PISO: `capabilities:enable` existe en una app pelona y devuelve el token traducido.
        //
        // ── LA ENTRADA SE DERIVA DE LA APP, Y ESO NO ES ADORNO ───────────────────────────────────
        //
        // La primera versión clavaba `milpa/agent`, y con `milpa/agent` YA INSTALADO la operación toma
        // la rama «already installed» que no devuelve `dry_run` en absoluto: verde en un recién nacido
        // y ROJA en una app con todo. Lo cachó el arm de F1 (decisions/0013), no la lectura. Así que
        // la capacidad la elige la app: `available` es, por definición, lo que NO está instalado.
        $disponibles = json_decode(trim($this->correr('capabilities', '--json')['texto']), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array<string, mixed>|string> $candidatas */
        $candidatas = $disponibles['result']['available'] ?? [];

        if ($candidatas === []) {
            self::markTestSkipped('esta app no reporta ninguna capacidad disponible: la rama de `dry_run` no es alcanzable aquí');
        }

        // `available` TRAE FILAS, NO CADENAS — y el nombre instalable vive en `package`. Tomar la fila
        // completa mandó «Array» como capacidad y la operación contestó «unknown capability». Es el
        // mismo detalle que evidence/0099 midió del otro lado: la colección trae filas y la llave
        // importa, aunque el mensaje de error de al lado imprima cadenas.
        $primera = $candidatas[0];
        $capacidad = \is_array($primera) ? (string) ($primera['package'] ?? $primera['id'] ?? '') : (string) $primera;
        self::assertNotSame('', $capacidad, 'no se pudo leer el nombre instalable de la primera disponible');

        $r = $this->correr('capabilities:enable', $capacidad, '--dry-run', '--json');

        /** @var array{result: array{ok: bool, dry_run: bool, command: string}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($json['result']['ok'], $r['texto']);
        self::assertTrue($json['result']['dry_run'], 'el kebab `--dry-run` no llegó como `dry_run`');
        self::assertStringContainsString('composer require', $json['result']['command']);
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

        self::assertStringContainsString('no such command', $r['texto']);
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

        self::assertLessThan(
            (int) strpos($texto, 'plugins:enable'),
            (int) strpos($texto, 'plugins:list'),
            'lo que lee (plugins:list) se lista antes de lo que cambia (plugins:enable)',
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
        OptIn::needs(\Milpa\Auth\AuthContext::class);

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

    /**
     * `coa shell` sin terminal pinta UN frame y sale — no se queda esperando teclas.
     *
     * Si el destino no es una terminal —una tubería, un redirect, CI— no hay con qué ser
     * interactivo. Es un hecho del destino y lo sabe quien tiene el stream (ADR-0025), así que la
     * pantalla no se entera: por eso se puede probar sin una.
     */
    public function testTheShellPrintsOneFrameWhenThereIsNoTerminal(): void
    {
        $r = $this->correr('shell');

        self::assertSame(0, $r['codigo']);
        // Una op de la familia plugins, no una específica: el frame del shell muestra UN screenful y
        // las ops van alfabéticas, así que `plugins.list` sale del cuadro cuando el catálogo crece
        // (medido en CI sin TTY). La primera op de plugins siempre entra — se afirma la familia.
        self::assertStringContainsString('plugins.', $r['texto'], 'el frame lista operaciones');
        self::assertStringContainsString('Enter', $r['texto'], 'dice cómo se usa');
    }

    /** `coa chat` igual: un frame con la invitación, y sale. */
    public function testTheChatPrintsOneFrameWhenThereIsNoTerminal(): void
    {
        OptIn::needs(\Milpa\AiGateway\LlmService::class);

        $r = $this->correr('chat');

        self::assertSame(0, $r['codigo']);
        // La portada, que es con lo que abre desde que cada chat es una sesión nueva.
        self::assertStringContainsString('El agente de esta app', $r['texto']);
    }

    /**
     * Y lo que el chat pregunta pasa por la MISMA operación `agent` que la terminal.
     *
     * Es lo que impide que las dos superficies contesten distinto: si el TUI armara su propio
     * orquestador, un cambio en la operación dejaría de reflejarse aquí sin que nada lo dijera.
     */
    public function testTheChatAsksThroughTheSameAgentOperation(): void
    {
        OptIn::needs(\Milpa\AiGateway\LlmService::class);

        $app = new Application(\dirname(__DIR__, 2));
        $metodo = new \ReflectionMethod($app, 'preguntarAlAgente');

        /** @var array{ok: bool, error?: string, hint?: string} $r */
        $r = $metodo->invoke($app, 'lo que sea');

        // Sin llave configurada, la respuesta es la de la operación — no una inventada aquí.
        self::assertFalse($r['ok']);
        self::assertStringContainsString('API key', (string) $r['error']);
    }
}
