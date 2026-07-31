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

namespace App\Auth;

use Milpa\Data\EntityInterface;

/**
 * Un token de API: quién es, qué puede, y el hash de lo que hay que presentar.
 *
 * `@final` y no `final` a secas: `EntityInterface::fromArray()` devuelve `static`, así que una
 * subclase con otro constructor rompería el contrato. Marcarlo en el docblock deja el `new static()`
 * seguro sin cerrarle la puerta a quien quiera extenderlo a propósito.
 *
 * @final
 *
 * ── EL SECRETO NO SE GUARDA ─────────────────────────────────────────────────────────────────────
 *
 * Lo que persiste es el HASH del token, nunca el token. Se muestra una sola vez, al acuñarlo, y
 * después no hay forma de recuperarlo — ni para quien lo creó ni para quien se robe el archivo. Un
 * almacén que puede devolver el secreto es un almacén cuyo robo entrega todas las sesiones.
 */
class ApiToken implements EntityInterface
{
    /**
     * @param list<string> $scopes lo que este token autoriza, y nada más
     */
    public function __construct(
        private readonly string $hash,
        private readonly string $actor,
        private readonly array $scopes,
        private readonly string $createdAt,
        private int|string|null $id = null,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'actor' => $this->actor,
            'scopes' => $this->scopes,
            'createdAt' => $this->createdAt,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): static
    {
        /** @var list<string> $scopes */
        $scopes = \is_array($row['scopes'] ?? null) ? array_values($row['scopes']) : [];

        return new static(
            hash: (string) ($row['hash'] ?? ''),
            actor: (string) ($row['actor'] ?? ''),
            scopes: $scopes,
            createdAt: (string) ($row['createdAt'] ?? ''),
            id: $row['id'] ?? null,
        );
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function actor(): string
    {
        return $this->actor;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }
}
