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

namespace App\Agent;

use Milpa\Agent\PolicyDecision;
use Milpa\Agent\Session;
use Milpa\Agent\SessionPolicy;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;

/**
 * Une la política de la sesión con el bucle del agente (P16.4/P16.5).
 *
 * ── POR QUÉ ESTE ADAPTADOR VIVE EN LA APP Y NO EN UN PAQUETE ────────────────────────────────────
 *
 * Porque es lo único que conoce a los dos lados. `milpa/agent` decide —y no sabe qué es un modelo ni
 * una herramienta— y `milpa/ai-gateway` corre el bucle —y no sabe qué es una sesión ni un permiso—.
 * Ponerlo en cualquiera de los dos los ataría, y lo que hoy son dos paquetes que se pueden usar por
 * separado pasarían a ser uno con dos nombres. La app es donde las decisiones concretas se toman.
 *
 * ── NEGAR ES APENDAR ────────────────────────────────────────────────────────────────────────────
 *
 * Cuando la política dice que hay que preguntar, esto NO devuelve nomás una negativa: apenda la
 * pregunta en la sesión. Esa es la diferencia entre un agente que se detiene y uno que se detiene y
 * te espera — la sesión queda no-corrible hasta que alguien conteste, y la pregunta sobrevive al
 * proceso igual que todo lo demás. Una negativa que sólo existiera en el texto de la respuesta se
 * perdería en cuanto cerraras la terminal.
 */
final class SessionToolGate implements ToolCallGate, ToolCallRecorder
{
    /** Cuánto de lo que contestó una herramienta se guarda en la bitácora de la sesión. */
    private const MAX_RESULT = 600;

    /**
     * @param list<Operation> $operations las de esta app — de ahí salen `mutating` y
     *                                    `requiresConfirmation`, que son declaraciones de la operación
     *                                    y no algo que esta compuerta pueda opinar
     */
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly Session $session,
        private readonly array $operations,
        private readonly SessionPolicy $policy = new SessionPolicy(),
        // Cuánto tiempo tiene un humano para contestar antes de que la sesión se declare muerta.
        // `null` es sin plazo, que es lo que había; la decide el host en `agent.permissionWindow`
        // porque es una política de producto y no una constante de este archivo.
        private readonly ?\DateInterval $permissionWindow = null,
    ) {
    }

    /**
     * El motivo por el que esta llamada no procede, o `null` si procede.
     *
     * @param array<string, mixed> $arguments
     */
    public function refuse(string $tool, array $arguments): ?string
    {
        // La contabilidad de la propia sesión —escribir el plan, mover un pendiente— NO se gatea.
        // Apenda, y lo declara, pero su efecto es exclusivamente sobre la bitácora de esta sesión: no
        // toca un archivo, una base ni un plugin. Pedir permiso para anotar un plan es pedir permiso
        // para ser legible, y una compuerta que se pide también para eso se aprueba sin leer — que es
        // como se pierde la que sí importaba.
        if ($this->esContabilidad($tool)) {
            return null;
        }

        $operacion = $this->operationFor($tool);
        if ($operacion === null) {
            // Una herramienta que no viene de una operación de esta app no la puede juzgar esta
            // política: no sabe si muta. Se deja pasar porque negar lo que no se entiende volvería
            // inútil cualquier registro externo — y porque el registro de herramientas tiene su propia
            // compuerta de scopes, que sigue puesta.
            return null;
        }

        $decision = $this->policy->decide(
            $this->session,
            $operacion->name,
            $operacion->mutating,
            $operacion->requiresConfirmation,
        );

        return match ($decision) {
            PolicyDecision::Allow => null,
            PolicyDecision::AskPermission => $this->pause(
                $this->policy->permissionQuestion($operacion->name, $arguments, $this->vence()),
            ),
            PolicyDecision::RequireSignature => $this->pause(
                $this->policy->signatureQuestion($operacion->name, $arguments),
            ),
        };
    }

    /** Cuándo vence la pregunta que se está por hacer, o `null` si el host no puso plazo. */
    private function vence(): ?\DateTimeImmutable
    {
        return $this->permissionWindow === null
            ? null
            : (new \DateTimeImmutable())->add($this->permissionWindow);
    }

    /**
     * Apunta en la sesión que esta herramienta corrió y qué contestó.
     *
     * La compuerta ve la intención y esto ve el desenlace; hacen falta las dos. Sin el desenlace,
     * retomar una sesión sería retomarla sabiendo qué se iba a intentar y no si funcionó — y el agente
     * repetiría el trabajo que su yo anterior ya hizo, o el que ya falló.
     *
     * El resultado se recorta: un `make` que devuelve tres rutas absolutas no aporta más al retomar
     * que su primera línea, y sí ocupa la ventana que la compactación acaba de liberar.
     *
     * @param array<string, mixed> $arguments
     */
    public function recorded(string $tool, array $arguments, string $result, bool $ok): void
    {
        // La contabilidad NO se apunta como llamada. Su efecto ya está en el stream con su propio
        // evento —`plan_set`, `todo_changed`— y el reductor lo devuelve como estado, que es como llega
        // a la ventana. Registrarla además como turno diría lo mismo dos veces y le cobraría a la
        // ventana el doble justo en las sesiones largas, donde el espacio es lo que escasea.
        if ($this->esContabilidad($tool)) {
            return;
        }

        $this->sessions->recordToolCall(
            $this->session->id,
            $tool,
            $arguments,
            mb_substr($result, 0, self::MAX_RESULT),
            $ok,
        );
    }

    /** Apenda la pregunta —la sesión queda esperando— y devuelve lo que se le dice a quien preguntó. */
    private function pause(\Milpa\Agent\PendingQuestion $pregunta): string
    {
        $this->sessions->ask($this->session->id, $pregunta);

        $linea = $pregunta->question;
        if ($pregunta->why !== null) {
            $linea .= "\n  con: " . $pregunta->why;
        }
        if ($pregunta->options !== []) {
            $linea .= "\n  contesta con: coa agent:answer {$this->session->id} <"
                . implode('|', $pregunta->options) . '>';
        }

        return $linea;
    }

    /** Si esta herramienta es la contabilidad de la propia sesión — plan y pendientes. */
    private function esContabilidad(string $tool): bool
    {
        foreach (SessionBookkeeping::names() as $nombre) {
            if (McpProjector::toolName($nombre) === $tool) {
                return true;
            }
        }

        return false;
    }

    /**
     * La operación detrás de un nombre de herramienta.
     *
     * La traducción la hace {@see McpProjector::toolName()} y no una regla escrita aquí: si un día
     * cambia cómo se nombran las herramientas, esta compuerta dejaría de encontrar sus operaciones y
     * las dejaría pasar TODAS en silencio. Preguntarle al proyector es lo que impide que una
     * convención duplicada se desincronice sin ruido.
     */
    private function operationFor(string $tool): ?Operation
    {
        foreach ($this->operations as $operacion) {
            if (McpProjector::toolName($operacion->name) === $tool) {
                return $operacion;
            }
        }

        return null;
    }
}
