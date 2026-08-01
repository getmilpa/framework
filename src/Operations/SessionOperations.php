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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\Auth\AuthContext;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * El otro lado de la pausa: ver las sesiones, leer una, y CONTESTARLE (P16.4/P16.5).
 *
 * ── POR QUÉ SON ÁTOMOS Y NO UN PROMPT INTERACTIVO ───────────────────────────────────────────────
 *
 * Porque una sesión se pausa en un proceso y se contesta en otro — al día siguiente, desde otra
 * máquina, o desde el TUI en vez de la terminal. Un `readline()` dentro del bucle habría atado la
 * respuesta al proceso que hizo la pregunta, que es justo lo que P16.1 acaba de desatar. Como átomos,
 * las cuatro superficies pueden contestar y ninguna tiene que saber cómo funcionan las otras.
 *
 * ── `answer` MUTA, Y NO PIDE FIRMA ──────────────────────────────────────────────────────────────
 *
 * Apenda eventos —incluido un permiso, cuando la respuesta es «sí»— así que decir que no muta sería
 * mentir. No pide firma porque ES la compuerta: exigir un consentimiento para dar un consentimiento
 * es una escalera sin piso. Lo que la mantiene honesta es que sólo puede otorgar lo que la sesión ya
 * había preguntado — no acepta un permiso para algo que nadie pidió.
 */
final class SessionOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'agent:sessions',
                description: 'Las sesiones de agente de esta app y en qué van',
                handler: fn (array $input): array => $this->listar(),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
            ),
            new Operation(
                name: 'agent:show',
                description: 'Todo lo que se sabe de una sesión: objetivo, plan, pendientes, permisos y en qué quedó',
                handler: fn (array $input): array => $this->mostrar($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                    ],
                    'required' => ['session'],
                ],
                mutating: false,
            ),
            new Operation(
                name: 'agent:mode',
                description: 'Cambia hasta dónde puede llegar una sesión sin preguntar',
                handler: fn (array $input): array => $this->cambiarModo($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['ask', 'acknowledge', 'auto'],
                            'description' => 'ask pregunta antes de mutar · acknowledge avisa y sigue · auto sigue sola',
                        ],
                    ],
                    'required' => ['session', 'mode'],
                ],
                // Muta la sesión, y sube o baja cuánta autonomía tiene un proceso automático. No pide
                // firma porque lo que hay del otro lado tampoco la evita: subir a `auto` no
                // pre-aprueba nada que exija firma, así que esto no puede usarse para rodear esa
                // compuerta — sólo para dejar de preguntar por lo reversible.
                mutating: true,
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'agent:answer',
                description: 'Contesta la pregunta que dejó pausada una sesión, y la reanuda',
                handler: fn (array $input): array => $this->contestar($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'Identificador de la sesión'],
                        'answer' => ['type' => 'string', 'description' => 'Tu respuesta — «sí» autoriza la operación en esta sesión'],
                    ],
                    'required' => ['session', 'answer'],
                ],
                mutating: true,
                // Fuera de HTTP por lo mismo que `agent`: contestar una pregunta de permiso desde una
                // petición web es autorizar con las credenciales del servidor, y esta plantilla no
                // toma esa decisión por nadie.
                surfaces: ['cli', 'tui', 'mcp'],
            ),
        ];
    }

    /**
     * Quién está contestando, con su origen y su nivel de confianza.
     *
     * ── LAS DOS FUENTES NO VALEN LO MISMO, Y POR ESO NO SE MEZCLAN ──────────────────────────────
     *
     * Si hay un {@see AuthContext} autenticado, hay una credencial detrás y el principal va marcado
     * **verificado**. Si no —el caso normal por terminal— se toma el usuario del sistema operativo y
     * va marcado **sin verificar**, porque cualquiera con esa terminal puede ser ese usuario.
     *
     * Guardar el segundo como si fuera el primero fabricaría una cadena de custodia inexistente:
     * «lo autorizó rod» cuando lo que se sabe es «lo autorizó quien tenía la máquina de rod».
     *
     * Ver Q-P19-B: comparando con `milpa/workflow`
     * salió que este lado no guardaba principal ninguno, y que aquél además prohíbe que el que pide
     * sea el que aprueba.
     */
    private function quienContesta(): Principal
    {
        $contexto = $this->container->has(AuthContext::class) ? $this->container->get(AuthContext::class) : null;
        if ($contexto instanceof AuthContext && $contexto->actor !== null) {
            return new Principal('actor:' . $contexto->actor->id, verified: true);
        }

        $usuario = getenv('USER');
        if (!\is_string($usuario) || $usuario === '') {
            $usuario = getenv('USERNAME');
        }

        return Principal::fromTerminal(\is_string($usuario) ? $usuario : null, gethostname() ?: null);
    }

    /**
     * @return array{ok: bool, total?: int, sessions?: list<array<string, mixed>>, error?: string}
     */
    private function listar(): array
    {
        $almacen = $this->sessions();
        if ($almacen === null) {
            return ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $filas = [];
        foreach ($almacen->ids() as $id) {
            $sesion = $almacen->load($id);
            if ($sesion === null) {
                continue;
            }

            $filas[] = [
                'session' => $id,
                'goal' => $sesion->goal,
                'mode' => $sesion->mode->value,
                'turns' => \count($sesion->turns),
                // Qué le pasa AHORA, que es lo que alguien busca al listar: una sesión esperando una
                // respuesta se ve igual que una viva si sólo se muestra su objetivo.
                'state' => $sesion->endedBecause !== null
                    ? 'terminada'
                    : ($sesion->question !== null ? 'esperando respuesta' : 'viva'),
                'pending' => \count($sesion->pendingTodos()),
            ];
        }

        return ['ok' => true, 'total' => \count($filas), 'sessions' => $filas];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function mostrar(array $input): array
    {
        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $sesion = $almacen->load($id);
        if ($sesion === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        return [
            'ok' => true,
            'session' => $sesion->id,
            'goal' => $sesion->goal,
            'mode' => $sesion->mode->value,
            'plan' => $sesion->plan,
            'todos' => array_map(static fn ($t): array => $t->toArray(), $sesion->todos),
            'permissions' => $sesion->permissions,
            'turns' => \count($sesion->turns),
            'compactedThrough' => $sesion->compactedThrough,
            'question' => $sesion->question?->toArray(),
            // LO QUE SE DECIDIÓ, y quién. Sin esta línea el principal se guardaba y no lo veía nadie
            // —el patrón que este repositorio lleva un mes cazando: una capacidad a la que le falta
            // la línea que la enchufa—. `agent:show` es donde alguien va a preguntar «¿quién
            // autorizó esto?», así que es donde tiene que estar la respuesta.
            'decisions' => array_map(
                static fn (array $d): array => [
                    'question' => $d['question'],
                    'answer' => $d['answer'],
                    // `by` va con su `verified` pegado y NO se aplana a una cadena: un id sin su
                    // nivel de confianza se lee como una identidad probada, y la mitad de las veces
                    // no lo es.
                    'by' => ($d['by'] ?? null) instanceof Principal ? $d['by']->toArray() : null,
                    // Presente sólo cuando nadie decidió: la ventana se cerró sola.
                    'expired' => $d['expired'] ?? null,
                ],
                $sesion->decisions,
            ),
            'endedBecause' => $sesion->endedBecause,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function contestar(array $input): array
    {
        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $respuesta = \is_string($input['answer'] ?? null) ? trim($input['answer']) : '';
        if ($respuesta === '') {
            return ['ok' => false, 'error' => 'falta `answer`: qué le contestas'];
        }

        $sesion = $almacen->load($id);
        if ($sesion === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        if ($sesion->question === null) {
            // Contestar algo que nadie preguntó no es inocuo: si sólo se apendara, quedaría un turno
            // suelto que el modelo leería como contexto en la siguiente vuelta.
            return [
                'ok' => false,
                'error' => "la sesión «{$id}» no está esperando ninguna respuesta",
                'hint' => 'córrela con `coa agent "…" --session=' . $id . '`',
            ];
        }

        $pregunta = $sesion->question;
        $almacen->answer($id, $pregunta->id, $respuesta, $this->quienContesta());

        // Un «sí» a una pregunta de PERMISO otorga esa operación para el resto de la sesión. El
        // permiso se deriva del id de la pregunta y no de lo que alguien teclee: así no se puede
        // autorizar algo que la sesión nunca pidió.
        $otorgado = null;
        if (str_starts_with($pregunta->id, 'perm:') && $this->esAfirmativa($respuesta)) {
            $otorgado = substr($pregunta->id, 5);
            $almacen->grant($id, $otorgado);
        }

        return [
            'ok' => true,
            'session' => $id,
            'answered' => $pregunta->id,
            'granted' => $otorgado,
            'hint' => 'retoma con `coa agent "sigue" --session=' . $id . '`',
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function cambiarModo(array $input): array
    {
        [$almacen, $id, $error] = $this->target($input);
        if ($error !== null || $almacen === null) {
            return $error ?? ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones'];
        }

        $modo = AutonomyMode::tryFrom(\is_string($input['mode'] ?? null) ? $input['mode'] : '');
        if ($modo === null) {
            return [
                'ok' => false,
                'error' => 'modo desconocido — válidos: '
                    . implode(', ', array_map(static fn (AutonomyMode $m): string => $m->value, AutonomyMode::cases())),
            ];
        }

        $sesion = $almacen->load($id);
        if ($sesion === null) {
            return ['ok' => false, 'error' => "no existe la sesión «{$id}»"];
        }

        $antes = $sesion->mode;
        $almacen->setMode($id, $modo);

        return [
            'ok' => true,
            'session' => $id,
            'from' => $antes->value,
            'mode' => $modo->value,
            // Lo que NINGÚN modo cambia se dice al cambiar de modo, que es cuando alguien podría creer
            // lo contrario. Subir a `auto` es dejar de preguntar por lo reversible, no firmar en blanco.
            'note' => 'lo que exige firma se sigue deteniendo en cualquier modo',
        ];
    }

    /**
     * Si una respuesta autoriza.
     *
     * Lista corta y explícita: cualquier cosa que no esté aquí NO autoriza. Un «tal vez» o un
     * «adelante pero con cuidado» tienen que caer del lado de la negativa, porque interpretar de más
     * en la pieza que otorga permisos es exactamente donde no se quiere ser listo.
     */
    private function esAfirmativa(string $respuesta): bool
    {
        return \in_array(mb_strtolower($respuesta), ['sí', 'si', 'yes', 'y', 's'], true);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: SessionStore|null, 1: string, 2: array<string, mixed>|null} el almacén es
     *                                                                              `null` sólo cuando
     *                                                                              hay error
     */
    private function target(array $input): array
    {
        $almacen = $this->sessions();
        if ($almacen === null) {
            return [null, '', ['ok' => false, 'error' => 'esta app no tiene dónde guardar sesiones']];
        }

        $id = \is_string($input['session'] ?? null) ? trim($input['session']) : '';
        if ($id === '') {
            return [null, '', ['ok' => false, 'error' => 'falta `session`: cuál']];
        }

        return [$almacen, $id, null];
    }

    /**
     * El mismo almacén que usa {@see AgentOperations}, por la misma vía.
     *
     * Se resuelve del contenedor y no se construye aquí: dos lugares que decidan dónde viven las
     * sesiones son dos lugares donde pueden dejar de coincidir, y el día que dejaran de hacerlo
     * `agent:answer` contestaría en una sesión que `agent` no está leyendo.
     */
    private function sessions(): ?SessionStore
    {
        if (!class_exists(SessionStore::class)) {
            return null;
        }

        return (new AgentOperations($this->container))->sessionStore();
    }
}
