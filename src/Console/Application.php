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
use App\Operations\AgentOperations;
use App\Tui\AgentScreen;
use Milpa\Agent\Session;
use Milpa\DevTools\Doctor\AppDoctor;
use Milpa\Console\Tui\OperationsScreen;
use Milpa\Live\Tui\StreamTerminal;
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

    /** Sobre cuál sesión corre `coa chat`. Se fija al despachar el comando. */
    private string $sesionDelChatId = 'chat';

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            return $this->dispatch($argv);
        } catch (\Throwable $e) {
            // Un arranque que se niega —una operación protegida expuesta sin política, un plugin que
            // pide algo que nadie provee— tiene un mensaje que dice qué arreglar. Sin este catch ese
            // mensaje sale enterrado bajo una traza, y el error se lee como un defecto del framework
            // en vez de como una configuración por corregir.
            $this->line('✗ ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * @param list<string> $argv
     */
    private function dispatch(array $argv): int
    {
        $comando = $argv[1] ?? null;

        if ($comando === null || \in_array($comando, ['help', '--help', '-h', 'list'], true)) {
            return $this->help();
        }

        // ANTES DE BOOTEAR, y ésa es toda la razón de que viva aquí y no en `config/operations.php`.
        // Una operación se despacha con el kernel arriba; si el grafo de capacidades no cierra, el
        // kernel no arranca y NINGUNA operación corre — incluidas las de diagnóstico. Medido en esta
        // misma app con una capacidad sin proveedor: `plugins:list`, `validate` y `test` caídas, y una
        // línea de error como único dato. La herramienta que explica por qué no arranca no puede
        // necesitar que arranque.
        if ($comando === 'doctor') {
            return $this->doctor();
        }

        // Las dos pantallas. No son operaciones y no lo fingen: una operación se ejecuta con lo que
        // trae y contesta, y esto CONVERSA — captura teclas hasta que alguien sale. Que vivan aquí y
        // no en `config/operations.php` es la misma distinción que dejó fuera a `coa:run`.
        if ($comando === 'shell') {
            return $this->pantalla(new OperationsScreen(
                $this->all(),
                $this->kernel()->container(),
                ...$this->tamano(),
                dispatcher: $this->kernel()->dispatcher(),
            ));
        }

        if ($comando === 'chat') {
            // `coa chat [<id>]`. Con un default y no con un id inventado en cada arranque: un chat que
            // olvida todo al cerrarse es justo lo que P16 vino a quitar, y pedir el id para lo más
            // común obligaría a inventar uno antes de poder preguntar nada.
            $this->sesionDelChatId = \is_string($argv[2] ?? null) && trim($argv[2]) !== ''
                ? trim($argv[2])
                : 'chat';

            [$ancho, $alto] = $this->tamano();

            return $this->pantalla(new AgentScreen(
                $this->preguntarAlAgente(...),
                $this->sesionDelChat(...),
                $this->contestarEnElChat(...),
                $ancho,
                $alto,
            ));
        }

        $operacion = $this->find($comando);
        if ($operacion === null) {
            $this->line("✗ no existe el comando «{$comando}»");
            $this->line('');

            return $this->help();
        }

        $resto = \array_slice($argv, 2);

        return (new CliRunner(
            renderer: \in_array('--json', $resto, true) ? new JsonCliRenderer() : new PlainTextCliRenderer(),
            // El despachador del kernel viaja al runner: sin él, un listener que audita operaciones
            // las vería por MCP y no por la terminal — que es el hueco que el runner vino a cerrar.
            dispatcher: $this->kernel()->dispatcher(),
        ))->run($operacion, $this->tokens($operacion, $resto), $this->kernel()->container(), $this->line(...));
    }

    /**
     * Corre una pantalla contra la terminal — o pinta un frame y sale, si no hay terminal.
     *
     * Si el destino no es una terminal —una tubería, un redirect, CI— no hay con qué ser
     * interactivo. Es un hecho del DESTINO y lo sabe quien tiene el stream (ADR-0025): la pantalla
     * no se entera, y por eso se puede probar sin una.
     */
    private function pantalla(OperationsScreen|AgentScreen $pantalla): int
    {
        if (!(\function_exists('stream_isatty') && @stream_isatty(\STDIN))) {
            $this->line($pantalla->render());

            return 0;
        }

        $pantalla->loop()->runOn(new StreamTerminal('coa'));

        return 0;
    }

    /**
     * El ancho y el alto de la terminal, o un tamaño razonable si no hay una.
     *
     * @return array{0: int, 1: int}
     */
    private function tamano(): array
    {
        $terminal = new StreamTerminal('coa');

        return [$terminal->columns(), $terminal->rows()];
    }

    /**
     * Le pregunta al agente, por el MISMO camino que `coa agent`.
     *
     * La pantalla no arma el orquestador ni elige proveedor: eso lo sabe `AgentOperations`, y
     * repetirlo aquí sería un segundo camino a lo mismo — como se llega a que la terminal y el TUI
     * contesten distinto.
     *
     * @return array{ok: bool, answer?: string, steps?: int, tools?: int, error?: string, hint?: string}
     */
    private function preguntarAlAgente(string $prompt): array
    {
        /** @var array{ok: bool, answer?: string, steps?: int, tools?: int, compacted?: bool, error?: string, hint?: string} $r */
        $r = $this->correr('agent', ['prompt' => $prompt, 'session' => $this->sesionDelChatId])
            ?? ['ok' => false, 'error' => 'esta app no declara la operación `agent`'];

        return $r;
    }

    /**
     * La sesión sobre la que corre el chat, releída en cada frame.
     *
     * Se relee y no se guarda porque cambia con cada vuelta: el plan que el agente acaba de escribir,
     * el pendiente que acaba de cerrar, la pregunta que acaba de dejar abierta. Una copia guardada en
     * la pantalla sería la de hace un rato, y una interfaz que enseña un estado viejo con cara de
     * actual es peor que una que no lo enseña.
     */
    private function sesionDelChat(): ?Session
    {
        // El mismo almacén que resuelve la operación `agent`, por la misma vía: dos lugares que
        // decidan dónde viven las sesiones son dos lugares donde pueden dejar de coincidir, y el día
        // que lo hicieran el TUI pintaría una sesión que el agente no está escribiendo.
        return (new AgentOperations($this->kernel()->container()))
            ->sessionStore()
            ?->load($this->sesionDelChatId);
    }

    /**
     * @return array{ok: bool, granted?: string|null, error?: string}
     */
    private function contestarEnElChat(string $respuesta): array
    {
        /** @var array{ok: bool, granted?: string|null, error?: string} $r */
        $r = $this->correr('agent:answer', ['session' => $this->sesionDelChatId, 'answer' => $respuesta])
            ?? ['ok' => false, 'error' => 'esta app no declara la operación `agent:answer`'];

        return $r;
    }

    /**
     * Corre una operación por nombre, o `null` si esta app no la declara.
     *
     * @param array<string, mixed> $entrada
     *
     * @return array<string, mixed>|null
     */
    private function correr(string $nombre, array $entrada): ?array
    {
        foreach ($this->all() as $operacion) {
            if ($operacion->name !== $nombre) {
                continue;
            }

            $handler = $operacion->handler;
            if (\is_callable($handler)) {
                $r = $handler($entrada);

                return \is_array($r) ? $r : null;
            }
        }

        return null;
    }

    /**
     * Lo que esta app sabe hacer, agrupado por si muta.
     *
     * La lista se DERIVA de los átomos, así que no puede quedar desactualizada respecto de lo que
     * realmente hay. Una ayuda escrita a mano es el primer archivo que miente cuando alguien instala
     * un plugin.
     */
    /**
     * Explica el estado arquitectónico de esta app SIN bootearla.
     *
     * Aquí sólo se RENDERIZA: el diagnóstico lo produce {@see AppDoctor} como valor, para que el mismo
     * cálculo sirva a una terminal, a un TUI y a un agente sin que ninguno tenga que parsear lo que
     * otro imprimió — y para que se pueda probar sin capturar salida.
     */
    private function doctor(): int
    {
        $declarados = $this->root . '/config/plugins.php';
        if (!is_file($declarados)) {
            $this->line('✗ no hay config/plugins.php — esta app no declara plugins');

            return 1;
        }

        /** @var list<string> $clases */
        $clases = require $declarados;
        $reporte = (new AppDoctor())->diagnose($clases);

        $this->line('coa doctor · ' . \count($clases) . ' plugin(s) declarado(s)');
        $this->line('');

        foreach ($reporte->unreadable as $ilegible) {
            $this->line('  ✗ ' . $ilegible);
        }

        foreach ($reporte->plugins as $plugin) {
            $provee = $plugin['provides'] === [] ? '—' : implode(', ', $plugin['provides']);
            $pide = $plugin['requires'] === [] ? '—' : implode(', ', $plugin['requires']);
            $this->line(sprintf('  %-22s provee: %-24s pide: %s', $plugin['name'], $provee, $pide));
        }

        $this->line('');

        foreach ($reporte->missing as $falta) {
            $id = \is_string($falta['id'] ?? null) ? $falta['id'] : (string) json_encode($falta);
            $this->line("  ✗ nadie provee «{$id}»");
        }

        // Lo aprendible del resolver, tal cual viene: qué pasó, POR QUÉ, cómo se arregla y a qué
        // lección lleva. Reformularlo aquí sería empeorarlo — y las `recommendedActions` son lo que un
        // agente puede aplicar sin interpretar nada, que es la diferencia entre un error que se lee y
        // uno que se opera.
        foreach ($reporte->errors as $error) {
            $this->line('');
            $this->line('  ' . (string) $error['code'] . ': ' . (string) $error['message']);
            $this->line('    por qué: ' . (string) $error['why']);
            foreach ((array) $error['fixes'] as $arreglo) {
                $this->line('    arregla: ' . (string) $arreglo);
            }
            foreach ((array) $error['recommendedActions'] as $accion) {
                $this->line('    acción:  ' . (string) json_encode($accion));
            }
            $aprende = (array) $error['learn'];
            $academia = $aprende['academy'] ?? null;
            if (\is_array($academia) && \is_string($academia['es'] ?? null)) {
                $this->line('    aprende: ' . $academia['es']);
            }
        }

        $this->line('');
        $this->line($reporte->ok() ? '✓ el grafo cierra' : '✗ esta app no va a arrancar así');

        return $reporte->ok() ? 0 : 1;
    }

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

        // `doctor`, `shell` y `chat` NO son operaciones y por eso la lista derivada no las trae: se
        // enumeran aquí, que es la única excepción honesta a «la ayuda se deriva». Una capacidad que
        // existe y no se anuncia no la encuentra nadie — y `doctor` es justamente la que hace falta
        // cuando lo demás no corre.
        $this->line('  Además:');
        $this->line('    doctor           Explica el estado arquitectónico de la app SIN arrancarla');
        $this->line('    shell            Todas las operaciones, en una pantalla');
        $this->line('    chat [<sesion>]  El agente, en una sesión que sobrevive al proceso');
        $this->line('');

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

        $kernel = Kernel::boot([
            'root' => $this->root,
            'plugins' => $boot['plugins'],
            'config' => $config,
            'container' => $boot['container'],
            // El registro de herramientas se arma SIEMPRE, no sólo cuando alguien pide MCP: es lo
            // que permite que `bin/mcp-server.php` y esta terminal vean exactamente las mismas
            // operaciones. Un registro que sólo existe en un modo produce dos inventarios.
            'toolRegistry' => new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger()),
        ]);

        // El kernel, en su propio contenedor. Un handler que corre DESPUÉS del arranque —el agente,
        // por ejemplo— necesita preguntar qué declara esta app, y sin esto tendría que volver a
        // resolverlo por su cuenta: dos respuestas a la misma pregunta, que es como se llega a que
        // una superficie ofrezca lo que la otra no.
        $boot['container']->registerService(Kernel::class, $kernel);

        return $this->booted = $kernel;
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
