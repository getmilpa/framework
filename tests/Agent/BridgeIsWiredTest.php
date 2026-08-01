<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\SurfaceBroadcaster;
use App\Operations\AgentOperations;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Que el puente esté ESCRITO no es que esté conectado.
 *
 * Este repositorio lleva un mes cazando el mismo defecto —lo declarado que nunca aterriza: `make`
 * cableaba 2 de 6 generadores, `exclusive` se escribía y no lo leía nadie, el arreglo de la línea
 * base se registraba después de que el kernel ya había recolectado— y las tres veces las pruebas
 * probaban las piezas, no el orden. Ésta prueba el orden: que la sesión que el sistema realmente
 * construye sea la que empuja.
 */
final class BridgeIsWiredTest extends TestCase
{
    /** Con un broadcaster registrado, lo que el agente escribe llega a la superficie. */
    public function testWhatTheAgentWritesReachesTheSurface(): void
    {
        $espia = new BroadcasterEspia();
        $contenedor = new DIContainer();
        $contenedor->registerService(EventStoreInterface::class, new InMemoryEventStore());
        $contenedor->registerService(SurfaceBroadcaster::class, $espia);

        $almacen = (new AgentOperations($contenedor))->sessionStore();
        self::assertNotNull($almacen);

        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'A', TodoStatus::Pending));

        self::assertSame(['milpa/sessions/s1'], $espia->topics);
        self::assertSame('card', $espia->payloads[0]['kind'] ?? null);
    }

    /**
     * Un hub Mercure en el contenedor basta: no hace falta declarar el puerto.
     *
     * Se pregunta por el NOMBRE de la clase, no por la clase, para no arrastrar `milpa/mercure` como
     * dependencia de este proyecto.
     */
    public function testAMercureHubInTheContainerIsEnough(): void
    {
        $hub = new HubDePrueba();
        $contenedor = new DIContainer();
        $contenedor->registerService(EventStoreInterface::class, new InMemoryEventStore());
        $contenedor->registerService('Milpa\\Mercure\\MercureService', $hub);

        $almacen = (new AgentOperations($contenedor))->sessionStore();
        self::assertNotNull($almacen);

        $almacen->start('s1', 'x');
        $almacen->setPlan('s1', 'un plan');

        self::assertNotEmpty($hub->publicados);
        self::assertSame('milpa/sessions/s1', $hub->publicados[0][0]);
    }

    /**
     * Sin nadie a quien empujarle, la sesión funciona igual.
     *
     * Una app sin tablero no paga nada — ni una dependencia, ni un fallo al arrancar.
     */
    public function testWithoutASurfaceTheSessionStillWorks(): void
    {
        $contenedor = new DIContainer();
        $contenedor->registerService(EventStoreInterface::class, new InMemoryEventStore());

        $almacen = (new AgentOperations($contenedor))->sessionStore();
        self::assertNotNull($almacen);

        $almacen->start('s1', 'x');

        self::assertSame('x', $almacen->load('s1')?->goal);
    }
}
