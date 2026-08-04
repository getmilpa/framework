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

namespace App\Plugins\OperationsHttpPlugin;

use Milpa\AppRuntime\Support\Operations;
use Milpa\Attributes\PluginMetadata;
use Milpa\Command\Operation;
use Milpa\Console\Http\HttpProjector;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Http\RouteProviderInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Sirve por HTTP las operaciones que `config/http.php` nombra — y NADA más.
 *
 * La cuarta superficie. `coa` y MCP ya proyectan todos los átomos de esta app; esto proyecta los que
 * decidas publicar, con la misma ruta que `$op->path` declara o una derivada de su nombre.
 *
 * ── POR QUÉ NO EXPONE TODO ──────────────────────────────────────────────────────────────────────
 *
 * Porque `coa` y MCP corren en la máquina de quien los invoca, y una ruta HTTP la puede llamar
 * cualquiera que alcance el servidor. Exponer todo por default convertiría instalar un plugin en
 * publicar una API sin que nadie lo hubiera decidido. La lista vacía de `config/http.php` es el
 * default, y prender algo cuesta una línea que se lee en un diff.
 *
 * ── EL ARRANQUE SE DETIENE SI FALTA LA POLÍTICA ─────────────────────────────────────────────────
 *
 * Un átomo que declara `scopes` o `permission` necesita quién decida si quien llama puede.
 * {@see HttpProjector} se niega en tiempo de PETICIÓN cuando no hay política —con un 500— y eso está
 * bien como red, pero aquí se puede saber antes: la lista de expuestos es conocida al arrancar. Así
 * que se comprueba al arrancar, que es cuando quien configuró está mirando, en vez de a la mitad de
 * una petición de alguien más.
 */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Your Name',
    site: 'https://example.com',
    name: 'OperationsHttp',
    type: 'Web',
)]
class OperationsHttpPlugin implements PluginInterface, RouteProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * La raíz de esta app.
     *
     * Es un método y no una constante para que una prueba pueda apuntar a un árbol propio: la
     * alternativa era escribir dentro del `config/` real y confiar en borrarlo después.
     */
    protected function appRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** El contenedor con el que se armó — donde queda registrado el proyector. */
    public function container(): DIContainerInterface
    {
        return $this->container;
    }

    public function boot(): void
    {
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    /**
     * Una ruta por operación expuesta, todas atendidas por el mismo proyector.
     *
     * El proyector se registra en el contenedor bajo su propia clase porque cada ruta apunta a
     * `HandlerReference(HttpProjector::class, 'handle')` y se resuelve por esa llave al despachar.
     * Tiene que haber UNA instancia: dos plugins registrando la suya harían que las rutas de la
     * primera resolvieran a una instancia que no conoce su operación, y contestaran 404.
     *
     * @return list<\Milpa\Http\Routing\Route>
     */
    public function routes(): array
    {
        $expuestas = $this->exposed();
        if ($expuestas === []) {
            return [];
        }

        $politica = $this->container->has(OperationHttpPolicy::class)
            ? $this->container->get(OperationHttpPolicy::class)
            : null;

        $this->assertGuarded($expuestas, $politica instanceof OperationHttpPolicy);

        $psr17 = new Psr17Factory();
        $proyector = new HttpProjector(
            $expuestas,
            $this->container,
            $psr17,
            $psr17,
            policy: $politica instanceof OperationHttpPolicy ? $politica : null,
        );

        $this->container->registerService(HttpProjector::class, $proyector);

        return $proyector->routes();
    }

    /**
     * Los átomos que `config/http.php` nombra, entre todos los que esta app declara.
     *
     * Un nombre que no corresponde a ninguna operación se DICE al arrancar. Ignorarlo dejaría a
     * alguien esperando una ruta que nunca se registró, y buscando el error en el servidor web.
     *
     * @return list<Operation>
     */
    private function exposed(): array
    {
        $root = $this->appRoot();
        $config = $root . '/config/http.php';
        if (!is_file($config)) {
            return [];
        }

        /** @var array{expose?: list<string>} $declarado */
        $declarado = require $config;
        $nombres = $declarado['expose'] ?? [];
        if ($nombres === []) {
            return [];
        }

        /** @var list<class-string> $declarados */
        $declarados = require $root . '/config/plugins.php';

        $porNombre = [];
        foreach (Operations::declared($this->container, $declarados, $root) as $operacion) {
            $porNombre[$operacion->name] = $operacion;
        }

        $expuestas = [];
        foreach ($nombres as $nombre) {
            if (!isset($porNombre[$nombre])) {
                throw new \RuntimeException(
                    "config/http.php expone «{$nombre}» y ninguna operación se llama así. "
                    . 'Las que hay: ' . implode(', ', array_keys($porNombre)) . '.',
                );
            }
            $expuestas[] = $porNombre[$nombre];
        }

        return $expuestas;
    }

    /**
     * Ninguna operación protegida se publica sin quién la proteja.
     *
     * Se comprueba al ARRANCAR y no al atender: la lista de expuestos ya se conoce, así que el error
     * puede llegarle a quien configuró en vez de a quien llamó.
     *
     * @param list<Operation> $expuestas
     */
    private function assertGuarded(array $expuestas, bool $hayPolitica): void
    {
        if ($hayPolitica) {
            return;
        }

        $protegidas = [];
        foreach ($expuestas as $operacion) {
            if ($operacion->scopes !== [] || $operacion->permission !== null) {
                $protegidas[] = $operacion->name;
            }
        }

        if ($protegidas !== []) {
            throw new \RuntimeException(
                'config/http.php expone operaciones que exigen identidad (' . implode(', ', $protegidas) . ') '
                . 'y esta app no registró un ' . OperationHttpPolicy::class . '. '
                . 'Registra uno —milpa/admin publica el que usa milpa/auth— o quita esas de la lista.',
            );
        }
    }
}
