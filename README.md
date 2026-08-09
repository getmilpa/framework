<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

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

## The same operation, on four surfaces

A claim like that is cheap, so here is the whole of it on one install. `plugins.list` is declared
once, by the plugin-management plugin a newborn app already boots. Nobody wrote a controller, a
route, an MCP tool or a form for it. Output is trimmed to four fields for width and otherwise
untouched.

**Terminal** — a bare `composer create-project`, nothing else installed:

```console
$ php bin/coa plugins:list --json
{"ok":true,"result":{"plugins":[{"name":"HelloPlugin","version":"0.1.0","type":"Web","enabled":true}]}}
```

**MCP** — after `composer require milpa/mcp-server`, speak JSON-RPC to `bin/mcp-server.php`:

```console
$ … | php bin/mcp-server.php
{"jsonrpc":"2.0","result":{"content":[{"type":"text",
 "text":"{\"success\":true,\"data\":{\"plugins\":[{\"name\":\"HelloPlugin\",\"version\":\"0.1.0\",…}]}}"}]}}
```

**HTTP** — name it in `config/http.php`, which serves nothing until you do:

```php
return ['expose' => ['plugins.list']];   //  →  GET /plugins
```

```console
$ curl -s localhost:8000/plugins
{"error":"[MILPA_UNAUTHENTICATED] … before calling a scope-protected route"}      # 401

$ php bin/coa token:new --actor=demo --scopes=plugins:read
$ curl -s -H "Authorization: Bearer $TOKEN" localhost:8000/plugins
{"plugins":[{"name":"HelloPlugin","version":"0.1.0","type":"Web","enabled":true}]}   # 200
```

That 401 is the surface working rather than failing, and a token holding the wrong scope answers
`403` naming the scope it wanted. Neither the operation nor the plugin knows any of it happened.

**TUI** — `php bin/coa shell`, every operation on one screen, this one among them.

One `new Operation(...)`, four answers. The envelope differs because each surface has its own — a
CLI result, an MCP content block, a bare HTTP body — and the payload underneath is the same object.

What a surface *may* show stays a decision, and it is declared where the capability is: an operation
names `surfaces:` when it should not be everywhere, which is why `agent` and `token:*` are
terminal-only by declaration rather than by accident, and why `config/http.php` starts empty.
Exposing an operation to the network is a decision someone should be able to read in a diff.

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

### The agent is governed, and 0.13 closed the loop that measurement demanded

Three authorities sit between the model and your app, each answering exactly one question:

- **The floor** (`SessionPolicy`): does this need permission or a signature? No autonomy mode
  skips a signature.
- **The intent contract** (ADR-0044): does a concrete operation even exist yet? An operation that
  declares `namedTarget` gets enforced **before** the permission policy — `auto` waives asking
  permission, not understanding what was asked. An unnamed target pauses the session with a formal
  question that carries the operation and its arguments; the human's *yes* names that target and
  only that target.
- **The second opinion** (`agent.secondOpinion` in `config/app.php`): does this concrete call go
  beyond what was asked? Its denial removes the option from the session's table and the loop
  continues with a different world.

And one rule that keeps all of it honest: **operations that adjudicate sessions are not tools of
the adjudicated**. `agent:answer` and `agent:mode` exist so a human governs the agent — they are
filtered out of the agent's own catalogue, because an agent that can answer its own pause or raise
its own autonomy is not governed; it is narrating governance.

When `agent.conditionalCatalog` is on, the run's result also says what the classifier decided
(`classifier: reads|changes|no-verdict|unreachable`) — the same reason `compacted` is reported:
anything that silently changes what the model sees, gets said.

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

## Hooks

Every operation — from the terminal, the TUI, HTTP or MCP — runs through one seam that announces it:

```php
$dispatcher->subscribe('operation.executing', function (array $payload): void {
    $op = $payload['event']->operation;
    if ($op->mutating && $payload['event']->surface === 'http') {
        $payload['slot']->stop();                       // deny it
        // $payload['slot']->shortCircuit($cached);     // …or answer for it
    }
});

$dispatcher->subscribe('operation.executed', function (array $payload): void {
    $e = $payload['event'];   // ->result, ->surface, ->ran(), ->shortCircuited, ->stopped, ->error
});
```

`operation.executed` fires on **every** outcome — answered, served from a listener, stopped, thrown.
An audit trail with holes is worse than none: it teaches you to trust an incomplete list. And the
event carries the surface it came in through, because the same operation arriving over HTTP and on
the machine's own terminal does not always deserve the same answer.

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
.milpa/foundation.json   the constitution — born EXPLICITLY unfounded (domain: null), filled by
                         the founding, amended only through recorded decisions
.milpa/decisions/        the choices that shaped this app: who, what, why
.milpa/evidence/         slice evidence — born empty, used from the founding itself
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

**Every Milpa is born with a constitution; not every Milpa needs a government yet.** The newborn's
only domain is *founding itself*: `.milpa/foundation.json` says what this app is — domain,
objective, boundaries, authorities — and it ships saying *not yet* (`domain: null`), with the
authorities already defaulting to the human: no app is born lawless, not even an unfounded one.
Declare the domain before scaffolding it; record the choices in `decisions/`; keep slice evidence
in `evidence/`. When the constitution needs to become verifiable, compiled law — ADRs with
supersession, enforced gates, corpus integrity — `milpa/governance` is one
`coa capabilities:enable governance` away, and it *adopts* the foundation as its antecedent, never
replaces it.

## Coming from an app you created before 0.21

Your app is not broken — it boots exactly as it did and every command still works. It is *frozen*:
26 of the 29 files in its `src/` were copied in the day you created it, and copied code never
receives an improvement again. From 0.21 those files ship as `milpa/app-runtime` instead.

**[UPGRADING.md](UPGRADING.md)** walks the migration: how to find out whether you edited any of them
first, then four steps and one check. Verified on a real 0.20.0 app — 29 operations before, the same
29 after.

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

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=framework)**.
