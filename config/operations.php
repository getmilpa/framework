<?php

declare(strict_types=1);

/**
 * Las capacidades que esta app adopta de PAQUETES, no de plugins.
 *
 * Un plugin contribuye sus operaciones al arrancar; un paquete no arranca, así que lo que publica se
 * enlista aquí. La diferencia no es de mecanismo sino de ciclo de vida, y por eso son dos listas.
 *
 * `DevToolsOperations` trae `validate` y `make`: validar un plugin y andamiar un controller o una
 * entidad. Es lo mínimo para que la primera hora en una app nueva no consista en escribir a mano lo
 * que el framework ya sabe generar.
 *
 * ── UNA CLASE QUE NO ESTÁ SE SALTA, NO TRUENA ───────────────────────────────────────────────────
 *
 * El despachador comprueba `class_exists()` antes de construir cada proveedor. Importa por una razón
 * concreta y fechada: `DevToolsOperations` nació DESPUÉS de `milpa/devtools 0.8.0`, así que una
 * instalación contra esa versión no la tiene. En vez de reventar al arrancar, esta app lista cuatro
 * operaciones en vez de seis y sigue funcionando; al subir devtools, las gana sin tocar nada.
 *
 * Es la degradación correcta para una lista declarativa: quien la escribió afirmó una intención, y
 * una intención que todavía no se puede cumplir no debería impedir arrancar.
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

    Milpa\DevTools\Operations\DevToolsOperations::class,

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
