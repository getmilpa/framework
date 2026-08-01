<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Operations\CapabilityOperations;
use App\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * El contrato por el que un paquete se anuncia solo.
 *
 * Lo que se prueba aquí es el LECTOR del contrato, contra vendors falsos escritos en disco: es la
 * única forma de ejercitar «no está instalado» sin desinstalar nada. Que el contrato esté bien
 * DECLARADO en los seis paquetes reales lo prueba el gate `verify-capability-contract.php`, que mira
 * los `composer.json` de verdad — dos preguntas distintas, dos instrumentos.
 */
final class CapabilitiesTest extends TestCase
{
    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor = sys_get_temp_dir() . '/milpa-cap-' . bin2hex(random_bytes(6));
        mkdir($this->vendor . '/composer', 0o775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->vendor . '/composer/installed.json');
        @rmdir($this->vendor . '/composer');
        @rmdir($this->vendor);
    }

    /** @param list<array<string, mixed>> $paquetes */
    private function vendorCon(array $paquetes): string
    {
        file_put_contents(
            $this->vendor . '/composer/installed.json',
            json_encode(['packages' => $paquetes], \JSON_THROW_ON_ERROR),
        );

        return $this->vendor;
    }

    /** @return array<string, mixed> */
    private function paquete(string $nombre, string $id, string $puerto, string $briefing = ''): array
    {
        return [
            'name' => $nombre,
            'extra' => ['milpa' => ['capability' => [
                'id' => $id,
                'title' => 'lo que sea',
                'unlocks' => ['algo'],
                'provides' => [$puerto],
                'briefing' => $briefing,
            ]]],
        ];
    }

    /** Un paquete instalado que declara su capacidad se ve, con lo que él dice de sí mismo. */
    public function testAnInstalledPackageAnnouncesItself(): void
    {
        $v = $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        self::assertTrue(Capabilities::installed('agent', $v));
        self::assertArrayHasKey('milpa/agent', Capabilities::declaredBy($v));
    }

    /** Y uno que no está, no. */
    public function testAPackageThatIsNotThereIsNotInstalled(): void
    {
        $v = $this->vendorCon([]);

        self::assertFalse(Capabilities::installed('agent', $v));
        self::assertSame([], Capabilities::declaredBy($v));
    }

    /**
     * Un paquete SIN el bloque `extra.milpa` se ignora, sin romper nada.
     *
     * La mayoría de lo que hay en `vendor/` es de terceros y nunca va a declarar nada. Un lector que
     * se cayera ante eso haría el contrato inservible en la única app que importa: una real.
     */
    public function testPackagesWithoutTheContractAreSimplyIgnored(): void
    {
        $v = $this->vendorCon([
            ['name' => 'symfony/console'],       // de terceros: nunca va a declarar nada
            ['sin' => 'nombre'],                 // una entrada sin `name`
            'ni siquiera un objeto',             // y una que no es ni un mapa
            ['name' => 'milpa/mudo', 'extra' => ['milpa' => ['capability' => ['sin' => 'id']]]],
            $this->paquete('milpa/agent', 'agent', 'agent.sessions'),
        ]);

        self::assertSame(['milpa/agent'], array_keys(Capabilities::declaredBy($v)));
    }

    /**
     * DOS paquetes en el mismo puerto se ven los dos. **No es un error aquí.**
     *
     * Es la pregunta de Rod hecha prueba: si mañana `milpa/agent-postgres` guarda las sesiones en
     * otro lado, el sistema tiene que poder NOMBRAR a los dos. Quién gana lo decide la app al
     * registrar el servicio — ADR-0037 dice por qué este archivo no es el lugar para decidirlo.
     */
    public function testTwoPackagesOnTheSamePortAreBothVisible(): void
    {
        $v = $this->vendorCon([
            $this->paquete('milpa/agent', 'agent', 'agent.sessions'),
            $this->paquete('milpa/agent-postgres', 'agent-postgres', 'agent.sessions'),
        ]);

        self::assertSame(
            ['milpa/agent', 'milpa/agent-postgres'],
            Capabilities::ports($v)['agent.sessions'] ?? [],
        );
    }

    /** El contexto que el agente recibe sale de lo instalado, no de una lista del framework. */
    public function testTheAgentBriefingComesFromWhatIsInstalled(): void
    {
        $v = $this->vendorCon([
            $this->paquete('milpa/agent', 'agent', 'agent.sessions', 'Esta app guarda sesiones.'),
            $this->paquete('milpa/data', 'persistence', 'data.repository', 'Esta app persiste.'),
            $this->paquete('milpa/mudo', 'mudo', 'x'),
        ]);

        self::assertSame(['Esta app guarda sesiones.', 'Esta app persiste.'], Capabilities::briefing($v));
    }

    /**
     * Sin `installed.json` no se adivina.
     *
     * Un catálogo inventado enseñaría un camino que nadie recorrió, y el agente lo seguiría.
     */
    public function testWithoutAnInstalledJsonNothingIsInvented(): void
    {
        self::assertSame([], Capabilities::declaredBy($this->vendor));
        self::assertFalse(Capabilities::installed('agent', $this->vendor));
    }

    /** El estado separa lo puesto de lo que falta, y lo que falta trae su comando armado. */
    public function testStateSeparatesWhatIsInstalledFromWhatIsMissing(): void
    {
        $v = $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        $estado = Capabilities::state($v);

        self::assertSame(['milpa/agent'], array_column($estado['installed'], 'package'));
        $faltan = array_column($estado['available'], 'package');
        self::assertContains('milpa/auth', $faltan);
        self::assertNotContains('milpa/agent', $faltan);
        foreach ($estado['available'] as $fila) {
            self::assertSame('composer require ' . $fila['package'], $fila['command']);
        }
    }

    /** @return callable */
    private function enable(): callable
    {
        foreach ((new CapabilityOperations())->operations() as $o) {
            if ($o->name === 'capabilities:enable') {
                $h = $o->handler;
                self::assertIsCallable($h);

                return $h;
            }
        }
        self::fail('capabilities:enable no se ofrece');
    }

    /**
     * `dry_run` ensena el comando EXACTO y no corre nada.
     *
     * Es lo que hace que el consentimiento se de sobre algo legible en vez de sobre un nombre: quien
     * autoriza ve la misma linea que se va a ejecutar.
     */
    public function testDryRunShowsTheExactCommandAndRunsNothing(): void
    {
        /** @var array<string, mixed> $r */
        $r = ($this->enable())(['capability' => 'milpa/agent', 'dry_run' => true]);

        // En este monorepo agent ESTA puesto, asi que la respuesta correcta es «ya esta» y no un
        // comando: pedir dos veces no es un error, y decir que fallo mandaria a buscar otra via.
        self::assertTrue($r['ok'] ?? false);
        self::assertStringContainsString('already installed', (string) ($r['hint'] ?? ''));

        // Y sobre un vendor donde SI falta, ensena el comando exacto y no corre nada.
        $corrio = false;
        $seco = Capabilities::install(
            'milpa/agent',
            $this->vendorCon([]),
            static function (string $c) use (&$corrio): array {
                $corrio = true;
                return [0, []];
            },
            dryRun: true,
        );

        self::assertTrue($seco['ok']);
        self::assertSame('composer require milpa/agent', $seco['command']);
        self::assertTrue($seco['dry_run']);
        self::assertFalse($corrio, 'dry-run no corre nada');
    }

    /** Sin nombre, no adivina cual. */
    public function testWithoutANameItDoesNotGuess(): void
    {
        /** @var array<string, mixed> $r */
        $r = ($this->enable())([]);

        self::assertFalse($r['ok'] ?? true);
        self::assertStringContainsString('capability', (string) ($r['error'] ?? ''));
    }

    /**
     * Un nombre que no existe se rechaza CON las respuestas validas.
     *
     * Decir solo «desconocida» obliga a correr una segunda operacion para saber que se debio decir —
     * un paso mas para quien ya se equivoco una vez.
     */
    public function testAnUnknownNameIsRefusedWithTheValidAnswers(): void
    {
        /** @var array<string, mixed> $r */
        $r = ($this->enable())(['capability' => 'milpa/teleport']);

        self::assertFalse($r['ok'] ?? true);
        self::assertStringContainsString('milpa/teleport', (string) ($r['error'] ?? ''));
        self::assertIsArray($r['available'] ?? null);
    }

    /** La pista aparece cuando falta algo, y calla cuando no. */
    public function testTheHintOnlyShowsUpWhenSomethingIsMissing(): void
    {
        self::assertNull(Capabilities::hintFor([]));
        self::assertNotNull(Capabilities::hintFor([['package' => 'milpa/agent']]));
    }

    /**
     * La operación existe, no muta, y se ofrece en TODAS las superficies.
     *
     * Si el agente no la pudiera llamar, el sistema le estaría enseñando el camino sólo a quien ya
     * sabía dónde mirar — que es lo que esta operación existe para no hacer.
     */
    public function testTheOperationIsOfferedOnEverySurfaceAndChangesNothing(): void
    {
        $operaciones = (new CapabilityOperations())->operations();
        $porNombre = [];
        foreach ($operaciones as $o) {
            $porNombre[$o->name] = $o;
        }

        self::assertArrayHasKey('capabilities', $porNombre);
        self::assertFalse($porNombre['capabilities']->mutating);
        foreach (['cli', 'tui', 'mcp', 'http'] as $superficie) {
            self::assertContains($superficie, $porNombre['capabilities']->surfaces ?? []);
        }

        // Y la que INSTALA muta, y no se ofrece por HTTP: instalar un paquete corre codigo de la red
        // sobre la maquina, y un scope no sostiene eso — una superficie alcanzable desde cualquier
        // lado convierte un token filtrado en codigo arbitrario en el host.
        self::assertTrue($porNombre['capabilities:enable']->mutating);
        self::assertNotContains('http', $porNombre['capabilities:enable']->surfaces ?? []);
        self::assertContains('cli', $porNombre['capabilities:enable']->surfaces ?? []);
    }

    /**
     * Y la operación ARMA la respuesta: estado, puertos, y la pista sólo si falta algo.
     *
     * Se prueba contra un vendor falso porque en esta app no falta nada, y una rama que sólo corre
     * cuando falta algo no se ejercitaría nunca aquí.
     */
    public function testTheOperationBuildsTheAnswerWithTheHintWhenSomethingIsMissing(): void
    {
        $r = Capabilities::answer($this->vendorCon([]));

        self::assertTrue($r['ok']);
        self::assertSame([], $r['installed']);
        self::assertNotEmpty($r['available']);
        self::assertArrayHasKey('hint', $r, 'si falta algo, se dice cómo conseguirlo');

        $conTodo = Capabilities::answer($this->vendorCon([
            $this->paquete('milpa/agent', 'agent', 'agent.sessions'),
            $this->paquete('milpa/ai-gateway', 'agent-runs', 'agent.model'),
            $this->paquete('milpa/auth', 'identity', 'auth.verifier'),
            $this->paquete('milpa/data', 'persistence', 'data.repository'),
            $this->paquete('milpa/devtools', 'devtools', 'dev.doctor'),
            $this->paquete('milpa/mcp-server', 'mcp', 'surface.mcp'),
        ]));

        self::assertArrayNotHasKey('hint', $conTodo, 'y si no falta nada, no se pide trabajo');
    }

    /** Y contesta con el estado real de esta app, donde los seis opt-in están puestos. */
    public function testItAnswersWithTheRealStateOfThisApp(): void
    {
        $handler = (new CapabilityOperations())->operations()[0]->handler;
        self::assertIsCallable($handler);

        /** @var array<string, mixed> $r */
        $r = $handler([]);

        self::assertTrue($r['ok'] ?? false);
        self::assertNotEmpty($r['installed'] ?? []);
        self::assertArrayHasKey('agent.sessions', $r['ports'] ?? []);
    }

    /**
     * Instalar de verdad: el comando corre y la respuesta dice que se desbloqueo.
     *
     * El runner inyectado es la unica forma honesta de probar esto sin instalar nada — y sin el, la
     * mitad que importa de `capabilities:enable` no la ejercitaria ninguna prueba.
     */
    public function testInstallingRunsTheCommandAndSaysWhatItUnlocked(): void
    {
        $v = $this->vendorCon([]);   // nada puesto: milpa/agent esta disponible
        $corrio = null;

        $r = Capabilities::install('milpa/agent', $v, static function (string $cmd) use (&$corrio): array {
            $corrio = $cmd;

            return [0, ['ok']];
        });

        self::assertTrue($r['ok']);
        self::assertSame('composer require milpa/agent', $corrio);
        self::assertSame('composer require milpa/agent', $r['command']);
    }

    /**
     * Y si composer se niega, se devuelve LO QUE DIJO.
     *
     * Un resumen convertiria un problema con arreglo —un conflicto de version, sin red— en «no
     * funciono», que es donde se acaban las opciones de quien lo lee.
     */
    public function testWhenComposerRefusesItsOwnWordsComeBack(): void
    {
        $r = Capabilities::install('milpa/agent', $this->vendorCon([]), static fn (string $cmd): array => [
            1,
            ['Your requirements could not be resolved', 'Problem 1'],
        ]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('could not be resolved', (string) $r['error']);
    }

    /** Pedir algo ya puesto no es un error: es «ya esta». */
    public function testAskingForSomethingAlreadyInstalledIsNotAnError(): void
    {
        $v = $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        $r = Capabilities::install('milpa/agent', $v, static fn (string $c): array => [0, []]);

        self::assertTrue($r['ok']);
        self::assertStringContainsString('already installed', (string) $r['hint']);
    }

    /** Y por su id tambien, no solo por el nombre del paquete. */
    public function testItAlsoAnswersByCapabilityId(): void
    {
        $v = $this->vendorCon([$this->paquete('milpa/agent', 'agent', 'agent.sessions')]);

        self::assertTrue(Capabilities::install('agent', $v, static fn (string $c): array => [0, []])['ok']);
    }

    /** Sin nombre no adivina, y un nombre desconocido vuelve con las respuestas validas. */
    public function testItRefusesAnEmptyOrUnknownNameUsefully(): void
    {
        $v = $this->vendorCon([]);

        self::assertFalse(Capabilities::install('  ', $v)['ok']);

        $r = Capabilities::install('milpa/teleport', $v);
        self::assertFalse($r['ok']);
        self::assertContains('milpa/agent', $r['available']);
    }
}
