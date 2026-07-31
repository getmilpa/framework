<?php

declare(strict_types=1);

use Milpa\Plugin\Operations\PluginManagementPlugin;
use App\Plugins\HelloPlugin\HelloPlugin;

/**
 * Los plugins que esta app arranca.
 *
 * La lista se lee en un diff, y ésa es la idea: qué corre en esta app es una decisión versionada, no
 * el resultado de escanear un directorio. `PluginManagementPlugin` puede encender y apagar lo que
 * hay, pero no agrega líneas aquí — un plugin que se instala solo desde la red es una superficie de
 * ataque, no una comodidad.
 *
 * `PluginManagementPlugin` es lo que hace administrables a los demás: contribuye las operaciones
 * `plugins.*` que la terminal, MCP y la TUI proyectan cada una a su forma. Bórralo y esta app vuelve
 * a estar gobernada sólo por esta lista.
 *
 * @return list<class-string>
 */
return [
    PluginManagementPlugin::class,
    HelloPlugin::class,
];
