# From a new house to a steady one

A short walk through the words this house uses, in the order you will meet them. Each step is one
command you can run now. The house is **steady** when it is founded, offers what you need, has a model
its agent can reach, can undo every change it adopts, and keeps a record of what you decided and
measured.

Every command below runs from the house's root. A command that changes something lasting needs
`--sign`. Step 3 explains why and how.

## 1. Operations: everything is one

```bash
php bin/coa
```

Every command is an **operation**: one declaration that the terminal, an MCP client and (if you name it
in `config/http.php`) HTTP all project from the same source. The list splits into what *reads* and what
*changes something*. To see one operation's inputs and effects before you run it:

```bash
php bin/coa operation:contract foundation:found
```

You will see one operation spelled three ways, and all three are the same thing: `plugins:list` on the
terminal, `plugins.list` in declarations and messages, and `plugins_list` as a tool the agent calls.

## 2. Ask the house where it stands

```bash
php bin/coa house:start
```

This shows what the house is, which capabilities it has and which it can switch on, its routes, and
the next real steps. Come back to it whenever you are unsure what to do next.

## 3. Signing: who asked for this change

A **lasting change** (it writes the house's files, config or records) does not run unsigned. The house
refuses and says *Nothing ran*. Reads never need a signature. Sign with a GPG key:

```bash
gpg --quick-gen-key "Your Name <you@example.com>" ed25519 sign never   # once, if you have no key
php bin/coa foundation:found --domain="Notes" --objective="Keep short notes" --dry-run
```

`--dry-run` shows the exact writes without making them, and needs no signature. If a signed call says
*Nothing was signed*, gpg found no usable key. Check with `gpg --list-secret-keys`.

## 4. The constitution

`.milpa/foundation.json` is the house's **constitution**: its domain, objective, boundaries, and who
decides what. A new house ships it *unfounded* on purpose. Founding writes it once, along with its
first decision:

```bash
php bin/coa foundation:found --domain="Notes" --objective="Keep short notes" \
  --boundaries="never sends email" --sign
```

That writes `.milpa/decisions/0001-fundacion.md`. From then on, changing the constitution is a new
decision in `.milpa/decisions/`, never a silent edit. `php bin/coa foundation` reads it back.

## 5. Capabilities: installed is not declared

The house starts small. A **capability** is an opt-in package, and turning one on is one signed step:

```bash
php bin/coa capabilities
php bin/coa capabilities:enable milpa/devtools --sign
```

`capabilities:enable` does two things: it installs the package with Composer, and it **declares** what
the package brings in `config/operations.php` and `config/plugins.php`. A bare `composer require` only
does the first, so the house holds the code and does not offer it. Read those two files in a diff:
they are the record of what this house runs.

## 6. Plugins and routes

With devtools on, scaffold a plugin with a route, register it so the kernel boots it, and serve it:

```bash
php bin/coa make resource Notes Note --fields="title:string,body:string" --route=/notes --sign
php bin/coa plugins:register Notes --sign
php bin/coa routes:list
php bin/coa serve
```

`make` scaffolds by *what* you want: here `resource` is the whole slice — the `Note` entity, a page a
visitor reads at `/notes`, and the plugin that holds them. (Each artifact is its own `what`: `make entity`,
`make page`, `make crud`, `make controller`; run `php bin/coa operation:contract make` for the list. A page
is backed by an entity — a route that returns one needs `--returns=page` and an `--entity` — which is why
`resource`, that makes both at once, is the shortest first step.) `serve` answers at
`http://localhost:8000/` until you stop it with Ctrl-C. `make` verifies what it writes before it lands.
`plugins:register` adds the class to `config/plugins.php`, which is a list rather than a scan, so what boots
is always a versioned decision.

## 7. The agent, and how it asks

The agent is two capabilities: `milpa/ai-gateway` (a model on the other side) and `milpa/agent`
(sessions that outlive the process). Tell it which model to use and where that model lives, then check
that the house can actually reach it:

```bash
php bin/coa capabilities:enable milpa/ai-gateway --sign
php bin/coa capabilities:enable milpa/agent --sign
php bin/coa config:set agent.model qwen3.8-27b --sign
php bin/coa config:set agent.baseUrl http://localhost:11434 --sign
php bin/coa provider:declare agent.apiKey --value="..." --sign    # only if the provider wants a key
php bin/coa agent:model
```

`config:set` writes to `.milpa/agent.json`, which git does not ignore: you commit it. `provider:declare`
writes to `.milpa/secrets.json`, which is git-ignored and never echoed back.

Asking the agent is a lasting change too, so it is signed. Each run belongs to a **session**:

```bash
php bin/coa agent "Scaffold TagsController in the Notes plugin at /tags, method index" --sign
```

What the agent scaffolds does not land in the house: it runs in a **trial** (step 8). That run does not
pause. It ends with its answer and leaves the trial open for you to adopt.

A session has a **mode**. In `ask` (the default) it pauses before a call that would change the house
itself, such as adopting its own trial (`sandbox:promote`). The output shows the question, the session
and the exact command to answer:

```bash
php bin/coa agent:answer --session=<id> --answer=yes --sign
```

With `--mode=acknowledge` or `--mode=auto` the same call runs without a pause. No mode skips a
signature.

An answer is recorded; it does not resume the run. `agent:answer` prints the command that does, and the
call you said yes to runs then:

```bash
php bin/coa agent "continue" --session=<id> --sign
```

A run also stops when it runs out of steps. In `ask` it ends `steps_exhausted` after 12, or after the
number you pass as `--steps=<n>`, and the same `continue` picks it up. In `auto` a run takes up to 40
steps and the house continues it once on its own, to 60 in all, before it stops the same way. When the
house has already verified a session's work, `continue` says so and answers without calling the model.

## 8. Trials: the agent works on a copy

The agent's changes do not land in the house directly. Each one runs in a **trial**, a disposable copy
of the house (`sandbox` is the operations' prefix). The house adopts a trial only through
**promotion**. A run that scaffolds ends leaving its trial open: you adopt it, or the agent asks to
(step 7). You act on trials yourself, and the three commands that change something are signed:

```bash
php bin/coa sandbox:list                                # open trials and what each one changed
php bin/coa sandbox:promote --workspace=<id> --sign     # the only door in
php bin/coa sandbox:discard --workspace=<id> --sign     # throw the trial away
php bin/coa sandbox:undo --workspace=<id> --sign        # reverse a promotion from the pre-image it kept
```

A promotion is checked before anything is written. If a file the trial changed has also changed in the
house since, the trial is refused (*the target moved since the trial*): scaffold again. And the house is
booted with the change beside the live one; if it does not boot, the promotion is refused and the live
files are never touched.

A finished run can leave a trial with no changes behind. `sandbox:list` shows it, and discarding it is
safe. The house keeps the 24 newest open trials and drops older ones.

### What the house may adopt on its own

Nothing, until you say so. `sandbox:admitted` lists the operations whose trial the house can adopt by
itself once it verifies — registering a plugin, seeding an entity's rows, and `make` of a page, a
plugin, an operation or an entity — and prints the one signed command that admits them all:

```bash
php bin/coa sandbox:admitted
php bin/coa sandbox:admit --everything=<digest> --sign
php bin/coa sandbox:withdraw --operation=make --what=entity --sign    # take one back
```

After that, a run in `auto` that scaffolds one of those adopts its own trial: asked for an entity, it
ends with the entity in the house. In `ask` and `acknowledge` nothing changes, and the trial stays open
for you. Anything that is not on the list, a controller for example, stays in its trial in every mode.

## 9. Seats and grants: the agent with its own key

So far the agent has acted with *your* signature. To let it work under its own key, with its own limits,
give it a **seat**. That needs identity:

```bash
php bin/coa capabilities:enable milpa/auth --sign
php bin/coa identity:seat --label=resident --sign
```

(Enabling identity also writes a passkey section to `config/app.php` and mentions a passkey invitation.
That is the browser's way in, for the admin panel. The terminal path below does not need it.)

The resident needs a key of its own, kept apart from yours. With GPG that is a second keyring: point
`GNUPGHOME` at another directory and run step 3's `gpg --quick-gen-key` there. The `identity:seat` output
prints `php bin/coa identity:accept --invite=... --sign`. Run it once, within the hour, with
`GNUPGHOME` set to the resident's keyring. From then on, signing `agent` runs with the resident's key
makes them the resident's. You keep answering its pauses (`agent:answer`) and granting with yours.

`php bin/coa identity:seats` shows what the seat holds. It starts with a fixed set of **scopes** —
`agent:run`, `agent:read`, `plugins:read`, `plugins:write`, `plugins.config:write` and
`milpa:component:data-table:*` — and with nothing under `admitted`. With those it runs sessions and
pauses in `ask` like yours do. Two things it cannot do until you decide: write into a particular
plugin, and call an operation built in this house.

When its session meets either one, the call is **refused** and recorded, and the run ends *waiting for
a grant* (one exception, for an operation the session itself built, is below). A refusal is not a
question, so `agent:answer` does not answer it. You answer with a **grant** that cites the refused call:

```bash
php bin/coa agent:timeline --session=<id>               # the refused call shows `at: <n>`
php bin/coa identity:grant --session=<id> --seq=<n> --sign
```

The house works out the scope from the call; you never type one. Then run
`php bin/coa agent "continue" --session=<id> --sign` with the resident's key, and the house opens that
run by making the refused call itself.

The refusal's own text points to the panel (Agent → Decisions). From the terminal, `identity:grant`
does it. A grant over a plugin that already exists opens write access to all of it, so the house
refuses it until you name the plugin on purpose with `--existing=<Plugin>`.

An operation built in this house (one you scaffolded with `make operation`, say) is not the seat's to
call until you **admit** it, whatever scopes the seat holds. `identity:seats` lists it under
`unadmitted`, with what it does and a `contract` digest. You admit by repeating that digest, so you
admit what you read:

```bash
php bin/coa identity:admit --seat=<fingerprint> --admits=<digest> --sign        # before any refusal
php bin/coa identity:grant --session=<id> --seq=<n> --admits=<digest> --sign    # answering one
```

Admitting an operation closes the write grant a seat held over its plugin: a seat builds a plugin or
uses its operations, not both at once. And an admitted operation that writes still pauses in `ask`,
like any call that changes the house.

One case goes differently. When the session that calls the operation is the one that built it — the
seat holds the write grant over that plugin, and this same session scaffolded the operation and adopted
it — the call is still refused and recorded, but the run does not stop there. The house runs the call
once in a **rehearsal** (in its own words, *a copy of the house without its state, discarded after the
call*) and hands the agent what the call answered there, marked `ran_in_trial: true, applied: false`.
The agent goes on. Nothing changes in the house and nothing is admitted: `identity:seats` still lists
the operation under `unadmitted`, the refusal still shows in `agent:timeline`, and the run's closing
lines say `rehearsed`, with `applied: no`. A new session of the same seat that makes the same call ends
waiting for a grant, as above.

Do not confuse this with `agent --grant=...`. That one is a *launch grant*: consent you give when you
start a run, for an operation it would otherwise pause on. `agent "..." --grant=sandbox:promote --sign`
adopts its trial without asking. `agent:answer` consents to one question already asked, and `--grant`
consents before the question comes. Neither one stands in for a signature or a scope.

## 10. Decisions and evidence

`.milpa/decisions/` holds the choices that shaped the house: who, what, and why. The founding writes
the first one, and you write the rest.

`.milpa/evidence/` holds what you **measured**. Nothing writes there for you, on purpose: a record the
house generates without a control proves nothing. One file per measured change, with four parts:

- **Frame**: what you changed and the question it answers.
- **Check**: the exact command and what it printed, for example `php bin/coa test`, a `curl` to a
  route, or `php bin/coa routes:list`.
- **Positive control**: the same check against a state where it *must* fail, showing that the check can
  see the defect. `test:baseline` before and `test:delta` after give you both halves for the suite:
  the delta lists the failures that are new, resolved and unchanged.
- **Verdict**: what the check and the control together let you say, and what they do not.

`php bin/coa test` will report one failure you have not caused: `make` in step 6 left
`tests/Plugins/Notes/NoteTest.php`, a test that fails on purpose until you write in it what `Note` must
do.

`.milpa/evidence/README.md` carries the same template. Write one now for what you adopted in steps 6
to 9: a `curl` to each route as the check, and a route that does not exist (a 404) as its control.

## The house is steady when

- `php bin/coa foundation` says *founded*.
- `php bin/coa house:start` lists the capabilities you need under *installed*.
- `php bin/coa agent:model` says `reached: yes` and `serves_declared: yes`, if you use the agent.
- `php bin/coa sandbox:list` shows no trial you forgot about (discard empty leftovers).
- `vendor/bin/phpunit` passes. It asserts what this house declares, so it passes after you enable
  capabilities too. "OK, but there were issues!" with a few skipped tests is a pass: each skip names a
  check that does not apply to the capabilities this house has.
- Every change you adopted has a decision or a piece of evidence that says why it is there.
