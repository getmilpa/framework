<?php

declare(strict_types=1);

use Milpa\Plugin\Operations\PluginManagementPlugin;
use App\Plugins\HelloPlugin\HelloPlugin;
use App\Plugins\OperationsHttpPlugin\OperationsHttpPlugin;

/**
 * Los plugins que esta app arranca.
 *
 * La lista se lee en un diff, y ésa es la idea: qué corre en esta app es una decisión versionada, no
 * el resultado de escanear un directorio.
 *
 * `plugins.register` SÍ agrega líneas aquí, y lo que protege esa propiedad no se pierde: lo valioso
 * no es que la teclee una persona, es que la decisión quede escrita y legible — y el commit la
 * muestra igual. Lo que autoriza cada llamada es la intención de quien pidió el trabajo: el plugin
 * tiene que venir NOMBRADO en la petición (el contrato de ADR-0044), así que «escribe el plugin Hola
 * y verifica» procede —verificar exige que arranque— y «haz algo con los plugins» se detiene y
 * pregunta.
 *
 * Lo que sigue fuera por construcción es el miedo real: **un paquete de la red no entra por aquí.**
 * `plugins.register` sólo declara clases que YA existen en `src/Plugins/` de esta app; instalar desde
 * fuera es `capabilities:enable`, que además pasa por el verificador.
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

    // Sirve por HTTP las operaciones que `config/http.php` nombre — ninguna, hasta que nombres una.
    OperationsHttpPlugin::class,
];
