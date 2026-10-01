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
php bin/coa make controller Notes NotesController --route=/notes --methods=index --sign
php bin/coa plugins:register Notes --sign
php bin/coa routes:list
php bin/coa serve
```

`serve` answers at `http://localhost:8000/` until you stop it with Ctrl-C. `make` verifies what it
writes before it lands. `plugins:register` adds the class to
`config/plugins.php`, which is a list rather than a scan, so what boots is always a versioned decision.

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

`config:set` writes to `.milpa/agent.json`, which is committed. `provider:declare` writes to
`.milpa/secrets.json`, which is git-ignored and never echoed back.

Asking the agent is a lasting change too, so it is signed. Each run belongs to a **session**:

```bash
php bin/coa agent "Scaffold TagsController in the Notes plugin at /tags, method index" --sign
```

A session has a **mode**. In `ask` (the default) it pauses before a change that needs your consent, in
`acknowledge` it says so and carries on, and in `auto` it carries on alone. No mode ever skips a
signature. When it pauses, the output names the session and the exact command to answer:

```bash
php bin/coa agent:answer --session=<id> --answer=yes --sign
php bin/coa agent "continue" --session=<id> --sign
```

Answering does not resume the run by itself. The `continue` call does. A run can pause more than
once, for example before running the tests or before adopting its work (that is a *promotion* of a
*trial*, step 8). Answer each pause the same way. A run that ends with `steps_exhausted` used its step
ceiling (12 unless you pass `--steps=<n>`), and another `continue` picks it up. When the session's work
is already verified, `continue` answers without calling the model.

## 8. Trials: the agent works on a copy

The agent's changes do not land in the house directly. Each one runs in a **trial**, a disposable copy
of the house (`sandbox` is the operations' prefix). The house adopts a trial only through
**promotion**. Usually the agent asks for it: in `ask` mode that is the pause you answered in step 7,
and a longer task pauses once per promotion, registration or discard, each answered the same way. You
can also act on trials yourself. These are lasting changes too, so they are signed:

```bash
php bin/coa sandbox:list                                # open trials and what each one changed
php bin/coa sandbox:promote --workspace=<id> --sign     # the only door in
php bin/coa sandbox:discard --workspace=<id> --sign     # throw the trial away
php bin/coa sandbox:undo --workspace=<id> --sign        # reverse a promotion from the pre-image it kept
```

A promotion the house cannot boot with is refused and rolled back, so nothing broken gets adopted. A
finished run can leave a trial with no changes behind. `sandbox:list` shows it, and discarding it is
safe.

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

The seat starts with a fixed set of scopes: `agent:run`, `agent:read`, `plugins:read`, `plugins:write`
and `plugins.config:write`. Those are the most it can do. When it needs more, for example `plugins.Blog:write` to create a new plugin
named Blog, the call is **refused** and recorded in its session. A refusal is never a request. You
answer it with a **grant** of exactly that scope, citing the refused call:

```bash
php bin/coa agent:timeline --session=<id>               # the refused call shows `at: <n>`
php bin/coa identity:grant --session=<id> --seq=<n> --sign
```

The refusal's own text says the grant can be made "in the panel". That is the admin panel
(`milpa/admin`). From the terminal, `identity:grant` is the same act. A grant over a plugin that already exists also needs `--existing=<Plugin>`, because it opens write
access to the whole plugin, and the house wants you to name it on purpose.

Do not confuse this with `agent --grant=...`. That one is a *launch grant*: consent you give in advance
to a session (`--session=<id>` is required) for operations it would otherwise pause on. `agent:answer`
consents to one question already asked, and `--grant` consents before the question comes. Neither
one stands in for a signature or a scope.

## 10. Decisions and evidence

`.milpa/decisions/` holds the choices that shaped the house: who, what, and why. The founding writes
the first one, and you write the rest.

`.milpa/evidence/` holds what you **measured**. Nothing writes there for you, on purpose: a record the
house generates without a control proves nothing. One file per measured change, with four parts:

- **Frame**: what you changed and the question it answers.
- **Check**: the exact command and what it printed, for example `php bin/coa test`, a `curl` to a
  route, or `php bin/coa routes:list`.
- **Positive control**: the same check against a state where it *must* fail, showing that the check can
  see the defect. `test:baseline` before and `test:delta` after give you both halves for the suite.
- **Verdict**: what the check and the control together let you say, and what they do not.

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
