# Upgrading

## 0.20 → 0.21 — the agent runtime moved to a package

**Your app is not broken.** It boots exactly as it did yesterday and every command still works.
Nothing here is urgent. What it is, is *frozen*: 26 of the 29 files in your `src/` were copied into
your app the day you created it, and copied code never receives an improvement again.

This migration hands those 26 files back to the framework so your app starts receiving them.

### What actually changed

`milpa/framework` is `type: project` — `composer create-project` **copies** its `src/` into your app,
and from that moment it is yours. That is right for the example plugin you are going to delete. It
was wrong for the agent runtime, which improves every week and which nobody edits.

The concrete symptom, measured before the move: an app created one day earlier did not receive the
permission-question buttons, the indicator that pulses on every real event, or `agent:board` — even
after updating everything. And the quiet case was worse. It *did* receive the new `milpa/live-tui`,
which knows how to paint what the system said in a different colour from what the model said, **and
saw no change at all**, because its copied screen never emitted the markers that trigger that
painting. Half the improvement landed, half didn't, and nothing said so.

From 0.21 those files ship as [`milpa/app-runtime`](https://packagist.org/packages/milpa/app-runtime).

### Step 0 — find out whether you edited any of them

Do this first. The migration **deletes** those 26 files, so if you changed one, you need to know
before and not after. Compare your `src/` against a pristine copy of the version your app was born
from:

```bash
composer create-project milpa/framework:0.20.0 /tmp/pristine --no-interaction
diff -rq /tmp/pristine/src src | grep -v Plugins
```

Silence means you edited nothing and the migration is mechanical. Any file it names is yours: keep
your change somewhere before continuing, and after the migration re-apply it — either as your own
class registered in `config/operations.php`, or as an issue on
[`getmilpa/app-runtime`](https://github.com/getmilpa/app-runtime) if the change belongs in the
framework.

### Step 1 — install the package

```bash
composer require "milpa/app-runtime:^0.4"
```

### Step 2 — delete what now arrives installed

```bash
rm -rf src/Agent src/Auth src/Console src/Operations src/Support src/Tui
```

`src/Plugins` stays. That is your code.

### Step 3 — point the four remaining references at the package

Eight references across four files. The namespace is the only thing that changes:

```bash
sed -i 's/\bApp\\\(Agent\|Auth\|Console\|Operations\|Support\|Tui\)\\/Milpa\\AppRuntime\\\1\\/g' \
  bin/coa bin/mcp-server.php config/boot.php config/operations.php
```

On macOS, `sed -i ''` instead of `sed -i`. If you prefer to do it by hand, the rule is
`App\X\` → `Milpa\AppRuntime\X\` for those six sub-namespaces, and the files are `bin/coa`,
`bin/mcp-server.php`, `config/boot.php` and `config/operations.php`.

### Step 4 — check

```bash
php bin/coa list
```

You should see exactly the operations you saw before. That is the point: this migration changes
where the code lives, not what your app does.

Verified on a real 0.20.0 app before publishing these notes — 29 operations before, the same 29
after, byte-identical catalogue; `src/` went from 29 files to 3.

### What you get from here on

`composer update` — or `coa update`, if you have `milpa/devtools` — now reaches the agent runtime.
Improvements to the gates, the operations, the CLI and the agent screen land in your app the same way
every other dependency does.

### If something goes wrong

`composer.lock` is your return point, and your VCS holds the deleted files. This migration touches
no data, no configuration values and no database.
