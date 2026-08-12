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
    // ESCRIBIR CARGA UN TECHO PRESTADO: el de lo más pesado que el criterio editado puede permitir,
    // porque quien edita al juez no pesa menos que lo juzgado. Construida desde esta lista no recibe
    // catálogo, así que lo presta de uno vacío — y GOV-05 hace que lo no clasificado cuente como el
    // máximo de cada eje. Pide consentimiento en vez de saltárselo, que es el lado correcto para
    // equivocarse; darle el catálogo real es otra rebanada, y está declarada.
    Milpa\AppRuntime\Operations\ConfigOperations::class,
];
