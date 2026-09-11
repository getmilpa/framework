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

namespace App\Plugins\HelloPlugin;

use App\Plugins\HelloPlugin\Controllers\DesignController;
use App\Plugins\HelloPlugin\Controllers\HomeController;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Kernel;
use Milpa\AppRuntime\Framework\FrameworkStamp;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * The proof-of-life plugin: provides nothing, requires nothing, and contributes one
 * route (`GET /`) so `composer create-project` followed by `php -S localhost:8000 -t public`
 * shows something alive immediately — zero database, zero configuration beyond this file and
 * `config/plugins.php`.
 *
 * Copy this plugin's shape (metadata + boot + {@see RouteProviderInterface::routes()}) as the
 * starting point for your own — see the README's "Add a plugin" section.
 */
#[PluginMetadata(
    version: '0.1.0',
    author: 'milpa/framework', // the skeleton's own — make it yours when you make the plugin yours
    site: 'https://github.com/getmilpa/framework',
    name: 'HelloPlugin',
    type: 'Web',
)]
final class HelloPlugin implements PluginInterface, RouteProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
        // The container is kept as a promoted readonly property because this plugin reads the
        // app-config bag in boot() (see below). PluginInterface fixes the constructor signature
        // to ($container), so this is the ONLY thing a plugin gets injected — never config values
        // directly. Everything else it needs, it resolves from the container.
    }

    public function boot(): void
    {
        // The Config idiom: read app configuration here in boot() via the container, NOT through
        // a constructor argument or an env var. `config/app.php` is registered by Kernel::boot()
        // as Milpa\Runtime\Config; dot-notation walks the nested array.
        // The default is deliberately DISTINCT from config/app.php's value: if you ever see it on
        // the page, the config bag was empty (e.g. config/app.php missing), which is a useful tell.
        $greeting = $this->container->get(Config::class)->get('app.greeting', 'Milpa is running (default greeting).');
        \assert(\is_string($greeting));

        // WHICH FRAMEWORK THIS HOUSE RUNS, read the same way: here, and handed over.
        //
        // `.milpa/framework.json` is the birth record — bumped by release-please on every release and
        // extended by `tools/stamp-framework.php` on `create-project`. It is the only thing that can
        // answer this: `milpa/framework` is the ROOT package of a created app, so it is not in
        // `composer.lock`'s package list at all, and a version typed into the page would be a second
        // answer to a question one file already answers (greenhouse decisions/0303).
        //
        // Null when the record is absent — a tree that was copied rather than created. The page says
        // nothing rather than guessing, the way `house:start` says what it could not do.
        $version = FrameworkStamp::version($this->root());

        // Wire the values into the collaborator that renders them. The controller is resolved from
        // the container at request time, so registering the built instance here is what makes the
        // config value reach the page — the plugin owns that wiring, the controller stays dumb.
        $this->container->registerService(HomeController::class, new HomeController($greeting, $version));
    }

    /** Where this app lives, asked of the Kernel rather than derived from `__DIR__`. */
    private function root(): string
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;

        return $kernel instanceof Kernel ? $kernel->root() : \dirname(__DIR__, 3);
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    /** This house's design-system mount point — see {@see designPrefix()}. */
    private const string DESIGN_PREFIX = '/design';

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    /**
     * Where this house mounts the design system — the one place the prefix is typed.
     *
     * The five files under it come from {@see \Milpa\Live\Support\DesignTokens::urls()} rather than from typing, and the
     * two route PATTERNS below (`{file}`, `{face}`) are this house's shape, which no authority can
     * produce for it. What is shared is the prefix and the filenames; the shape stays local
     * (greenhouse decisions/0308).
     */
    public static function designPrefix(): string
    {
        return self::DESIGN_PREFIX;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return [
            new Route(
                path: '/',
                methods: HttpMethod::GET,
                name: 'home',
                handler: new HandlerReference(HomeController::class, 'index'),
            ),
            // MILPA'S DESIGN SYSTEM, SERVED FROM THIS HOUSE. The tokens and fonts ship inside
            // `milpa/live-web` and nothing reached them, so the page below hand-rolled six hex values
            // and two of them were unreadable. A page that can link the tokens never has to invent a
            // colour — see {@see DesignController} (greenhouse decisions/0298).
            new Route(
                path: self::DESIGN_PREFIX . '/{file}',
                methods: HttpMethod::GET,
                name: 'design.file',
                handler: new HandlerReference(DesignController::class, 'file'),
            ),
            new Route(
                path: self::DESIGN_PREFIX . '/fonts/{face}',
                methods: HttpMethod::GET,
                name: 'design.face',
                handler: new HandlerReference(DesignController::class, 'face'),
            ),
        ];
    }
}
