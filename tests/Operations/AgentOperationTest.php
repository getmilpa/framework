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

namespace App\Tests\Operations;

use App\Operations\AgentOperations;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * El agente de esta app, y sobre todo lo que hace cuando NO puede correr.
 *
 * `milpa/ai-gateway` venía instalado con el framework desde la 0.1.0 y nadie lo llamaba: estaba en el
 * árbol de dependencias y no existía para quien creaba una app. Lo que se prueba aquí es la línea que
 * faltaba y, más importante, que sin llave diga qué falta en vez de contestar algo plausible — un
 * agente que responde sin haber preguntado a nadie es peor que uno que no arranca.
 */
final class AgentOperationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $entornoPrevio = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Sin esto, una máquina con la llave exportada saldría a la red desde una prueba unitaria —
        // y pasaría o fallaría según quién la corra.
        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY'] as $var) {
            $this->entornoPrevio[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->entornoPrevio as $var => $valor) {
            if (\is_string($valor)) {
                putenv("{$var}={$valor}");
            }
        }
        parent::tearDown();
    }

    private function operacion(): Operation
    {
        return $this->operacionDe(new AgentOperations(new DIContainer()));
    }

    private function operacionDe(AgentOperations $proveedor): Operation
    {
        $operaciones = $proveedor->operations();
        self::assertCount(1, $operaciones);

        return $operaciones[0];
    }

    /**
     * Un agente con llave, con herramientas, y sin red: la única parte que sale a internet está
     * sustituida.
     *
     * @param \Closure(string): string $responder
     */
    private function agenteQueContesta(\Closure $responder): AgentOperations
    {
        putenv('ANTHROPIC_API_KEY=llave-de-prueba');

        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();

        return new class ($kernel->container(), $responder) extends AgentOperations {
            /** @param \Closure(string): string $responder */
            public function __construct(\Milpa\Interfaces\Di\DIContainerInterface $container, private readonly \Closure $responder)
            {
                parent::__construct($container);
            }

            protected function ask(
                string $prompt,
                int $pasos,
                \Milpa\ToolRuntime\ToolRegistry $registry,
                string $proveedor,
                string $llave,
                string $modelo,
                callable $onStep,
            ): string {
                $onStep();
                $onStep();

                return ($this->responder)($prompt);
            }
        };
    }

    /**
     * Con llave y con herramientas, la respuesta del agente ES el resultado — y viene con cuántos
     * pasos dio y cuántas herramientas tenía.
     *
     * Los dos números no son adorno: sin ellos, «el agente contestó» no distingue entre haber usado
     * las herramientas de esta app y haber contestado de memoria.
     */
    public function testTheAnswerComesBackWithTheStepsItTookAndTheToolsItHad(): void
    {
        $agente = $this->agenteQueContesta(static fn (string $p): string => "contesté a: {$p}");
        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, answer?: string, steps?: int, tools?: int} $r */
        $r = $handler(['prompt' => 'lista los plugins']);

        self::assertTrue($r['ok']);
        self::assertSame('contesté a: lista los plugins', $r['answer']);
        self::assertSame(2, $r['steps']);
        self::assertGreaterThan(0, $r['tools'], 'un agente sin herramientas no puede hacer nada con esta app');
    }

    /**
     * Si el proveedor truena, el motivo llega TAL CUAL.
     *
     * Viene del otro lado —una llave inválida, un modelo que no existe, la red— y quien lo lee
     * necesita esa frase para saber qué arreglar; reformularla la empeora.
     */
    public function testAProviderFailureComesBackVerbatim(): void
    {
        $agente = $this->agenteQueContesta(static function (string $p): string {
            throw new \RuntimeException('Anthropic API Error: HTTP 401 Unauthorized');
        });
        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string} $r */
        $r = $handler(['prompt' => 'lo que sea']);

        self::assertFalse($r['ok']);
        self::assertSame('Anthropic API Error: HTTP 401 Unauthorized', $r['error']);
    }

    /**
     * Con OPENAI_API_KEY y sin la de Anthropic, el proveedor que se usa es OpenAI — y el modelo sale
     * de `config/app.php` si lo declara.
     *
     * Importa porque la llave y el modelo tienen que viajar juntos: una llave de OpenAI con el modelo
     * default de Anthropic falla del otro lado, con un mensaje que no dice que la culpa fue de aquí.
     */
    public function testTheProviderFollowsWhichKeyIsPresent(): void
    {
        putenv('OPENAI_API_KEY=llave-openai');

        $kernel = \App\Tests\Support\OperationsTest::bootedKernel();
        // La captura va por propiedad ESTÁTICA: una readonly no se puede escribir después de
        // construirse, y una referencia a un array local tampoco (`Cannot indirectly modify`).
        $agente = new class ($kernel->container()) extends AgentOperations {
            /** @var array<string, string> */
            public static array $visto = [];

            protected function ask(
                string $prompt,
                int $pasos,
                \Milpa\ToolRuntime\ToolRegistry $registry,
                string $proveedor,
                string $llave,
                string $modelo,
                callable $onStep,
            ): string {
                static::$visto = ['proveedor' => $proveedor, 'llave' => $llave, 'modelo' => $modelo];

                return 'ok';
            }
        };

        $handler = $this->operacionDe($agente)->handler;
        self::assertIsCallable($handler);
        $handler(['prompt' => 'algo']);

        $visto = $agente::$visto;
        self::assertSame('openai', $visto['proveedor']);
        self::assertSame('llave-openai', $visto['llave']);
        self::assertSame('gpt-4o', $visto['modelo'], 'sin `agent.model` declarado, el default del proveedor');
    }

    /**
     * Sin kernel en el contenedor no hay catálogo de herramientas, y se dice.
     *
     * Es el caso de una app que llama a esta operación fuera de su terminal: sin herramientas, un
     * agente no puede hacer nada con la app, y contestar «no tengo herramientas» es más útil que
     * contestar de memoria.
     */
    public function testWithoutAKernelThereAreNoToolsAndItSaysSo(): void
    {
        putenv('ANTHROPIC_API_KEY=llave-de-prueba');

        $handler = $this->operacion()->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string} $r */
        $r = $handler(['prompt' => 'algo']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('herramienta', (string) $r['error']);
    }

    /**
     * Sin llave configurada dice QUÉ falta y CÓMO ponerla, y no inventa una respuesta.
     *
     * Es la mitad que importa de esta operación: un modo demo que contesta algo verosímil sin haber
     * llamado a ningún modelo enseña a confiar en respuestas que nadie produjo.
     */
    public function testWithoutAKeyItSaysWhatIsMissingInsteadOfPretending(): void
    {
        $handler = $this->operacion()->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string, hint?: string, answer?: string} $r */
        $r = $handler(['prompt' => 'lista los plugins']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('API key', (string) $r['error']);
        self::assertStringContainsString('ANTHROPIC_API_KEY', (string) $r['hint']);
        self::assertArrayNotHasKey('answer', $r, 'sin llave no hay respuesta que dar');
    }

    /** Sin prompt no se le pregunta nada a nadie. */
    public function testWithoutAPromptItRefusesBeforeReachingAnybody(): void
    {
        $handler = $this->operacion()->handler;
        self::assertIsCallable($handler);

        /** @var array{ok: bool, error?: string} $r */
        $r = $handler([]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('prompt', (string) $r['error']);
    }

    /**
     * MUTA y NO pide firma, a propósito.
     *
     * El agente puede llamar operaciones que cambian cosas, así que decir que no muta sería mentir.
     * Pero la firma la pide cada herramienta que la exige, cuando la exige: una firma aquí
     * consentiría «lo que el agente decida», que es exactamente lo que no se puede consentir de
     * antemano.
     */
    public function testItMutatesAndLeavesTheSignatureToEachToolThatDemandsIt(): void
    {
        $op = $this->operacion();

        self::assertTrue($op->mutating);
        self::assertFalse($op->requiresConfirmation);
    }

    /**
     * Sólo en la terminal.
     *
     * Un agente corriendo por HTTP con las credenciales del servidor es otra decisión —y de quien
     * monta la app, no de esta plantilla.
     */
    public function testItIsATerminalSurfaceOnly(): void
    {
        $op = $this->operacion();

        self::assertTrue($op->supportsSurface('cli'));
        self::assertFalse($op->supportsSurface('http'));
        self::assertFalse($op->supportsSurface('mcp'), 'un agente que se ofrece a otro agente es un bucle que nadie pidió');
    }
}
