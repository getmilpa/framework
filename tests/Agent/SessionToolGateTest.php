<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\SessionToolGate;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * La compuerta que detiene al agente antes de que cambie algo (P16.4/P16.5).
 *
 * Lo que se mide aquí no es la política —eso vive probado en `milpa/agent`— sino que negar APENDE:
 * la sesión queda esperando y la pregunta sobrevive al proceso. Una negativa que sólo existiera en el
 * texto de la respuesta se perdería en cuanto cerraras la terminal, y entonces «el agente pidió
 * permiso» sería una frase sin nada detrás.
 */
final class SessionToolGateTest extends TestCase
{
    /** @return list<Operation> */
    private function operaciones(): array
    {
        return [
            new Operation('plugins_list', 'Lista', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []]),
            new Operation('make', 'Andamia', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true),
            new Operation('plugins_remove', 'Quita', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true, requiresConfirmation: true),
        ];
    }

    private function compuerta(
        SessionStore $almacen,
        string $id,
        AutonomyMode $modo = AutonomyMode::Ask,
        ?\DateInterval $ventana = null,
    ): SessionToolGate {
        $almacen->start($id, 'x', $modo);
        $sesion = $almacen->load($id);
        self::assertNotNull($sesion);

        return new SessionToolGate($almacen, $sesion, $this->operaciones(), permissionWindow: $ventana);
    }

    /** Leer pasa, y no deja nada apendado: preguntar por una consulta gastaría la atención en lo que no importa. */
    public function testReadingPassesAndLeavesNoQuestionBehind(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1');

        self::assertNull($compuerta->refuse('plugins_list', []));
        self::assertNull($almacen->load('s1')?->question);
    }

    /**
     * Lo que muta se niega Y SE APENDA: la sesión queda esperando.
     *
     * Las dos mitades importan. Sin la negativa, la herramienta corre; sin el apendado, nadie puede
     * contestar desde otro proceso y la pausa muere con la terminal.
     */
    public function testAMutationIsRefusedAndTheSessionIsLeftWaiting(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1');

        $motivo = $compuerta->refuse('make', ['what' => 'plugin', 'plugin' => 'Cobranza']);

        self::assertNotNull($motivo);
        self::assertStringContainsString('make', $motivo);
        self::assertStringContainsString('Cobranza', $motivo, 'la negativa dice sobre QUÉ');
        self::assertStringContainsString('agent:answer', $motivo, 'y cómo contestarla');

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertFalse($sesion->isRunnable(), 'la sesión quedó esperando');
        self::assertSame('perm:make', $sesion->question?->id);
    }

    /** Con el permiso ya otorgado, pasa sin preguntar de nuevo. */
    public function testAGrantedOperationPassesWithoutAskingAgain(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x');
        $almacen->grant('s1', 'make');
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate($almacen, $sesion, $this->operaciones());

        self::assertNull($compuerta->refuse('make', []));
        self::assertNull($almacen->load('s1')?->question, 'no volvió a preguntar');
    }

    /** En `auto`, lo que muta y no exige firma sigue de largo. */
    public function testInAutoModeAMutationDoesNotStop(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1', AutonomyMode::Auto);

        self::assertNull($compuerta->refuse('make', []));
    }

    /**
     * LA FIRMA SE DETIENE INCLUSO EN `auto`, y la pregunta no ofrece un «sí».
     *
     * Es la línea entre autonomía y cheque en blanco, medida donde se aplica y no sólo donde se
     * decide: la política ya lo dice, y esta prueba verifica que la compuerta la honra.
     */
    public function testASignatureStopsEvenInAutoAndOffersNoWayToApproveFromHere(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1', AutonomyMode::Auto);

        $motivo = $compuerta->refuse('plugins_remove', ['name' => 'X']);

        self::assertNotNull($motivo);
        self::assertStringContainsString('--sign', $motivo);
        self::assertStringNotContainsString('agent:answer', $motivo, 'no hay respuesta que autorice esto');

        $sesion = $almacen->load('s1');
        self::assertSame('sign:plugins_remove', $sesion?->question?->id);
    }

    /**
     * Una herramienta que no viene de una operación de esta app se deja pasar.
     *
     * Esta política no sabe si muta, y negar lo que no se entiende volvería inútil cualquier registro
     * externo. El registro de herramientas tiene su propia compuerta de scopes, que sigue puesta.
     */
    public function testAToolThisAppDidNotDeclareIsLeftToItsOwnGate(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $compuerta = $this->compuerta($almacen, 's1');

        self::assertNull($compuerta->refuse('herramienta_de_otro_registro', []));
    }

    /**
     * Con ventana declarada, la pregunta nace con plazo; sin ella, espera para siempre.
     *
     * Es la línea que ARMA la caducidad. Sin ella el mecanismo existe, está probado, y no lo usa
     * nadie — el patrón que este repositorio lleva un mes cazando: una capacidad a la que le falta la
     * línea que la enchufa.
     */
    public function testTheHostsWindowReachesTheQuestionAsADeadline(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $this->compuerta($almacen, 's1', ventana: new \DateInterval('PT4H'))->refuse('make', []);

        $pregunta = $almacen->load('s1')?->question;

        self::assertNotNull($pregunta?->expiresAt, 'la ventana del host tiene que llegar hasta aquí');
        self::assertFalse($pregunta->hasExpired(new \DateTimeImmutable('+3 hours')));
        self::assertTrue($pregunta->hasExpired(new \DateTimeImmutable('+5 hours')));
    }

    /** Sin ventana, la pregunta no lleva plazo — que es lo que hacía antes de que esto existiera. */
    public function testWithoutAWindowTheQuestionCarriesNoDeadline(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $this->compuerta($almacen, 's1')->refuse('make', []);

        self::assertNull($almacen->load('s1')?->question?->expiresAt);
    }
}
