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

use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

/**
 * El agente de esta app: le pides algo en tu idioma y lo hace con las MISMAS operaciones que ya
 * tienes.
 *
 * `milpa/ai-gateway` traía el bucle —alterna modelo ↔ herramientas— desde antes, y venía instalado
 * con el framework sin que nadie lo llamara: estaba en el árbol y no existía para quien creaba una
 * app. Esto es la línea que faltaba.
 *
 * ── LAS HERRAMIENTAS SON LOS ÁTOMOS, NO OTRA COSA ───────────────────────────────────────────────
 *
 * El agente ve exactamente lo que ve un cliente MCP, porque se arma igual: las operaciones de esta
 * app proyectadas con {@see McpProjector}. No hay un segundo catálogo de «herramientas del agente»
 * que se pueda desincronizar del primero — sería el mismo defecto que tener dos gestores de plugins.
 *
 * ── SIN LLAVE NO FINGE ──────────────────────────────────────────────────────────────────────────
 *
 * Sin API key configurada contesta qué falta y cómo ponerla. No hay modo demo ni respuesta simulada:
 * un agente que contesta algo plausible sin haber llamado a nadie es peor que uno que no arranca.
 */
class AgentOperations implements CommandProvider
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
                name: 'agent',
                description: 'Le pide al agente que haga algo usando las operaciones de esta app',
                handler: fn (array $input): array => $this->run($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'Qué quieres que haga, en tu idioma'],
                        'steps' => ['type' => 'integer', 'description' => 'Tope de pasos modelo↔herramienta; 12 si no se dice'],
                    ],
                    'required' => ['prompt'],
                ],
                // MUTA, y lo dice: el agente puede llamar operaciones que cambian cosas. Lo que NO
                // hace es pedir firma por sí mismo — la pide cada herramienta que la exige, cuando la
                // exige, y esa es la compuerta que nombra la llamada concreta. Una firma aquí
                // consentiría «lo que el agente decida», que es justo lo que no se puede consentir.
                mutating: true,
                // Fuera de la terminal: un agente que corre por HTTP con las credenciales del
                // servidor es otra decisión, y esta plantilla no la toma por nadie.
                surfaces: ['cli'],
            ),
        ];
    }

    /**
     * Corre el bucle y devuelve lo que el agente contestó.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, answer?: string, steps?: int, tools?: int, error?: string, hint?: string}
     */
    private function run(array $input): array
    {
        $prompt = \is_string($input['prompt'] ?? null) ? trim($input['prompt']) : '';
        if ($prompt === '') {
            return ['ok' => false, 'error' => 'falta `prompt`: qué quieres que haga'];
        }

        if (!class_exists(AgentOrchestrator::class) || !class_exists(ToolRegistry::class)) {
            return [
                'ok' => false,
                'error' => 'esta app no tiene la superficie de agente instalada',
                'hint' => 'composer require milpa/ai-gateway milpa/tool-runtime',
            ];
        }

        $credencial = $this->credential();
        if ($credencial === null) {
            return [
                'ok' => false,
                'error' => 'no hay API key configurada, así que no hay a quién preguntarle',
                'hint' => 'exporta ANTHROPIC_API_KEY (o OPENAI_API_KEY) y vuelve a correrlo',
            ];
        }

        [$proveedor, $llave, $modelo] = $credencial;

        $registry = $this->toolsOfThisApp();
        if ($registry === null) {
            return ['ok' => false, 'error' => 'esta app no expuso ninguna operación como herramienta'];
        }

        $pasos = \is_int($input['steps'] ?? null) && $input['steps'] > 0 ? $input['steps'] : 12;

        $vistos = 0;
        try {
            $respuesta = $this->ask($prompt, $pasos, $registry, $proveedor, $llave, $modelo, function () use (&$vistos): void {
                ++$vistos;
            });
        } catch (\Throwable $e) {
            // El motivo se devuelve tal cual: viene del proveedor —una llave inválida, un modelo que
            // no existe, la red— y quien lo lee necesita esa frase, no una reformulación.
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'answer' => $respuesta,
            'steps' => $vistos,
            'tools' => \count($registry->getToolDefinitions()),
        ];
    }

    /**
     * Una vuelta del bucle: pregunta al modelo, deja que llame herramientas, devuelve la respuesta.
     *
     * Es un método aparte —y protegido— porque es la ÚNICA parte que sale a la red. Todo lo demás de
     * esta operación —qué falta, qué herramientas hay, qué forma tiene el resultado, qué pasa si el
     * proveedor truena— se puede probar sin llave y sin red sustituyendo esto. La alternativa era
     * dejar la mitad del archivo sin medir y enterarse en producción.
     *
     * @param callable():void $onStep
     */
    protected function ask(
        string $prompt,
        int $pasos,
        ToolRegistry $registry,
        string $proveedor,
        string $llave,
        string $modelo,
        callable $onStep,
    ): string {
        $orquestador = new AgentOrchestrator(
            new LlmService($llave, $modelo, $proveedor, new NullLogger()),
            new McpClientService($registry),
            $pasos,
            new NullLogger(),
        );

        return $orquestador->run(
            $prompt,
            'Eres el agente de esta app Milpa. Usa las herramientas para responder; no inventes resultados.',
            [],
            $onStep,
        );
    }

    /**
     * La llave, el proveedor y el modelo — de las variables de entorno, o `null` si no hay ninguna.
     *
     * Anthropic primero porque es el default de la casa. El modelo se puede fijar en `config/app.php`
     * (`agent.model`) y si no se usa el de cada proveedor.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function credential(): ?array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $modeloDeclarado = $config instanceof Config ? $config->get('agent.model') : null;

        $anthropic = getenv('ANTHROPIC_API_KEY');
        if (\is_string($anthropic) && $anthropic !== '') {
            return ['anthropic', $anthropic, \is_string($modeloDeclarado) ? $modeloDeclarado : 'claude-sonnet-4-5'];
        }

        $openai = getenv('OPENAI_API_KEY');
        if (\is_string($openai) && $openai !== '') {
            return ['openai', $openai, \is_string($modeloDeclarado) ? $modeloDeclarado : 'gpt-4o'];
        }

        return null;
    }

    /**
     * Las operaciones de esta app, proyectadas como herramientas — las MISMAS que ve un cliente MCP.
     *
     * Se proyecta contra un registry nuevo y no contra el del kernel: el del kernel puede traer
     * herramientas que un plugin registró con `#[Tool]`, y las queremos también, pero armar el
     * catálogo aquí es lo que garantiza que el agente vea lo mismo que `bin/mcp-server.php` expone.
     */
    private function toolsOfThisApp(): ?ToolRegistry
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return null;
        }

        $registry = $kernel->toolRegistry();
        if (!$registry instanceof ToolRegistry) {
            $registry = new ToolRegistry(new NullLogger());
        }

        // Sólo lo que TODAVÍA no está. Proyectar dos veces sobre el mismo registro lanza
        // `ToolAlreadyRegisteredException`, y eso convertía la segunda llamada al agente —en el mismo
        // proceso— en una excepción. Lo encontró una prueba que llama dos veces; en producción lo
        // habría encontrado quien le preguntara dos cosas seguidas.
        $faltantes = array_values(array_filter(
            $kernel->commands(),
            static fn ($op): bool => $registry->getDefinition(McpProjector::toolName($op->name)) === null,
        ));

        if ($faltantes !== []) {
            (new McpProjector())->projectAll($faltantes, $registry, $kernel->container());
        }

        return $registry;
    }
}
