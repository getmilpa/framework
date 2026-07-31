<?php

declare(strict_types=1);

/**
 * Qué operaciones de esta app se atienden por HTTP.
 *
 * ── POR QUÉ ES UNA LISTA, Y POR QUÉ ESTÁ VACÍA ──────────────────────────────────────────────────
 *
 * Porque exponer una operación por la red es una decisión, no un default. `coa` y MCP corren en la
 * máquina de quien los invoca; una ruta HTTP la puede llamar cualquiera que alcance el servidor. Que
 * cada operación nueva apareciera sola en la web convertiría instalar un plugin en publicar una API.
 *
 * Es la misma postura que `config/plugins.php`: lo que corre es una decisión versionada, que alguien
 * lee en un diff.
 *
 * ── CÓMO SE PRENDE ──────────────────────────────────────────────────────────────────────────────
 *
 * Nombra los átomos que quieras servir. El nombre es el de la operación —el mismo que `coa` traduce
 * a `:`— y la ruta sale de `$op->path` si lo declara, o se deriva del nombre:
 *
 *     return ['expose' => ['plugins.list']];   →   GET /plugins
 *
 * Un átomo que declara `scopes` o `permission` NO se sirve sin una política de identidad: el arranque
 * se detiene y dice cuál falta. Registra en el contenedor un `Milpa\Console\Http\OperationHttpPolicy`
 * —`milpa/admin` publica el que usa `milpa/auth`— o expón sólo operaciones sin scopes.
 *
 * @return array{expose: list<string>}
 */
return [
    'expose' => [],
];
