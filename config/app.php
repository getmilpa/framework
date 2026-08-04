<?php

declare(strict_types=1);

/**
 * The app-config bag — the second source of truth `Milpa\Runtime\Kernel::boot()` reads, via
 * `$config['config']`. It is registered in the container as `Milpa\Runtime\Config` and is the
 * seam plugins use to receive configuration WITHOUT constructor arguments: `PluginInterface`
 * fixes the constructor to `(DIContainerInterface $container)`, so a plugin that needs a value
 * reads it in `boot()` with `$container->get(Config::class)->get('app.greeting')` instead of an
 * env var or a widened constructor. Keys are indexed with dot notation, so a nested array here
 * (`'app' => ['greeting' => ...]`) reads back as `get('app.greeting')`.
 *
 * `HelloPlugin::boot()` reads `app.greeting` from here — edit the string below, reload the page,
 * and watch it change. That is the whole idiom: config lives in this file, plugins read it in
 * `boot()`.
 *
 * @return array<string, mixed>
 */
return [
    'app' => [
        'name' => 'Milpa App',
        'greeting' => 'Milpa is running.',
    ],

    /*
     * WHERE THINGS GET STORED.
     *
     * `Milpa\Data\RepositoryFactory::fromConfig()` reads this block and builds one of four backends,
     * all behind the same `RepositoryInterface` — so moving from a JSON file to a real database is
     * this block, and nothing else. Anything `coa make entity` or `coa make crud` scaffolds already
     * reads it, which is why the generated code never names a backend.
     *
     *   'driver' => 'file',    'path' => __DIR__ . '/../var/products.json'   // the whole collection in one JSON file
     *   'driver' => 'sqlite',  'path' => __DIR__ . '/../var/app.sqlite'      // a real database, still one file, zero servers
     *   'driver' => 'mysql',   'dsn'  => 'mysql:host=127.0.0.1;dbname=app',  'user' => '…', 'password' => '…'
     *   'driver' => 'memory'                                                  // nothing outlives the process — tests
     *
     * Left commented ON PURPOSE. With no `storage` block the generated code falls back to a JSON file
     * under `var/`, so a fresh app persists with zero configuration; uncommenting is how you say
     * something different. A default written here instead would be a decision this file made for you
     * while looking like a suggestion.
     *
     * Doctrine is a separate story: it belongs to the LEGACY host convention (`coa make entity
     * --flavor=legacy`, which needs `composer require doctrine/orm`), not to this one. A runtime app
     * persists through `milpa/data`, and the entities `make` writes implement `Milpa\Data\EntityInterface`
     * — no ORM attributes, no mapping, no metadata cache.
     */
    /*
     * EL AGENTE.
     *
     * `instructions` es lo que esta app quiere que su agente sepa y que no le toca decir a ningún
     * plugin — llega al prompt de sistema junto con las secciones que aportan los plugins.
     *
     * `compaction` decide cuándo una sesión larga resume lo viejo (P16.2). Los números dependen del
     * modelo: una ventana de 8k y una de 200k no se compactan igual, y por eso son config y no una
     * constante. `keepRecent` conviene holgado — el resumen contesta «qué ha pasado» y sólo los turnos
     * íntegros contestan «en qué íbamos».
     *
     * ── `permissionWindow`: UNA OPINIÓN INICIAL, NO UNA DOCTRINA ────────────────────────────────
     *
     * Cuánto tiempo tiene un humano para contestar antes de que la sesión se declare muerta. Sin
     * ella, una pregunta espera para siempre — y como una sesión con pregunta abierta no es
     * retomable, la que nadie contestó no queda pausada: queda muerta sin que nadie lo declare
     * (Q-P19-B).
     *
     * **El runtime no trae default y no debe traerlo**: no tiene forma de saber quién opera esta app.
     * Esta plantilla sí propone uno, porque una plantilla ES una opinión inicial y las opiniones
     * iniciales valen mientras sean explícitas.
     *
     * **La plantilla propone `PT8H` —una jornada— y los hosts deben ajustarlo a su operación.** No
     * hay evidencia detrás de las ocho horas: es una hipótesis razonable, no un hallazgo. Lo que sí
     * está medido es el problema que resuelve. Un equipo que opera de guardia querrá `PT1H`; uno que
     * revisa los lunes, `P3D`; quien no quiera plazo, borra la línea.
     */
    // 'agent' => [
    //     'instructions' => 'Los precios de esta app van en centavos.',
    //     'compaction' => ['maxTurns' => 40, 'keepRecent' => 12],
    //     'permissionWindow' => 'PT8H',
    //
    //     // QUÉ LLAMADAS AMERITAN UN SEGUNDO LECTOR ANTES DE PREGUNTARTE.
    //     //
    //     // Van por NOMBRE DE HERRAMIENTA, que es el de la operación con lo no alfanumérico
    //     // convertido a `_` (`capabilities:enable` → `capabilities_enable`) — así lo proyecta
    //     // `McpProjector::toolName()`, y es contra eso que el juez compara.
    //     //
    //     // `capabilities_enable` viene primero por una razón de clase, no de gusto: instalar un
    //     // paquete corre código de la red EN ESTA MÁQUINA y cambia las dependencias de la app. La
    //     // compuerta de permisos la trata igual que a un `make` —muta, se pregunta— y no es igual.
    //     // Un verificador que la clasifique antes te da con qué contestar; sin él, el «¿autorizas
    //     // capabilities:enable?» te llega sin nada que evaluar salvo el nombre.
    //     'secondOpinion' => ['capabilities_enable'],
    // ],

    // 'storage' => [
    //     'driver' => 'sqlite',
    //     'path' => __DIR__ . '/../var/app.sqlite',
    // ],
];
