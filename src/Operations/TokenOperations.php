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

use App\Auth\ApiToken;
use App\Auth\TokenVerifier;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Data\RepositoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Los tokens con que alguien se identifica ante esta app por HTTP.
 *
 * Sin una forma de acuñar el primero, la cadena de autenticación es decoración: `milpa/auth` queda
 * instalado y nadie puede presentarse. Estas tres son esa forma — y viven en la terminal, no en la
 * red, porque quien puede crear un token puede crear uno con todos los scopes.
 *
 * ── EL SECRETO SE VE UNA VEZ ────────────────────────────────────────────────────────────────────
 *
 * `token:new` imprime el token y guarda sólo su hash. No hay «volver a mostrarlo»: un almacén que
 * puede devolver el secreto es un almacén cuyo robo entrega todas las sesiones. Si se pierde, se
 * revoca y se acuña otro, que es más barato que un almacén que los conserva.
 */
final readonly class TokenOperations implements CommandProvider
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'token.list',
                description: 'Los tokens de API de esta app: quién es cada uno y qué puede — nunca el secreto',
                handler: fn (array $input): array => $this->list($input),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
                // Sólo la terminal. Listar tokens por HTTP le dice a quien llegue qué actores existen
                // y con qué scopes — un mapa de a quién conviene robarle.
                surfaces: ['cli'],
            ),
            new Operation(
                name: 'token.new',
                description: 'Acuña un token para un actor con los scopes que le nombres; lo imprime UNA vez',
                handler: fn (array $input): array => $this->create($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'actor' => ['type' => 'string', 'description' => 'Quién es: "ci", "agente-de-rod", lo que identifique'],
                        'scopes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Qué puede. `*` los concede todos'],
                    ],
                    'required' => ['actor'],
                ],
                // Muta y NO pide firma: acuñar es reversible —se revoca— y quien corre esto ya está
                // en la terminal de la app, que es el mismo poder. Una compuerta aquí sería trámite.
                mutating: true,
                surfaces: ['cli'],
            ),
            new Operation(
                name: 'token.revoke',
                description: 'Revoca un token por su id; deja de servir en la siguiente petición',
                handler: fn (array $input): array => $this->revoke($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string', 'description' => 'El id que `token:list` muestra']],
                    'required' => ['id'],
                ],
                mutating: true,
                surfaces: ['cli'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, tokens?: list<array{id: string, actor: string, scopes: string, createdAt: string}>, error?: string}
     */
    private function list(array $input): array
    {
        unset($input);

        $repo = $this->tokens();
        if ($repo === null) {
            return ['ok' => false, 'error' => 'esta app no cableó un almacén de tokens'];
        }

        $tokens = [];
        foreach ($repo->all() as $token) {
            $tokens[] = [
                'id' => (string) $token->id(),
                'actor' => $token->actor(),
                'scopes' => $token->scopes() === [] ? '—' : implode(', ', $token->scopes()),
                'createdAt' => $token->createdAt(),
            ];
        }

        return ['ok' => true, 'tokens' => $tokens];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, token?: string, id?: string, actor?: string, scopes?: list<string>, warning?: string, error?: string}
     */
    private function create(array $input): array
    {
        $actor = \is_string($input['actor'] ?? null) ? trim($input['actor']) : '';
        if ($actor === '') {
            return ['ok' => false, 'error' => 'falta `actor`: quién va a usar este token'];
        }

        $repo = $this->tokens();
        if ($repo === null) {
            return ['ok' => false, 'error' => 'esta app no cableó un almacén de tokens'];
        }

        /** @var list<string> $scopes */
        $scopes = [];
        foreach ((array) ($input['scopes'] ?? []) as $scope) {
            if (\is_string($scope) && trim($scope) !== '') {
                $scopes[] = trim($scope);
            }
        }

        // 32 bytes de aleatoriedad criptográfica. `random_bytes` lanza si el sistema no puede darla,
        // que es lo correcto: un token adivinable es peor que no tener token.
        $secreto = bin2hex(random_bytes(32));
        $id = $repo->save(new ApiToken(
            hash: TokenVerifier::hash($secreto),
            actor: $actor,
            scopes: $scopes,
            createdAt: (new \DateTimeImmutable())->format('c'),
        ));

        return [
            'ok' => true,
            'token' => $secreto,
            'id' => (string) $id,
            'actor' => $actor,
            'scopes' => $scopes,
            'warning' => 'guárdalo ahora: sólo se guarda su hash y no se puede volver a mostrar',
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, id?: string, error?: string}
     */
    private function revoke(array $input): array
    {
        $id = $input['id'] ?? null;
        if (!\is_string($id) && !\is_int($id)) {
            return ['ok' => false, 'error' => 'falta `id`: cuál revocar'];
        }

        $repo = $this->tokens();
        if ($repo === null) {
            return ['ok' => false, 'error' => 'esta app no cableó un almacén de tokens'];
        }

        if ($repo->find($id) === null) {
            return ['ok' => false, 'error' => "no hay ningún token con id «{$id}»"];
        }

        $repo->delete($id);

        return ['ok' => true, 'id' => (string) $id];
    }

    /**
     * El almacén de tokens que `config/boot.php` registró, o `null`.
     *
     * @return RepositoryInterface<ApiToken>|null
     */
    private function tokens(): ?RepositoryInterface
    {
        if (!$this->container->has(TokenVerifier::class . '.repository')) {
            return null;
        }

        $repo = $this->container->get(TokenVerifier::class . '.repository');

        /** @var RepositoryInterface<ApiToken>|null */
        return $repo instanceof RepositoryInterface ? $repo : null;
    }
}
