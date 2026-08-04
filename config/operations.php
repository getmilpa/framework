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
];
