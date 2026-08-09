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

namespace App\Tests\Http;

use App\Tests\Support\OptIn;
use App\Plugins\OperationsHttpPlugin\OperationsHttpPlugin;
use Milpa\Command\Operation;
use Milpa\Console\Http\HttpProjector;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * La cuarta superficie de esta app: las operaciones que `config/http.php` nombre, servidas por HTTP.
 *
 * Lo que se cuida aquí no es que HTTP funcione —eso lo prueba `milpa/console`— sino las tres
 * decisiones de ESTA plantilla: que por default no se publique nada, que un nombre inventado se diga
 * al arrancar, y que una operación protegida no llegue a la red sin quién la proteja.
 */
final class OperationsHttpTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        // Un árbol de app de mentiras con la misma forma que ésta: `config/` con sus tres archivos.
        // El plugin lee su configuración de `dirname(__DIR__, 3)` respecto a SU archivo, así que la
        // prueba usa la subclase de abajo para apuntar a este árbol — la alternativa era escribir
        // dentro de `config/` del paquete real y confiar en borrarlo después.
        $this->tmp = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'milpa-fw-http-' . uniqid();
        mkdir($this->tmp . '/config', 0777, true);
        file_put_contents($this->tmp . '/config/plugins.php', "<?php\n\nreturn [\\Milpa\\Plugin\\Operations\\PluginManagementPlugin::class];\n");
        file_put_contents($this->tmp . '/config/operations.php', "<?php\n\nreturn [\\Milpa\\DevTools\\Operations\\DevToolsOperations::class];\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/config/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmp . '/config');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function expose(string ...$nombres): void
    {
        file_put_contents(
            $this->tmp . '/config/http.php',
            "<?php\n\nreturn " . var_export(['expose' => array_values($nombres)], true) . ";\n",
        );
    }

    private function plugin(?OperationHttpPolicy $politica = null): OperationsHttpPlugin
    {
        $container = new DIContainer();
        $container->registerService(
            \Milpa\Plugin\Contracts\PluginRegistryInterface::class,
            new \Milpa\Plugin\Registry\InMemoryPluginRegistry(),
        );
        if ($politica !== null) {
            $container->registerService(OperationHttpPolicy::class, $politica);
        }

        // La raíz viaja por una propiedad estática y no por el constructor: `PluginInterface` fija la
        // firma a `($container)`, así que una subclase que agregue un argumento no es un plugin.
        $subclase = new class ($container) extends OperationsHttpPlugin {
            public static string $raiz = '';

            protected function appRoot(): string
            {
                return static::$raiz;
            }
        };
        $subclase::$raiz = $this->tmp;

        return $subclase;
    }

    /**
     * Por default NO se publica nada, ni siquiera lo que `coa` sí ofrece.
     *
     * Es la decisión de la plantilla: `coa` y MCP corren en la máquina de quien los invoca, y una ruta
     * HTTP la puede llamar cualquiera que alcance el servidor. Instalar un plugin no debería publicar
     * una API que nadie decidió.
     */
    public function testNothingIsPublishedUntilSomebodySaysSo(): void
    {
        $this->expose();

        self::assertSame([], $this->plugin()->routes());
    }

    /**
     * Lo nombrado sí se publica, con el verbo y la ruta que el átomo declara.
     *
     * Se usa `validate` y no una operación de plugins porque las de `milpa/plugin` declaran scopes:
     * en una app sin identidad, ninguna se puede publicar — y eso es la compuerta funcionando, no un
     * defecto de esta prueba.
     *
     * ── POR QUÉ ESTOS TRES CASOS SÍ SON FRONTERA (evidence/0103) ─────────────────────────────────
     *
     * decisions/0013 los listó como PISO, diciendo que «su fixture está mal, no la app». Se midió y
     * es al revés. El plugin arma su universo con `Operations::declared()` —sólo lo que declaran los
     * PLUGINS de la app, no lo que trae el runtime—, así que `capabilities` y las demás sin scopes
     * NO son candidatas. En un recién nacido quedan ocho, y las ocho declaran scopes.
     *
     * O sea: para medir «lo expuesto obtiene su ruta» hace falta una operación declarada por un
     * plugin y SIN scopes, y la única que existe la trae `milpa/devtools`. El autor original eligió
     * bien; lo que estaba mal era la clasificación.
     */
    public function testAnExposedOperationGetsItsRoute(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $this->expose('validate');

        $rutas = $this->plugin()->routes();

        self::assertCount(1, $rutas);
        self::assertSame('/validate', $rutas[0]->path);
        self::assertSame('validate', $rutas[0]->name);
        self::assertSame([\Milpa\Http\HttpMethod::GET], $rutas[0]->methods);
    }

    /**
     * Un nombre que no corresponde a ninguna operación se dice AL ARRANCAR, con la lista de las que
     * hay.
     *
     * Ignorarlo dejaría a alguien esperando una ruta que nunca se registró, buscando el error en el
     * servidor web — lejos del archivo donde está el typo.
     */
    public function testAnUnknownOperationNameIsSaidAtBootWithTheOnesThatExist(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $this->expose('validat');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/validat.*validate/s');

        $this->plugin()->routes();
    }

    /**
     * Una operación que exige identidad NO llega a la red sin quién la proteja: el arranque se
     * detiene.
     *
     * Es el caso REAL de esta plantilla y no un ejemplo de laboratorio: las siete operaciones de
     * `milpa/plugin` declaran scopes, así que una app sin identidad no puede publicar ninguna.
     *
     * `HttpProjector` ya se niega en tiempo de petición, y eso está bien como red. Pero aquí se puede
     * saber antes —la lista de expuestos se conoce al arrancar— así que el error le llega a quien
     * configuró en vez de a quien llamó.
     */
    /**
     * ── ESTE CASO ES EL PISO DE LA GUARDA, Y F2 LO CONFIRMÓ (evidence/0109) ──────────────────────
     *
     * El mecanismo tiene dos caminos que caen en lados opuestos de la compuerta de opt-ins:
     *
     *   «lo expuesto obtiene su ruta»  necesita una operación SIN scopes → sólo la traen las dev tools
     *   «sin política se RECHAZA»      necesita una CON scopes y ninguna política → un recién nacido
     *                                  tiene ocho y cero, así que lo mide de sobra
     *
     * evidence/0109 fue a construirle un piso a este mecanismo **y ya estaba aquí**. La mutación M5′
     * —destripar `assertGuarded()`— pone rojo el arm PELÓN, y esta prueba es una de las dos que la ven.
     *
     * Lo que sí quedó medido es que la M5 original de decisions/0013 —registrar la política bajo otra
     * llave— **apunta a código que no corre en una app pelona**: ese registro vive dentro de una guarda
     * de opt-ins. Su verde pelón no era el gate escondiendo piso; era una mutación mal apuntada.
     */
    public function testAProtectedOperationRefusesToBePublishedWithoutAPolicy(): void
    {
        // `plugins.enable` declara scopes `plugins:write`.
        $this->expose('plugins.enable');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/plugins\.enable/');

        $this->plugin()->routes();
    }

    /** Con política registrada, la misma operación sí se publica. */
    public function testWithAPolicyTheProtectedOperationIsPublished(): void
    {
        $this->expose('plugins.enable');

        $politica = new class () implements OperationHttpPolicy {
            public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
            {
                return null;
            }
        };

        $rutas = $this->plugin($politica)->routes();

        self::assertCount(1, $rutas);
        self::assertSame('plugins.enable', $rutas[0]->name);
        self::assertSame([\Milpa\Http\HttpMethod::POST], $rutas[0]->methods, 'muta, así que POST');
    }

    /**
     * El proyector queda registrado bajo su clase — sin eso las rutas contestarían 404.
     *
     * Cada ruta apunta a `HandlerReference(HttpProjector::class, 'handle')` y se resuelve por esa
     * llave al despachar: publicar rutas sin registrar la instancia es publicar 404s.
     */
    public function testTheProjectorIsRegisteredSoTheRoutesCanResolve(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $this->expose('validate');

        $plugin = $this->plugin();
        $plugin->routes();

        self::assertTrue($plugin->container()->has(HttpProjector::class));
    }
}
