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
     * Se usa `validate` y no una operación de plugins porque las SIETE de `milpa/plugin` declaran
     * scopes: en una app sin identidad, ninguna de ellas se puede publicar — y eso es la compuerta
     * funcionando, no un defecto de esta prueba.
     */
    public function testAnExposedOperationGetsItsRoute(): void
    {
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
        $this->expose('validate');

        $plugin = $this->plugin();
        $plugin->routes();

        self::assertTrue($plugin->container()->has(HttpProjector::class));
    }
}
