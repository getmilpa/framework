<?php

/**
 * This file is part of Milpa Framework — the composer create-project starting point for a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace App\Operations;

use App\Support\Capabilities;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;

/**
 * `capabilities` — qué puede hacer esta app, y qué le falta para hacer lo demás.
 *
 * ── ES UNA OPERACIÓN Y NO UNA PÁGINA DE AYUDA ───────────────────────────────────────────────────
 *
 * Porque tiene que servirle a los dos. Una página de ayuda la lee un humano; una **operación** la ve
 * el humano en `coa capabilities` y la llama el agente como herramienta, desde la misma fuente. Dos
 * listas —una escrita para leer y otra para llamar— divergirían, y la que se quedaría vieja es
 * justamente la que nadie ejecuta.
 *
 * Y para el agente esto es una decisión menos, no una más: en vez de adivinar si esta app tiene el
 * paquete que necesita, pregunta. Es la dirección que este programa lleva midiendo desde Q-P17 —
 * **reducirle decisiones, no aumentarle el contexto.**
 *
 * ── NO ESTÁ GATEADA, Y NO MUTA ──────────────────────────────────────────────────────────────────
 *
 * Leer qué se puede instalar no le da acceso a nada a nadie: los nombres de los paquetes de este
 * framework son públicos y están en Packagist. Y **no instala**: devuelve el `composer require`
 * exacto y ahí se detiene. Que un agente pueda describir cómo crecer una app es útil; que pueda
 * cambiarle las dependencias sin que nadie lo autorice es otra cosa, y eso pertenece a una política.
 */
final readonly class CapabilityOperations implements CommandProvider
{
    // SIN CONSTRUCTOR, y no es casualidad: `Support\Operations::declared()` construye un proveedor
    // con el contenedor en cuanto tiene UN parámetro, así que un parámetro «sólo para pruebas» habría
    // recibido el contenedor en producción. El contrato de proveedor decide la forma de esta clase.

    /**
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'capabilities',
                description: 'Qué puede hacer esta app hoy, y con qué comando crece',
                handler: fn (array $input): array => $this->listar(),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
                // TODAS LAS SUPERFICIES. Si el agente no la puede llamar, el camino se lo enseña el
                // sistema sólo a quien ya sabía dónde mirar.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
        ];
    }

    /**
     * @return array{ok: bool, installed: list<array<string, mixed>>, available: list<array<string, mixed>>, ports: array<string, list<string>>, hint?: string}
     */
    private function listar(): array
    {
        return Capabilities::answer();
    }
}
