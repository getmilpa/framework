<?php

declare(strict_types=1);

/**
 * The capabilities this app adopts from PACKAGES, not from plugins.
 *
 * A plugin contributes its operations when it boots; a package does not boot, so what it publishes is
 * listed here. The difference is one of lifecycle, not mechanism, and that is why there are two lists.
 *
 * ── ONLY WHAT THIS APP SHIPS IS LISTED ──────────────────────────────────────────────────────────
 *
 * A capability you switch on declares its own provider here: `coa capabilities:enable milpa/devtools`
 * writes `DevToolsOperations` (`validate`, `make`, `doctor`) into this list, because the package names it
 * in its manifest. Pre-listing a class this app does not ship left a fresh app's static analysis red on
 * day one (greenhouse evidence/0565). If you `composer require` a capability by hand, add its provider
 * here by hand — or run `capabilities:enable`, which does both.
 *
 * ── A CLASS THAT IS NOT THERE IS SKIPPED, NEVER FATAL ───────────────────────────────────────────
 *
 * The dispatcher checks `class_exists()` before building each provider: an app-runtime older than a
 * provider listed here boots with fewer operations instead of not booting. It is the right degradation
 * for a declarative list — whoever wrote it stated an intention, and an intention that cannot be met yet
 * should not stop the app from starting.
 *
 * @return list<class-string<\Milpa\Command\CommandProvider>>
 */
return [
    // EL CATÁLOGO VA PRIMERO, y no está gateado por nada: es la única operación que una app tiny
    // siempre tiene. Si dependiera de un paquete, la app más pequeña —la que más necesita que le
    // enseñen el camino— sería justo la que no lo tendría (ADR-0040).
    Milpa\AppRuntime\Operations\CapabilityOperations::class,

    // THE CONSTITUTION SITS NEXT TO THE CATALOGUE, ungated for the same reason: the newborn —
    // the app that has not been founded yet — is exactly the one that needs the system to teach
    // it the rite. `foundation` reads (and teaches when there is nothing); `foundation:found`
    // writes the constitution and its acta, once (greenhouse decisions/0004).
    //
    // On an app-runtime older than the group, `class_exists()` above skips it and the app keeps
    // booting — the documented degradation of this list, exercised on purpose.
    Milpa\AppRuntime\Operations\FoundationOperations::class,

    // El agente de esta app: `coa agent "..."`. Ve las mismas operaciones que un cliente MCP, y sin
    // API key configurada dice qué falta en vez de fingir una respuesta.
    Milpa\AppRuntime\Operations\AgentOperations::class,

    // El otro lado de la pausa: `agent:sessions`, `agent:show` y `agent:answer`. Van aparte de
    // `AgentOperations` porque una sesión se pausa en un proceso y se contesta en otro — a veces desde
    // otra superficie, a veces al día siguiente.
    Milpa\AppRuntime\Operations\SessionOperations::class,

    // Los tokens con que alguien se identifica ante esta app por HTTP. Sólo terminal: quien puede
    // acuñar un token puede acuñar uno con todos los scopes.
    Milpa\AppRuntime\Operations\TokenOperations::class,

    // LA CONFIGURACIÓN DEL AGENTE, por el camino gobernado en vez de a mano (greenhouse
    // decisions/0027, evidence/0145). `config` lee lo que esta app corre y dice qué llaves declaran
    // DOS archivos a la vez; `config:set` escribe una llave sin que nadie tenga que saber dónde vive
    // ni cómo se anida — la misma razón por la que `make` andamia un controller.
    //
    // WRITING CARRIES A BORROWED CEILING: the heaviest thing the edited criterion can permit, because
    // whoever edits the judge does not weigh less than what the judge governs. Built from this list
    // it receives no catalogue, so it borrows from an empty one — GOV-05 makes that the maximum of
    // every axis — and asks for consent rather than skipping it, the right side to err on. The real
    // catalogue is handed over in a second pass, once this list is complete.
    //
    // THE ORDER OF THESE LINES DOES NOT DECIDE A CEILING (greenhouse decisions/0224). Two providers
    // here borrow from each other — `config:set` folds the catalogue, `sequence:run` folds its
    // declared steps — and the loan is solved as a fixed point from their floors, the same one in
    // every order. Reordering this file changes how the catalogue is assembled and nothing else.
    Milpa\AppRuntime\Operations\ConfigOperations::class,

    // THE UI CATALOGUE — the agent asks what its own face is made of (greenhouse decisions/0214).
    // This house lets an agent WRITE a screen and, until this operation, gave it no way to ask which
    // components exist or what they are for: it composed blind over a library the house itself knows.
    //
    // It offers nothing at all when `milpa/live` is absent, which is why it sits ungated here: an app
    // without a UI gets an empty provider rather than a missing class, and an app that later installs
    // a component-declaring plugin gains its rows without touching this list.
    Milpa\AppRuntime\Operations\ComponentOperations::class,
    Milpa\AppRuntime\Operations\SequenceOperations::class,
    // A recipe ORIGINATES governed work (greenhouse decisions/0180, 0216): `recipe:apply` reads
    // recipes/<name>.json and drives its foundation, its capabilities and its scaffolds through the
    // same gate every operation passes. The skeleton ships one recipe (recipes/notes.json).
    Milpa\AppRuntime\Operations\RecipeOperations::class,
];
