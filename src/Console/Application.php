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

namespace App\Console;

use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Console\CliProjector;
use Milpa\Console\CliRunner;
use Milpa\Console\Rendering\JsonCliRenderer;
use Milpa\Console\Rendering\PlainTextCliRenderer;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;

/**
 * El `coa` de esta app: arranca el kernel, junta los átomos y despacha uno.
 *
 * ── POR QUÉ ESTO CABE EN DOSCIENTAS LÍNEAS ──────────────────────────────────────────────────────
 *
 * Porque no sabe hacer nada. No trae `doctor`, ni `validate`, ni `make`, ni `inspect`: los recibe.
 * Cada capacidad es una {@see Operation} que alguien declaró —un paquete, un plugin, esta app— y
 * este archivo sólo la proyecta a la terminal y la corre.
 *
 * El punto de entrada anterior de la familia, `milpa/skeleton`, escribía quince comandos a mano en
 * 1402 líneas. La diferencia no es de estilo: allá agregar una capacidad significaba editar el
 * despachador, y exponerla a un agente significaba escribirla otra vez. Aquí un plugin que implementa
 * {@see CommandProvider} aparece en la terminal, en MCP y en la TUI sin que este archivo se entere.
 *
 * ── SIN SYMFONY CONSOLE, A PROPÓSITO ────────────────────────────────────────────────────────────
 *
 * La tesis de este paquete es un piso mínimo. `milpa/console` publica la proyección y el runner sin
 * atarse a ningún renderer —ésa es la cláusula 2 de ADR-0035— así que un despachador de argv de este
 * tamaño basta. Meter `symfony/console` traería un framework de comandos entero para volver a
 * declarar lo que el átomo ya declara: su nombre, su descripción y sus entradas.
 *
 * Quien quiera esa ergonomía la instala; que sea una decisión suya y no una herencia es la misma
 * regla que rige el resto de esta app.
 *
 * ── LOS NOMBRES ─────────────────────────────────────────────────────────────────────────────────
 *
 * Un `_` o un `.` del átomo se escriben `:` en la terminal: `plugins.list` se invoca `plugins:list`
 * y `fs_read` se invoca `fs:read`. Los dos separadores dicen jerarquía y la familia usa los dos —el
 * host escribe `_`, `milpa/plugin` escribe `.`—; traducir aquí deja que cada paquete conserve su
 * convención y que la terminal tenga la suya. No hay prefijo `coa:` porque el binario ya se llama así.
 */
final class Application
{
    /** @var list<Operation>|null resueltos una vez por corrida */
    private ?array $operations = null;

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $comando = $argv[1] ?? null;

        if ($comando === null || \in_array($comando, ['help', '--help', '-h', 'list'], true)) {
            return $this->help();
        }

        $operacion = $this->find($comando);
        if ($operacion === null) {
            $this->line("✗ no existe el comando «{$comando}»");
            $this->line('');

            return $this->help();
        }

        $resto = \array_slice($argv, 2);

        return (new CliRunner(renderer: \in_array('--json', $resto, true) ? new JsonCliRenderer() : new PlainTextCliRenderer()))
            ->run($operacion, $this->tokens($operacion, $resto), $this->kernel()->container(), $this->line(...));
    }

    /**
     * Lo que esta app sabe hacer, agrupado por si muta.
     *
     * La lista se DERIVA de los átomos, así que no puede quedar desactualizada respecto de lo que
     * realmente hay. Una ayuda escrita a mano es el primer archivo que miente cuando alguien instala
     * un plugin.
     */
    private function help(): int
    {
        $this->line('coa — el runtime de esta app. Cada comando es una operación declarada.');
        $this->line('');

        $lee = [];
        $muta = [];
        foreach ($this->all() as $operacion) {
            $fila = [$this->commandName($operacion), $operacion->description, $operacion->requiresConfirmation];
            $operacion->mutating ? $muta[] = $fila : $lee[] = $fila;
        }

        $this->section('Consultan', $lee);
        $this->section('Cambian algo', $muta);

        $this->line('  Una operación que exige firma se corre con --sign; --json cambia la salida a');
        $this->line('  documento de una línea, para un programa.');

        return 0;
    }

    /**
     * @param list<array{0: string, 1: string, 2: bool}> $filas
     */
    private function section(string $titulo, array $filas): void
    {
        if ($filas === []) {
            return;
        }

        usort($filas, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $ancho = max(array_map(static fn (array $f): int => mb_strlen($f[0]), $filas));

        $this->line($titulo . ':');
        foreach ($filas as [$nombre, $descripcion, $firma]) {
            $this->line(\sprintf(
                '  %s  %s%s',
                $nombre . str_repeat(' ', $ancho - mb_strlen($nombre)),
                $descripcion,
                $firma ? '  [firma]' : '',
            ));
        }
        $this->line('');
    }

    /**
     * Rearma los tokens que el runner espera, traduciendo de vuelta los nombres de la terminal.
     *
     * Lo OBLIGATORIO se escribe posicional —`make entity MiPlugin Cosa`— porque es la convención de
     * cualquier CLI; lo opcional va como `--bandera`. Y una llave `dry_run` del esquema se escribe
     * `--dry-run`: un esquema JSON no lleva guiones en sus llaves y una terminal no lleva guiones
     * bajos en sus opciones, y traducir cuesta menos que pedirle a alguna de las dos que ceda.
     *
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private function tokens(Operation $operacion, array $argv): array
    {
        $modelo = (new CliProjector())->project($operacion);

        $posicionales = [];
        foreach ($modelo->flags as $nombre => $definicion) {
            if ($definicion['required']) {
                $posicionales[] = $nombre;
            }
        }

        $tokens = [];
        $siguiente = 0;

        foreach ($argv as $token) {
            if (!str_starts_with($token, '--')) {
                if (isset($posicionales[$siguiente])) {
                    $tokens[] = '--' . $posicionales[$siguiente] . '=' . $token;
                    ++$siguiente;
                }

                continue;
            }

            [$clave, $valor] = str_contains($token, '=')
                ? explode('=', substr($token, 2), 2)
                : [substr($token, 2), null];

            // `--sign` es de la compuerta de consentimiento, no del esquema: viaja tal cual.
            if ($clave === 'sign') {
                $tokens[] = '--sign';

                continue;
            }
            if ($clave === 'json') {
                continue;
            }

            $enEsquema = str_replace('-', '_', $clave);
            $tokens[] = $valor === null ? '--' . $enEsquema : '--' . $enEsquema . '=' . $valor;
        }

        return $tokens;
    }

    private function find(string $comando): ?Operation
    {
        foreach ($this->all() as $operacion) {
            if ($this->commandName($operacion) === $comando) {
                return $operacion;
            }
        }

        return null;
    }

    private function commandName(Operation $operacion): string
    {
        return str_replace(['_', '.'], ':', $operacion->name);
    }

    /**
     * Los átomos de las dos fuentes: los plugins que arrancaron y lo que esta app enlista.
     *
     * @return list<Operation>
     */
    private function all(): array
    {
        if ($this->operations !== null) {
            return $this->operations;
        }

        $kernel = $this->kernel();
        /** @var list<Operation> $operaciones */
        $operaciones = $kernel->commands();

        $declarados = $this->root . '/config/operations.php';
        if (is_file($declarados)) {
            /** @var list<class-string<CommandProvider>> $proveedores */
            $proveedores = require $declarados;
            foreach ($proveedores as $proveedor) {
                if (!class_exists($proveedor)) {
                    continue;
                }
                $reflexion = new \ReflectionClass($proveedor);
                /** @var CommandProvider $instancia */
                $instancia = ($reflexion->getConstructor()?->getNumberOfParameters() ?? 0) > 0
                    ? $reflexion->newInstance($kernel->container())
                    : $reflexion->newInstance();

                foreach ($instancia->operations() as $operacion) {
                    $operaciones[] = $operacion;
                }
            }
        }

        return $this->operations = $operaciones;
    }

    private ?Kernel $booted = null;

    private function kernel(): Kernel
    {
        if ($this->booted !== null) {
            return $this->booted;
        }

        /** @var array{container: DIContainerInterface, plugins: list<class-string>} $boot */
        $boot = require $this->root . '/config/boot.php';
        /** @var array<string, mixed> $config */
        $config = require $this->root . '/config/app.php';

        return $this->booted = Kernel::boot([
            'root' => $this->root,
            'plugins' => $boot['plugins'],
            'config' => $config,
            'container' => $boot['container'],
            // El registro de herramientas se arma SIEMPRE, no sólo cuando alguien pide MCP: es lo
            // que permite que `bin/mcp-server.php` y esta terminal vean exactamente las mismas
            // operaciones. Un registro que sólo existe en un modo produce dos inventarios.
            'toolRegistry' => new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger()),
        ]);
    }

    /**
     * Una línea a la salida estándar.
     *
     * `echo` y no `fwrite(STDOUT, …)`, y la diferencia importa: `fwrite` a la constante STDOUT se
     * salta el buffer de salida de PHP, así que una prueba en proceso no puede capturarlo. La
     * alternativa era inyectar un stream, y un `create-project` cuya tesis es un piso mínimo no
     * debería necesitar una capa de abstracción de salida para imprimir un renglón.
     */
    private function line(string $texto): void
    {
        echo $texto, \PHP_EOL;
    }
}
