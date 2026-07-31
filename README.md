# milpa/framework

The `composer create-project` starting point for a Milpa app: a runtime where **every capability is a
declared Operation**, reachable from the terminal, from MCP and from a TUI at once.

No database. No web framework. No command framework. What you get is a substrate an agent can
operate, and a plugin system to grow it.

```bash
composer create-project milpa/framework my-app
cd my-app
php bin/coa
```

## What `coa` is

`coa` does not implement any command. It boots the kernel, collects the operations that packages and
plugins declared, projects them onto the terminal and runs one:

```
coa — el runtime de esta app. Cada comando es una operación declarada.

Consultan:
  plugins:list  List every installed plugin with its version, type and whether it boots.
  plugins:show  Everything the registry knows about one plugin.
  validate      Valida el manifiesto de un plugin y los proveedores que declara

Cambian algo:
  make             Andamia un artefacto del framework (controller o entity) y lo verifica
  plugins:disable  Turn a plugin off without removing it or its data.
  plugins:enable   Turn a plugin on: it boots from the next request or command.
```

That listing is **derived**, not written. Install a plugin that declares operations and they appear —
in `coa`, in the MCP tool registry, and in a TUI — with no edit to any file of this app.

## The one idea

A capability is declared **once**, as an `Operation`: a name, a description, an input schema, a
handler, and whether it mutates.

```php
new Operation(
    name: 'backup_create',
    description: 'Take a backup of the data directory',
    handler: [BackupHandler::class, 'create'],
    inputSchema: ['type' => 'object', 'properties' => [
        'label' => ['type' => 'string', 'description' => 'How to name it'],
    ]],
    mutating: true,
    requiresConfirmation: true,
);
```

Return it from a plugin's `operations()` and you are done. A **projector** turns that declaration
into each surface's own shape — a command, an MCP tool, a TUI form — and a **renderer** materialises
it. Neither of them is something you write.

The corollary is the point: you never write the same capability twice, and you cannot have a
capability that the terminal offers and an agent cannot reach.

## Consent is part of the declaration

`mutating` says the operation changes something. `requiresConfirmation` says the change cannot be
undone, and on the terminal that means a **signature over this exact call** — the operation, these
arguments, this host:

```
$ coa plugins:remove SomePlugin
This operation mutates and needs your authorization. Re-run with --sign.

  --sign signs THIS call — the operation, these arguments, this host — with your
  key. The authorization cannot be presented for a different target, which is
  what a confirmation flag could never promise.
```

The declaration travels with the operation, so every surface applies the same rule. It is not a flag
the terminal invented.

## What is opt-in, and why

The box is deliberately small. Two examples of what it does **not** include:

- **Filesystem and shell primitives.** A framework that installs an agent with a shell by default is
  a security decision, not a packaging one. `read`/`write`/`edit`/`grep`/`shell` live in a separate
  plugin you install on purpose.
- **The remote plugin operations.** `plugins:list`, `:show`, `:enable` and `:disable` are here.
  `:install`, `:update` and `:remove` appear only once you wire a `PluginInstallerInterface` — an app
  that never reaches the network does not grow the operations that would.

## Layout

```
bin/coa                  the dispatcher — boots, projects, runs
bin/mcp-server.php       the same operations, over MCP
config/plugins.php       which plugins boot (a list you read in a diff)
config/operations.php    which packages contribute operations
config/app.php           the config bag plugins read in boot()
public/index.php         the HTTP entry point
src/Plugins/HelloPlugin  proof of life: one route, one response
```

`config/plugins.php` is a list, not a scan. What runs in this app is a versioned decision — a plugin
that installs itself from the network is an attack surface, not a convenience.

## What this is NOT

It is not a web framework and it will not become one. There is no ORM, no template engine, no
router-with-batteries. `milpa/http` ships routing **contracts**; bring your own PSR-7 implementation
(this app ships `nyholm/psr7`) and your own persistence.

## License

Apache-2.0 © Rodrigo Vicente - TeamX Agency
