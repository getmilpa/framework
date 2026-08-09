<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Tests\Support\OptIn;
use Milpa\AppRuntime\Console\Application;
use PHPUnit\Framework\TestCase;

/**
 * `coa` contra esta app REAL, por proceso.
 *
 * Se corre de verdad y no se instancia la clase: lo que esta app promete es que un
 * `composer create-project` arranca y hace algo útil sin tocar un archivo, y eso sólo lo prueba un
 * proceso que arranca el kernel de verdad. Una prueba que llamara a `Application::run()` con dobles
 * mediría el despachador y no la promesa.
 */
final class ApplicationTest extends TestCase
{
    private function coa(string $args = ''): array
    {
        $salida = [];
        $codigo = 0;
        exec('php ' . escapeshellarg(\dirname(__DIR__, 2) . '/bin/coa') . ' ' . $args . ' 2>&1', $salida, $codigo);

        return ['texto' => implode("\n", $salida), 'codigo' => $codigo];
    }

    /**
     * La ayuda se DERIVA de los átomos, así que no puede quedar desactualizada.
     *
     * Es la diferencia con el punto de entrada anterior de la familia, que enumeraba sus quince
     * comandos a mano: una ayuda escrita es el primer archivo que miente cuando alguien instala un
     * plugin.
     */
    public function testHelpIsDerivedFromTheDeclaredOperations(): void
    {
        $r = $this->coa();

        self::assertSame(0, $r['codigo'], $r['texto']);
        self::assertStringContainsString('Every command is a declared operation', $r['texto']);
        // De las dos fuentes: `plugins:*` los declara un plugin arrancado, `validate` y `make` los
        // publica un paquete que esta app adopta.
        // PISO: que la ayuda salga de las operaciones declaradas se mide sin un solo opt-in, y
        // `plugins:*` los declara un plugin arrancado (evidence/0103).
        self::assertStringContainsString('plugins:list', $r['texto']);
        self::assertStringContainsString('capabilities', $r['texto']);

        // FRONTERA: `validate` y `make` los publica un paquete que esta app ADOPTA. Exigirlos aquí
        // hacía que el piso de arriba se perdiera junto con ellos.
        if (OptIn::has(\Milpa\DevTools\Doctor\Repair::class)) {
            self::assertStringContainsString('validate', $r['texto']);
            self::assertStringContainsString('make', $r['texto']);
        }
    }

    /** Lo que muta se lista aparte de lo que consulta, porque no se leen igual. */
    public function testTheHelpSeparatesWhatReadsFromWhatChanges(): void
    {
        $texto = $this->coa()['texto'];

        self::assertStringContainsString('They read:', $texto);
        self::assertStringContainsString('They change something:', $texto);
        self::assertLessThan(
            strpos($texto, 'plugins:enable'),
            (int) strpos($texto, 'They change something:'),
            'habilitar un plugin cambia algo y va del lado que cambia',
        );
    }

    /** Una operación de plugin corre contra el registro real de esta app. */
    public function testAPluginOperationRunsAgainstThisApp(): void
    {
        $r = $this->coa('plugins:list --json');

        self::assertSame(0, $r['codigo'], $r['texto']);

        /** @var array{ok: bool, result: array{plugins: list<array<string, mixed>>}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($json['ok']);

        // El CONJUNTO, no el orden: encender o apagar un plugin le escribe un registro, y con eso
        // cambia de lugar en la lista. Afirmar la secuencia hacía que esta prueba dependiera de
        // cuál corrió antes — y una prueba sensible al orden de sus hermanas falla por motivos que
        // no tienen nada que ver con lo que mide.
        $nombres = array_column($json['result']['plugins'], 'name');
        sort($nombres);
        self::assertSame(['HelloPlugin', 'OperationsHttp', 'PluginManagement'], $nombres);
    }

    /**
     * Lo obligatorio se escribe POSICIONAL, como en cualquier CLI.
     *
     * El átomo declara `target` como entrada obligatoria y aquí se teclea `validate HelloPlugin`, sin
     * bandera. Que el materializador lo traduzca es lo que permite migrar una capacidad a átomo sin
     * cambiarle a nadie la forma de invocarla.
     */
    public function testRequiredInputIsWrittenPositionally(): void
    {
        // RE-VEHICULADO DE `validate` A `plugins:show` (evidence/0103). La propiedad —que un
        // posicional llegue al esquema como entrada obligatoria— es PISO: se mide con cero opt-ins.
        // Montada sobre `validate`, que es de las dev tools, nacía roja en un recién nacido y el
        // piso se perdía con la frontera. `plugins:show` declara `name` obligatorio y existe pelona.
        $r = $this->coa('plugins:show HelloPlugin --json');

        /** @var array{result: array{name: string}} $json */
        $json = json_decode(trim($r['texto']), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('HelloPlugin', $json['result']['name']);
    }

    /**
     * Lo que no se puede deshacer NO corre sin firma, y no escribe nada al negarse.
     *
     * Es la garantía que distingue a este runtime de otro agente de terminal, y por eso se comprueba
     * con la operación real y no con una declaración: que el átomo diga `requiresConfirmation` sólo
     * sirve si algo lo honra.
     */
    public function testWhatIsReversibleRunsWithoutASignature(): void
    {
        self::assertSame(0, $this->coa('plugins:disable HelloPlugin')['codigo'], 'deshabilitar es reversible');
        self::assertFalse($this->habilitado('HelloPlugin'), 'y de verdad lo deshabilitó');

        $this->coa('plugins:enable HelloPlugin');
        self::assertTrue($this->habilitado('HelloPlugin'));
    }

    /**
     * El ESTADO de un plugin, no la cadena que lo reporta.
     *
     * La primera versión de esto comparaba la salida JSON completa antes y después del ciclo, y
     * fallaba: encender un plugin le escribe un registro y con eso cambia el ORDEN de la lista. El
     * estado era idéntico y la cadena no. Comparar representaciones cuando lo que importa es el hecho
     * es una prueba que se rompe sola.
     */
    private function habilitado(string $nombre): bool
    {
        /** @var array{result: array{plugins: list<array{name: string, enabled: bool}>}} $json */
        $json = json_decode(trim($this->coa('plugins:list --json')['texto']), true, 512, JSON_THROW_ON_ERROR);

        foreach ($json['result']['plugins'] as $plugin) {
            if ($plugin['name'] === $nombre) {
                return $plugin['enabled'];
            }
        }

        self::fail("«{$nombre}» no aparece en la lista");
    }

    /** Un comando que no existe lo dice y enseña los que sí, en vez de sólo negarse. */
    public function testAnUnknownCommandShowsWhatDoesExist(): void
    {
        $r = $this->coa('no-existe-esto');

        self::assertStringContainsString('no existe el comando', $r['texto']);
        self::assertStringContainsString('plugins:list', $r['texto']);
    }

    /**
     * `coa doctor` corre EN PROCESO, que es como se prueba lo que imprime — y a la vez la prueba de
     * que no necesita el kernel arriba.
     *
     * @return array{texto: string, codigo: int}
     */
    private function doctorEnProceso(): array
    {
        $app = new Application(\dirname(__DIR__, 2));

        ob_start();
        $codigo = $app->run(['coa', 'doctor']);
        $texto = (string) ob_get_clean();

        return ['texto' => $texto, 'codigo' => $codigo];
    }

    /**
     * El doctor explica la app sin arrancarla, y nombra lo que cada plugin provee y pide.
     *
     * Es lo que hace que exista: cuando el grafo no cierra, el kernel no arranca, `coa` no despacha y
     * NINGUNA operación corre — medido en esta misma app, las quince herramientas del agente caídas y
     * una línea de error como todo el dato. La herramienta que explica por qué algo no arranca no
     * puede necesitar que arranque.
     */
    public function testTheDoctorExplainsTheAppWithoutBootingIt(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $r = $this->doctorEnProceso();

        self::assertSame(0, $r['codigo'], $r['texto']);
        self::assertStringContainsString('plugin(s) declared', $r['texto']);
        self::assertStringContainsString('HelloPlugin', $r['texto']);
        self::assertStringContainsString('provee:', $r['texto']);
        self::assertStringContainsString('pide:', $r['texto']);
        self::assertStringContainsString('✓ el grafo cierra', $r['texto']);
    }

    /**
     * Y sale con código 0 cuando cierra — porque un CI que lo corra necesita el veredicto, no el texto.
     */
    public function testTheDoctorExitsGreenWhenTheGraphCloses(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        self::assertSame(0, $this->doctorEnProceso()['codigo']);
    }

    /** Se ofrece en la ayuda: una capacidad que existe y no se anuncia no la encuentra nadie. */
    public function testTheDoctorIsOfferedInTheHelp(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        self::assertStringContainsString('doctor', $this->coa()['texto']);
    }

    /**
     * Cuando el grafo NO cierra, el doctor imprime lo aprendible del resolver tal cual viene.
     *
     * Ésta es la mitad que importa. Un doctor que sólo sabe describir apps sanas es un adorno: el
     * momento en que alguien lo corre es justo cuando algo no arranca, y ahí lo que decide si la
     * persona sale del hoyo es que el error traiga POR QUÉ, cómo se arregla, y qué acción aplicar.
     *
     * Se arma una app con un plugin que pide una capacidad que nadie provee — el caso más común de
     * todos — y se verifica que el texto lleve las cuatro cosas.
     */
    public function testWhenTheGraphDoesNotCloseTheDoctorPrintsTheLearnableError(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $raiz = sys_get_temp_dir() . '/milpa-doctor-roto-' . bin2hex(random_bytes(4));
        mkdir($raiz . '/config', 0o777, true);
        mkdir($raiz . '/src/Plugins/Huerfano', 0o777, true);

        file_put_contents($raiz . '/src/Plugins/Huerfano/HuerfanoPlugin.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Plugins\Huerfano;

            use Milpa\Attributes\PluginMetadata;
            use Milpa\Interfaces\Plugin\PluginInterface;
            use Milpa\Plugin\PluginBase;

            #[PluginMetadata(
                version: '0.1.0',
                author: 'prueba',
                site: 'https://example.com',
                name: 'Huerfano',
                type: 'Service',
                requires: ['nadie.provee.esto.v1'],
            )]
            final class HuerfanoPlugin extends PluginBase implements PluginInterface
            {
                public function boot(): void {}
                public function install(): void {}
                public function uninstall(): void {}
                public function enable(): void {}
                public function disable(): void {}
            }
            PHP);

        file_put_contents($raiz . '/config/plugins.php', "<?php\n\nreturn [\\App\\Plugins\\Huerfano\\HuerfanoPlugin::class];\n");

        require_once $raiz . '/src/Plugins/Huerfano/HuerfanoPlugin.php';

        $app = new Application($raiz);
        ob_start();
        $codigo = $app->run(['coa', 'doctor']);
        $texto = (string) ob_get_clean();

        self::assertSame(1, $codigo, $texto);
        self::assertStringContainsString('nadie.provee.esto.v1', $texto);

        $this->borrar($raiz);
    }

    private function borrar(string $ruta): void
    {
        if (!is_dir($ruta)) {
            return;
        }
        foreach (scandir($ruta) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $hijo = $ruta . '/' . $e;
            is_dir($hijo) ? $this->borrar($hijo) : @unlink($hijo);
        }
        @rmdir($ruta);
    }

    /**
     * `/sessions` lista y `/sessions <id>` cambia — sin gastar una vuelta del modelo.
     *
     * Cambiar de sesión no es una pregunta: es cambiar el sujeto de la conversación. Mandarlo al
     * agente gastaría una llamada al proveedor para que conteste sobre algo que el sistema ya sabe, y
     * lo contestaría interpretando en vez de haciendo.
     *
     * LA SESIÓN LA CREA ESTA PRUEBA. Antes sólo afirmaba que la respuesta era una cadena, así que
     * pasaba con el almacén vacío —enumerando la nada— y con el almacén lleno, midiendo cosas
     * distintas sin decirlo. En mi máquina cubría trece líneas que en CI no, porque mi `var/` traía
     * las sesiones del día: 90.34% aquí, 89.83% allá, sobre EL MISMO código y las mismas pruebas. Una
     * cobertura que depende de lo que quedó en el disco no está midiendo la suite.
     */
    public function testSlashSessionsListsAndSwitchesWithoutAskingTheModel(): void
    {
        OptIn::needs(\Milpa\Agent\SessionStore::class);

        $app = new Application(\dirname(__DIR__, 2));
        $metodo = new \ReflectionMethod($app, 'preguntarAlAgente');

        $almacen = (new \ReflectionMethod($app, 'almacenDeSesiones'))->invoke($app);
        if (!$almacen instanceof \Milpa\Agent\SessionStore) {
            self::markTestSkipped('esta app no guarda sesiones');
        }

        $j = 'jornada-' . bin2hex(random_bytes(3));
        $almacen->start($j, 'la tarea de esta prueba');
        (new \ReflectionProperty($app, 'sesionDelChatId'))->setValue($app, $j);

        $listado = (string) $metodo->invoke($app, '/sessions')['answer'];

        self::assertStringContainsString($j, $listado, 'la sesión que existe aparece enumerada');
        self::assertStringContainsString('pendiente(s)', $listado);
        self::assertStringContainsString('← estás aquí', $listado, 'y dice en cuál estás parado');

        // Cambiarse a una que SÍ existe deja al chat parado ahí — el camino que la enumeración
        // habilita, y el que sólo se probaba por su negativa.
        $otra = 'jornada-' . bin2hex(random_bytes(3));
        $almacen->start($otra, 'otra cosa');

        self::assertTrue($metodo->invoke($app, '/sessions ' . $otra)['ok']);
        self::assertSame($otra, (new \ReflectionProperty($app, 'sesionDelChatId'))->getValue($app));

        $inexistente = $metodo->invoke($app, '/sessions no-existe-esta');

        self::assertFalse($inexistente['ok'], 'no abre una sesión vacía con ese nombre');
        self::assertStringContainsString('no existe la sesión', (string) $inexistente['error']);
    }

    /**
     * LA PREGUNTA DEL SUB-AGENTE SE BUSCA POR FILIACIÓN, NO POR NOMBRE (Q-P19-Q).
     *
     * El prefijo `<padre>.sub-` es convención del spawner; lo que decide es el `parentId` apendado en
     * el stream del hijo. Una sesión que se llamara así sin descender de esta no es hija de nadie, y
     * pintar su pregunta en esta pantalla sería ofrecerle al humano contestar por un árbol ajeno.
     */
    public function testAChildsPendingQuestionIsFoundByLineageAndNotByName(): void
    {
        OptIn::needs(\Milpa\Agent\SessionStore::class);

        // La raíz del paquete, que ES una app Milpa — igual que el test de `/sessions` de arriba. Los
        // ids llevan sufijo aleatorio porque el almacén es el de esa app y no se puede borrar de él:
        // un stream es una bitácora, no una tabla.
        $j = 'jornada-' . bin2hex(random_bytes(3));
        $app = new Application(\dirname(__DIR__, 2));

        $almacen = (new \ReflectionMethod($app, 'almacenDeSesiones'))->invoke($app);
        if (!$almacen instanceof \Milpa\Agent\SessionStore) {
            self::markTestSkipped('esta app no guarda sesiones');
        }

        (new \ReflectionProperty($app, 'sesionDelChatId'))->setValue($app, $j);
        $almacen->start($j, 'la tarea grande');
        // Se llama como una hija y NO lo es: sin `parentId`, no cuenta.
        $almacen->start($j . '.sub-impostor', 'ajena');
        $almacen->ask($j . '.sub-impostor', new \Milpa\Agent\PendingQuestion(
            id: 'perm:make',
            question: '¿autorizas algo ajeno?',
            options: ['sí', 'no'],
        ));

        $buscar = new \ReflectionMethod($app, 'preguntaDeHijoPausado');
        self::assertNull($buscar->invoke($app), 'el prefijo del nombre no basta');

        // Y una hija de verdad sí aparece, con su id para que quien conteste sepa a quién.
        $almacen->start($j . '.sub-real', 'la sub-tarea', parentId: $j);
        $almacen->ask($j . '.sub-real', new \Milpa\Agent\PendingQuestion(
            id: 'perm:make',
            question: '¿autorizas make?',
            options: ['sí', 'no'],
        ));

        $hijo = $buscar->invoke($app);
        self::assertIsArray($hijo);
        self::assertSame($j . '.sub-real', $hijo['session']);
        self::assertSame('¿autorizas make?', $hijo['question']);
        self::assertSame(['sí', 'no'], $hijo['options']);
    }

    /** Y contestarle al hijo va por la MISMA operación que contesta las propias, con su id. */
    public function testAnsweringAChildGoesThroughTheSameOperationWithItsOwnId(): void
    {
        OptIn::needs(\Milpa\Agent\SessionStore::class);

        $j = 'jornada-' . bin2hex(random_bytes(3));
        $app = new Application(\dirname(__DIR__, 2));

        $almacen = (new \ReflectionMethod($app, 'almacenDeSesiones'))->invoke($app);
        if (!$almacen instanceof \Milpa\Agent\SessionStore) {
            self::markTestSkipped('esta app no guarda sesiones');
        }

        (new \ReflectionProperty($app, 'sesionDelChatId'))->setValue($app, $j);
        $almacen->start($j, 'x');
        $almacen->start($j . '.sub-real', 'y', parentId: $j);
        $almacen->ask($j . '.sub-real', new \Milpa\Agent\PendingQuestion(
            id: 'perm:make',
            question: '¿autorizas make?',
            options: ['sí', 'no'],
        ));

        $eco = (new \ReflectionMethod($app, 'contestarAlHijo'))->invoke($app, $j . '.sub-real', 'sí');

        self::assertTrue($eco['ok'] ?? false);
        self::assertNull($almacen->load($j . '.sub-real')?->question, 'el hijo dejó de esperar');
        self::assertNull($almacen->load($j)?->question, 'y la del padre no se tocó');
    }

    /**
     * REPARAR TAMPOCO PUEDE NECESITAR QUE ARRANQUE — y esta prueba lo fija donde importa.
     *
     * La raíz que recibe NO tiene kernel: sólo un `config/plugins.php` y ningún `config/boot.php`, así
     * que cualquier operación moriría al despachar. `repair` contesta igual, porque va antes — la
     * misma decisión que `doctor` ya tenía y por la misma razón.
     *
     * Sin esto, la herramienta que repara no correría sobre la app que necesita reparación: medido el
     * 2026-08-04 en un host con una capacidad requerida sin proveedor, `coa:doctor`, `coa repair` y
     * cualquier otra operación morían con el mismo `[Initialization Error]`.
     */
    public function testRepairAnswersOnARootThatCannotBoot(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $raiz = sys_get_temp_dir() . '/milpa-sin-kernel-' . bin2hex(random_bytes(4));
        mkdir($raiz . '/config', 0o775, true);
        file_put_contents($raiz . '/config/plugins.php', '<?php return [];');

        self::assertFileDoesNotExist($raiz . '/config/boot.php', 'la raíz no puede arrancar, y ése es el punto');

        $app = new Application($raiz);

        ob_start();
        $codigo = $app->run(['coa', 'repair', 'milpa/mcp-server']);
        $texto = (string) ob_get_clean();

        $this->borrar($raiz);

        self::assertSame(1, $codigo, 'no repara lo que el diagnóstico no pidió');
        self::assertStringContainsString('no recomienda instalar nada', $texto, 'contestó, no se cayó');
        self::assertStringNotContainsString('Initialization Error', $texto);
    }

    /** Sin paquete dice cómo se usa, en vez de reparar lo primero que encuentre. */
    public function testRepairWithoutAPackageSaysHowItIsUsed(): void
    {
        OptIn::needs(\Milpa\DevTools\Doctor\Repair::class);

        $app = new Application(\dirname(__DIR__, 2));

        ob_start();
        $codigo = $app->run(['coa', 'repair']);
        $texto = (string) ob_get_clean();

        self::assertSame(1, $codigo);
        self::assertStringContainsString('uso: coa repair', $texto);
    }
}
