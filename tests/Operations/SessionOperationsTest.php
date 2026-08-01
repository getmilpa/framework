<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Operations\SessionOperations;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * El otro lado de la pausa: ver, leer y CONTESTAR (P16.4/P16.5).
 *
 * Son átomos y no un prompt interactivo porque una sesión se pausa en un proceso y se contesta en
 * otro — al día siguiente, desde otra máquina, o desde el TUI. Un `readline()` dentro del bucle habría
 * atado la respuesta al proceso que hizo la pregunta, que es justo lo que P16.1 desató.
 */
final class SessionOperationsTest extends TestCase
{
    private InMemoryEventStore $eventos;

    protected function setUp(): void
    {
        $this->eventos = new InMemoryEventStore();
    }

    private function proveedor(): SessionOperations
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(SessionStore::class, new SessionStore($this->eventos));

        return new SessionOperations($contenedor);
    }

    private function almacen(): SessionStore
    {
        return new SessionStore($this->eventos);
    }

    /** @return array<string, mixed> */
    private function llamar(string $nombre, array $entrada = []): array
    {
        foreach ($this->proveedor()->operations() as $operacion) {
            if ($operacion->name === $nombre) {
                $handler = $operacion->handler;
                self::assertIsCallable($handler);

                /** @var array<string, mixed> $r */
                $r = $handler($entrada);

                return $r;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }

    private function operacion(string $nombre): Operation
    {
        foreach ($this->proveedor()->operations() as $operacion) {
            if ($operacion->name === $nombre) {
                return $operacion;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }

    /**
     * La lista dice en QUÉ ESTADO está cada una.
     *
     * Es lo que alguien busca al listar: una sesión esperando una respuesta se ve igual que una viva
     * si sólo se muestra su objetivo, y la que espera es la única sobre la que hay algo que hacer.
     */
    public function testTheListSaysWhichOnesAreWaiting(): void
    {
        $almacen = $this->almacen();
        $almacen->start('viva', 'a');
        $almacen->start('esperando', 'b');
        $almacen->ask('esperando', new PendingQuestion('perm:make', '¿autorizas?', ['sí', 'no']));
        $almacen->start('cerrada', 'c');
        $almacen->end('cerrada', 'listo');

        $r = $this->llamar('agent:sessions');

        self::assertTrue($r['ok']);
        self::assertSame(3, $r['total']);

        $porId = [];
        foreach ($r['sessions'] as $fila) {
            $porId[$fila['session']] = $fila['state'];
        }
        self::assertSame('viva', $porId['viva']);
        self::assertSame('esperando respuesta', $porId['esperando']);
        self::assertSame('terminada', $porId['cerrada']);
    }

    /** `agent:show` trae plan, pendientes y permisos — el estado, no la transcripción. */
    public function testShowBringsTheStateAndNotTheTranscript(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'migrar');
        $almacen->setPlan('s1', '1. entidad  2. rutas');
        $almacen->setTodo('s1', new Todo('t1', 'la entidad'));
        $almacen->grant('s1', 'make');
        $almacen->recordTurn('s1', 'user', 'hola');

        $r = $this->llamar('agent:show', ['session' => 's1']);

        self::assertTrue($r['ok']);
        self::assertSame('migrar', $r['goal']);
        self::assertStringContainsString('entidad', (string) $r['plan']);
        self::assertSame(['make'], $r['permissions']);
        self::assertSame(1, $r['turns'], 'cuántos turnos hay, no cuáles');
    }

    /**
     * Contestar «sí» a una pregunta de permiso OTORGA esa operación, y la sesión vuelve a ser corrible.
     */
    public function testAnsweringYesGrantsTheOperationAndResumesTheSession(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']));

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'sí']);

        self::assertTrue($r['ok']);
        self::assertSame('make', $r['granted']);

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertTrue($sesion->isRunnable());
        self::assertTrue($sesion->allows('make'));
    }

    /**
     * Contestar «no» reanuda la sesión SIN otorgar nada.
     *
     * La sesión sigue: negar un permiso no mata el trabajo, sólo cierra un camino — y el agente puede
     * proponer otro o explicar por qué no hay.
     */
    public function testAnsweringNoResumesWithoutGranting(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']));

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'no']);

        self::assertTrue($r['ok']);
        self::assertNull($r['granted']);

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertTrue($sesion->isRunnable(), 'la sesión sigue: negar cierra un camino, no el trabajo');
        self::assertFalse($sesion->allows('make'));
    }

    /**
     * SÓLO se otorga lo que la sesión preguntó.
     *
     * El permiso sale del id de la pregunta y no de lo que alguien teclee, así que un «sí» a una
     * pregunta que no era de permiso —o a una de firma— no autoriza nada. Es lo que impide que
     * `agent:answer` se convierta en una puerta para otorgar lo que nadie pidió.
     */
    public function testOnlyWhatWasAskedCanBeGranted(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('sign:plugins_remove', 'necesita firma', []));

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'sí']);

        self::assertTrue($r['ok']);
        self::assertNull($r['granted'], 'un «sí» no reemplaza una firma');
        self::assertFalse($almacen->load('s1')?->allows('plugins_remove'));
    }

    /**
     * Cualquier respuesta que no sea un sí explícito NO autoriza.
     *
     * Interpretar de más en la pieza que otorga permisos es exactamente donde no se quiere ser listo:
     * un «tal vez» tiene que caer del lado de la negativa.
     */
    public function testAnythingThatIsNotAnExplicitYesDoesNotGrant(): void
    {
        foreach (['tal vez', 'adelante pero con cuidado', 'ok', 'claro'] as $respuesta) {
            $eventos = new InMemoryEventStore();
            $this->eventos = $eventos;
            $almacen = $this->almacen();
            $almacen->start('s1', 'x');
            $almacen->ask('s1', new PendingQuestion('perm:make', '¿autorizas?', ['sí', 'no']));

            $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => $respuesta]);

            self::assertNull($r['granted'], "«{$respuesta}» no puede autorizar");
        }
    }

    /** Contestar algo que nadie preguntó se niega, en vez de dejar un turno suelto. */
    public function testAnsweringWhenNothingWasAskedIsRefused(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'sí']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no está esperando', (string) $r['error']);
    }

    /** Una sesión que no existe se dice, no se inventa. */
    public function testAnUnknownSessionIsSaid(): void
    {
        $r = $this->llamar('agent:show', ['session' => 'no-existe']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('no existe', (string) $r['error']);
    }

    /**
     * La política de consentimiento de las tres, declarada.
     *
     * `answer` muta —apenda eventos, incluido un permiso— y no pide firma porque ES la compuerta:
     * exigir un consentimiento para dar un consentimiento es una escalera sin piso. Y no se ofrece por
     * HTTP, porque autorizar desde una petición web es autorizar con las credenciales del servidor.
     */
    public function testTheConsentPolicyOfTheThreeIsDeclared(): void
    {
        self::assertFalse($this->operacion('agent:sessions')->mutating);
        self::assertFalse($this->operacion('agent:show')->mutating);

        $contestar = $this->operacion('agent:answer');
        self::assertTrue($contestar->mutating);
        self::assertFalse($contestar->requiresConfirmation);
        self::assertSame(['cli', 'tui', 'mcp'], $contestar->surfaces);
    }

    /** `agent:mode` cambia la autonomía y dice desde dónde — un cambio sin origen no se puede revisar. */
    public function testTheModeCanBeChangedAndItSaysWhereItCameFrom(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');

        $r = $this->llamar('agent:mode', ['session' => 's1', 'mode' => 'auto']);

        self::assertTrue($r['ok']);
        self::assertSame('ask', $r['from']);
        self::assertSame('auto', $r['mode']);
        self::assertSame(AutonomyMode::Auto, $almacen->load('s1')?->mode);
    }

    /**
     * Y al cambiarlo dice lo que NINGÚN modo cambia.
     *
     * Es cuando alguien podría creer lo contrario: subir a `auto` es dejar de preguntar por lo
     * reversible, no firmar en blanco. Decirlo aquí cuesta una línea; no decirlo cuesta la confusión
     * en el peor momento.
     */
    public function testChangingTheModeRestatesWhatNoModeChanges(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');

        $r = $this->llamar('agent:mode', ['session' => 's1', 'mode' => 'auto']);

        self::assertStringContainsString('firma', (string) $r['note']);
    }

    /** Un modo inventado se niega, y la negativa lista los que sí. */
    public function testAnUnknownModeIsRefusedWithTheValidOnes(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');

        $r = $this->llamar('agent:mode', ['session' => 's1', 'mode' => 'barra-libre']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('ask', (string) $r['error']);
        self::assertStringContainsString('auto', (string) $r['error']);
    }

    /**
     * Los tres rechazos de `agent:answer`, que son los que evitan escribir en el lugar equivocado.
     *
     * Sin respuesta no hay nada que apendar; sin sesión no hay dónde; y contestar algo que nadie
     * preguntó dejaría un turno suelto que el modelo leería como contexto en la siguiente vuelta —
     * una respuesta a una pregunta inexistente se vuelve una afirmación.
     */
    public function testAnsweringRefusesWhatItCannotWrite(): void
    {
        $sinRespuesta = $this->llamar('agent:answer', ['session' => 's1', 'answer' => '  ']);
        self::assertFalse($sinRespuesta['ok']);
        self::assertStringContainsString('falta `answer`', (string) $sinRespuesta['error']);

        $sinSesion = $this->llamar('agent:answer', ['session' => 'no-existe', 'answer' => 'sí']);
        self::assertFalse($sinSesion['ok']);
        self::assertStringContainsString('no existe la sesión', (string) $sinSesion['error']);
    }

    /**
     * Contestar registra QUIÉN, y por terminal ese principal va SIN verificar.
     *
     * Es la distinción que hace auditable un permiso: una terminal reporta el usuario del sistema,
     * que cualquiera con esa terminal puede ser. Guardarlo como identidad probada fabricaría una
     * cadena de custodia inexistente.
     */
    public function testAnsweringRecordsAnUnverifiedTerminalPrincipal(): void
    {
        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿Lo autorizas?', ['sí', 'no']));

        $r = $this->llamar('agent:answer', ['session' => 's1', 'answer' => 'sí']);
        self::assertTrue($r['ok']);

        $mostrado = $this->llamar('agent:show', ['session' => 's1']);
        $decision = $mostrado['decisions'][0] ?? [];

        self::assertSame('sí', $decision['answer'] ?? null);
        self::assertIsArray($decision['by'] ?? null, 'la decisión sabe quién la firmó');
        self::assertFalse($decision['by']['verified'], 'una terminal no prueba nada');
        self::assertStringStartsWith('cli:', (string) $decision['by']['id']);
    }

    /**
     * Con un actor autenticado, el principal va VERIFICADO y con su origen adelante.
     *
     * Es la otra mitad de la distinción: detrás de un `AuthContext` autenticado hay una credencial
     * que alguien comprobó, y eso sí es una identidad. El prefijo `actor:` importa porque dos canales
     * pueden usar el mismo nombre para personas distintas.
     */
    public function testAnAuthenticatedActorIsRecordedAsVerified(): void
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(SessionStore::class, $this->almacen());
        $contenedor->registerService(
            \Milpa\Auth\AuthContext::class,
            \Milpa\Auth\AuthContext::authenticated(
                new \Milpa\Auth\Actor('member:42', \Milpa\Auth\ActorType::User),
            ),
        );

        $almacen = $this->almacen();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿?', ['sí', 'no']));

        $ops = new SessionOperations($contenedor);
        foreach ($ops->operations() as $op) {
            if ($op->name === 'agent:answer') {
                ($op->handler)(['session' => 's1', 'answer' => 'sí']);
            }
        }

        $decision = $almacen->load('s1')?->decisions[0] ?? [];
        self::assertSame('actor:member:42', $decision['by']?->id);
        self::assertTrue($decision['by']?->verified, 'hubo credencial detrás');
    }

    /**
     * Sin `session`, las tres operaciones de sesión dicen qué falta en vez de adivinar cuál.
     *
     * Elegir «la última» sería cómodo y sería el defecto: quien tenga dos sesiones abiertas vería
     * responderse una que no nombró.
     */
    public function testTheSessionOperationsRefuseToGuessWhichSession(): void
    {
        foreach (['agent:show', 'agent:answer'] as $nombre) {
            $r = $this->llamar($nombre, ['answer' => 'sí']);
            self::assertFalse($r['ok'], $nombre);
            self::assertStringContainsString('falta `session`', (string) $r['error'], $nombre);
        }
    }
}
