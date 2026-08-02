<?php

/**
 * This file is part of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace App\Support;

use Psr\Log\AbstractLogger;

/**
 * Un logger que escribe a STDERR, para que «lo dice» sea cierto.
 *
 * Existe por una contradicción medida: `SecondOpinionGate` promete en su docblock que un verificador
 * caído «no calla» — y su warning iba a un `NullLogger`, así que callaba. Lo encontró una revisión
 * adversaria del pre-registro de Q-P19-J, y no es cosmético: en el laboratorio, un juez que no pudo
 * opinar deja pasar la llamada, y sin esta línea esa corrida es indistinguible de una donde el juez
 * aprobó — que es exactamente la confusión que el falsificador 1 de Q-P19-D nombra.
 *
 * STDERR y no el stream de la sesión, a propósito: «no pude opinar» es un hecho del PROCESO, no de la
 * sesión — depende de la red de esta corrida, no del trabajo. El arnés (`lab-run.sh`) captura stderr
 * por corrida en su `.err`, y en una terminal el operador lo ve donde se ven las advertencias.
 */
final class StderrLogger extends AbstractLogger
{
    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $ctx = $context === [] ? '' : ' ' . (json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '');

        fwrite(\STDERR, '[' . $level . '] ' . $message . $ctx . "\n");
    }
}
