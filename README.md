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

## Two screens

```bash
php bin/coa shell    # everything this app can do, navigable — and runnable
php bin/coa chat     # a conversation with the agent
```

`shell` lists the operations grouped by whether they read or change something, opens one with Enter,
lets you fill its fields and runs it — the same coercion and the same verdict the CLI uses. An
operation that demands a **signature** is not run from there: a signature names *this* call and is
produced with a key that lives outside the screen, so the form refuses and prints the exact
`coa … --sign` line instead. `Esc` goes back.

Neither is an operation, and neither pretends to be: an operation runs with what it was given and
answers. These converse. Piped or in CI they print one frame and exit — whether there is a terminal
is a fact of the destination, not of the screen.

## The agent

`milpa/ai-gateway` ships the loop that alternates model ↔ tools. `coa agent` is the line that calls
it, and what it hands the model is not a separate catalogue: it is **this app's operations**, the
same ones an MCP client sees.

```bash
export ANTHROPIC_API_KEY=...      # or OPENAI_API_KEY
php bin/coa agent "which plugins are on, and would enabling the other one resolve?"
```

Without a key it says so and stops. There is no demo mode: an agent that answers something plausible
without having called anything teaches you to trust answers nobody produced. The answer comes back
with how many steps it took and how many tools it had, because "the agent replied" does not
distinguish using your app from replying from memory.

**Your own model works too.** Point it at anything OpenAI-compatible — an Ollama on your LAN, a
vLLM, a proxy — and no token leaves the building:

```bash
export MILPA_AGENT_BASE_URL=https://llama.local
export MILPA_AGENT_BASIC_AUTH=user:pass      # if the endpoint asks for it
export MILPA_AGENT_MODEL=qwen3-coder:30b
php bin/coa agent "which plugins are on?"
```

A declared endpoint wins over any provider key sitting in the environment: whoever pointed their
agent at a local model does not want a forgotten `ANTHROPIC_API_KEY` sending it elsewhere — and
billing them.

It is a **terminal** operation only. An agent running over HTTP with the server's credentials is a
different decision, and this template does not take it for you.

## Serving operations over HTTP

The fourth surface. `config/http.php` names which operations get a route — and it is **empty**:

```php
return ['expose' => ['plugins.list']];   //  →  GET /plugins
```

`coa` and MCP run on the machine of whoever invokes them; an HTTP route can be called by anyone who
reaches the server. Exposing everything by default would turn installing a plugin into publishing an
API nobody decided on. Same doctrine as `config/plugins.php`: what runs is a versioned decision.

### Identity, so the protected ones can be served too

Most operations worth exposing declare `scopes` — all twelve `plugins.*` do. This app wires the three
pieces that make them servable, in `config/boot.php`:

1. a **token store** (`milpa/data`, file-backed by default — change the driver in `config/app.php`),
2. a **verifier** that turns `Authorization: Bearer …` into an actor with scopes,
3. a **policy** (`milpa/auth`) that decides whether that actor may run *this* operation.

Mint a token, expose the operation, call it:

```bash
php bin/coa token:new ci --scopes=plugins:read
#  token: 6e59b6a4…      ← shown once; only its hash is stored
```

```php
// config/http.php
return ['expose' => ['plugins.list']];
```

```bash
curl -H "Authorization: Bearer 6e59b6a4…" localhost:8000/plugins   # 200
curl localhost:8000/plugins                                        # 401 MILPA_AUTH_CONTEXT_MISSING
```

A token with the wrong scopes gets a 403 that names what was missing. `token:list` never prints the
secret or its hash, `token:revoke` takes effect on the next request, and the three token operations
are **terminal-only**: whoever can mint a token can mint one with every scope.

Remove those three lines from `config/boot.php` and the app still runs whole over the terminal and
MCP — it just cannot expose anything that declares scopes, and the boot says so.

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
config/http.php          which operations get an HTTP route (empty by default)
config/boot.php          the container, the plugin list, and the identity chain
config/app.php           the config bag plugins read in boot()
public/index.php         the HTTP entry point
src/Plugins/HelloPlugin  proof of life: one route, one response
src/Plugins/OperationsHttpPlugin  serves whatever config/http.php names
src/Operations            this app's own atoms — `agent` and `token:*` live here
src/Auth                  the API-token store and the verifier behind them
src/Tui                   the agent conversation screen
```

`config/plugins.php` is a list, not a scan. What runs in this app is a versioned decision — a plugin
that installs itself from the network is an attack surface, not a convenience.

## Coming from `milpa/skeleton`

`milpa/skeleton` was the previous `composer create-project` target and is now **abandoned** — this
package replaces it. Two entry points both saying *start here* is a second source of truth, and the
one a newcomer lands on decides what they believe Milpa is.

An app you already created from the skeleton keeps working: what it runs is your code, not that
package. If you are starting a new one, start here. What the skeleton brought and this does not are
`milpa/auth` and `milpa/data` — add them when your app needs them, which is the point of a floor you
build up from. Its one unique capability, the HTTP projection of operations, now lives in
`milpa/console` (with the auth-backed policy in `milpa/admin`).

## What this is NOT

It is not a web framework and it will not become one. There is no ORM, no template engine, no
router-with-batteries. `milpa/data` is here for one job — storing API tokens — and its four drivers
(file, sqlite, mysql, memory) are as much persistence as this floor takes a position on. `milpa/http` ships routing **contracts**; bring your own PSR-7 implementation
(this app ships `nyholm/psr7`) and your own persistence.

## License

Apache-2.0 © Rodrigo Vicente - TeamX Agency
