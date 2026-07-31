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

namespace App\Tests\Plugins;

use App\Plugins\HelloPlugin\HelloPlugin;
use App\Plugins\OperationsHttpPlugin\OperationsHttpPlugin;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Plugin\PluginInterface;
use PHPUnit\Framework\TestCase;

/**
 * Los cuatro momentos del ciclo de vida de los plugins que esta plantilla trae.
 *
 * Están vacíos a propósito —ninguno de los dos guarda nada que instalar o borrar— y por eso se
 * prueban: un método vacío que alguien llena a medias es la forma más silenciosa de romper
 * `plugins:install`, y aquí lo que se fija es que los cuatro se puedan llamar sin efecto y sin
 * excepción. Es también el ejemplo que copia quien escribe el suyo.
 */
final class PluginLifecycleTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: PluginInterface}>
     */
    public static function plugins(): array
    {
        $container = new DIContainer();

        return [
            ['HelloPlugin', new HelloPlugin($container)],
            ['OperationsHttpPlugin', new OperationsHttpPlugin($container)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plugins')]
    public function testTheFourLifecycleMomentsRunWithoutEffectAndWithoutThrowing(string $nombre, PluginInterface $plugin): void
    {
        $plugin->install();
        $plugin->enable();
        $plugin->disable();
        $plugin->uninstall();

        self::assertTrue(true, "{$nombre} sobrevive su ciclo de vida completo");
    }
}
