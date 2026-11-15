<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Moderacion\Modelo\DatosDeSala;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Modelo\ImagenSubida;
use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
use IndieCinema\Moderacion\Pruebas\Dobles\Imagenes;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSolicitudes;
use IndieCinema\Moderacion\Servicios\ArchivosDeSolicitudes;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La bandeja de solicitudes de sala de punta a punta, ya en moderación (EF-07.2): la aplicación
 * entera con las rutas de moderación, las plantillas del paquete front, un MySQL de verdad y una
 * cuentas y una programación de mentira (ConServidoresDePrueba), a las que se les piden los datos
 * de quien la mandó, el rol y la sala. En desarrollo, para que Twig falle si la plantilla usa una
 * variable que el controlador no le pasa.
 */
final class AdministracionDeSolicitudesTest extends TestCase
{
    use ConBaseDePruebas;
    use ConSaltoAlContenido;
    use ConServidoresDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ADMINISTRADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const MODERADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    // Los usuarios que conoce Dobles/cuentas.php.
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const LENTO = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;
    private string $carpeta;

    /** Por dónde salen los correos: el SMTP de prueba, salvo que la prueba lo tire. */
    private ?string $servidorDeCorreo = null;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::iniciarServidores();
        self::$correo = ServidorSmtpDePrueba::iniciar();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerServidores();
        self::$correo->detener();
    }

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        self::reiniciarServidores();
        self::$correo->vaciar();
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->carpeta}/solicitudes/*") ?: []);
        @rmdir("{$this->carpeta}/solicitudes");
        @rmdir($this->carpeta);
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => $this->servidorDeCorreo ?? self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => 'equipo@indiecinema.test'],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $url, ?string $rol = 'administrador', array $cuerpo = [], ?string $usuario = null): Respuesta
    {
        $usuario ??= $rol === 'moderador' ? self::MODERADORA : self::ADMINISTRADOR;
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('moderacion', $this->salidaDelLog));

        return PaginasParaValidar::anotar($metodo, "/moderacion{$url}", $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF])));
    }

    /**
     * @param array<string, string> $campos
     */
    private function resolver(string $id, array $campos, ?string $rol = 'administrador'): Respuesta
    {
        return $this->pedir('POST', "/solicitudes/{$id}/resolver", $rol, $campos + ['_csrf' => self::CSRF]);
    }

    /**
     * Una solicitud pendiente, como la deja el formulario, con su foto en el volumen; sin foto, como
     * una que se mudó de programación.
     */
    private function solicitar(string $usuario, string $sala, string $enviada = '2026-10-01 10:00:00', string $localidad = 'Luján', ?string $nombre = 'Eva Prueba', bool $conFoto = true, ?string $salaId = null, bool $cambio = false): string
    {
        $foto = $conFoto ? (new ArchivosDeSolicitudes($this->configuracion()))->guardar(new ImagenSubida(Imagenes::subida('sala.png')['tmp_name'], 'png')) : null;
        $id = Uuid::nuevo();
        (new RepositorioDeSolicitudes($this->base))->agregar(new SolicitudDeSala(
            $id,
            $salaId ?? Uuid::nuevo(),
            $usuario,
            $nombre,
            new DatosDeSala($sala, 'Un galpón con proyector.', 'Calle 1', $localidad, 40, 2, 120, 15),
            $foto,
            EstadoSolicitud::Pendiente,
            new DateTimeImmutable($enviada),
            cambio: $cambio,
        ));

        return $id;
    }

    /**
     * El seguro y la habilitación de una solicitud, como los deja el formulario (EF-07.4). Los
     * archivos no hacen falta: la bandeja muestra el enlace, no el PDF.
     *
     * @return array<string, string> tipo => id del documento
     */
    private function conDocumentos(string $solicitud, string $venceSeguro = '+1 year'): array
    {
        $ids = [];
        foreach (['seguro' => $venceSeguro, 'habilitacion' => '+2 years'] as $tipo => $vence) {
            $ids[$tipo] = Uuid::nuevo();
            $this->base->ejecutar(
                "INSERT INTO documento (id, sala_id, solicitud_id, tipo, archivo, vence_en, subido_en)
                 VALUES (?, ?, ?, ?, '0123456789abcdef0123456789abcdef.pdf', ?, NOW())",
                [$ids[$tipo], $this->salaId($solicitud), $solicitud, $tipo, new DateTimeImmutable('today')->modify($vence)->format('Y-m-d')],
            );
        }

        return $ids;
    }

    /**
     * @return array<string, string> tipo => estado
     */
    private function estadosDeLosDocumentos(string $solicitud): array
    {
        return array_column($this->base->filas('SELECT tipo, estado FROM documento WHERE solicitud_id = ?', [$solicitud]), 'estado', 'tipo');
    }

    private function estado(string $id): string
    {
        return (string) $this->base->valor('SELECT estado FROM solicitud_sala WHERE id = ?', [$id]);
    }

    private function salaId(string $id): string
    {
        return (string) $this->base->valor('SELECT sala_id FROM solicitud_sala WHERE id = ?', [$id]);
    }

    /**
     * @return list<string> los nombres de la lista de la bandeja, en orden
     */
    private static function nombres(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        preg_match_all('#class="bandeja-item-sala">([^<]*)</span>#', $respuesta->cuerpo(), $nombres);

        return array_map(html_entity_decode(...), $nombres[1]);
    }

    /**
     * @return list<array{para: string, asunto: string, texto: string}> los correos que salieron, ya decodificados
     */
    private static function correos(): array
    {
        return array_map(static function (array $correo): array {
            [$cabeceras, $cuerpo] = explode("\r\n\r\n", $correo['mensaje'], 2);
            $asunto = iconv_mime_decode_headers($cabeceras, 0, 'UTF-8')['Subject'] ?? '';

            return [
                'para' => implode(',', $correo['para']),
                'asunto' => is_array($asunto) ? implode(' ', $asunto) : (string) $asunto,
                'texto' => (string) base64_decode(str_replace("\r\n", '', $cuerpo), true),
            ];
        }, self::$correo->correos());
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testLasPendientesVanPrimeroLaQueMasEsperoYSeEligeEsa(): void
    {
        $this->solicitar(self::ESPECTADORA, 'La Nueva', '2026-10-10 10:00:00');
        $vieja = $this->solicitar(self::ORGANIZADOR, 'La Vieja', '2026-09-01 10:00:00');

        $respuesta = $this->pedir('GET', '/solicitudes');

        self::assertSame(['La Vieja', 'La Nueva'], self::nombres($respuesta));
        self::assertStringContainsString('<h2 id="titulo-solicitud" class="bandeja-detalle-titulo">La Vieja</h2>', $respuesta->cuerpo());
        self::assertStringContainsString("href=\"/moderacion/solicitudes?id={$vieja}\" class=\"bandeja-item activo\"", $respuesta->cuerpo());
        self::assertStringContainsString("action=\"/moderacion/solicitudes/{$vieja}/resolver\"", $respuesta->cuerpo());
        // La pestaña dice cuántas esperan.
        self::assertMatchesRegularExpression('#Solicitudes de sala <span class="bandeja-cuenta">2</span>#', $respuesta->cuerpo());
        // El menú del panel lleva a esta bandeja.
        self::assertStringContainsString('<a href="/moderacion/solicitudes" class="panel-menu-enlace activo"', $respuesta->cuerpo());
    }

    /**
     * El panel saca el encabezado del sitio, pero no el enlace: pasa de largo la barra lateral.
     */
    public function testElPanelTambienEmpiezaConSaltarAlContenido(): void
    {
        $this->solicitar(self::ESPECTADORA, 'El Galpón');

        self::assertSaltaAlContenido($this->pedir('GET', '/solicitudes')->cuerpo());
    }

    public function testLaElegidaMuestraLaSalaPropuestaYLoQueCuentaCuentasDeQuienLaMando(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $cuerpo = $this->pedir('GET', "/solicitudes?id={$id}")->cuerpo();

        self::assertStringContainsString('Calle 1, Luján', $cuerpo);
        self::assertStringContainsString('40 lugares declarados', $cuerpo);
        self::assertStringContainsString('Un galpón con proyector.', $cuerpo);
        self::assertStringContainsString("src=\"/moderacion/solicitudes/{$id}/foto\"", $cuerpo);
        self::assertStringContainsString('Eva Prueba · <a href="mailto:eva@ejemplo.com">eva@ejemplo.com</a>', $cuerpo);
        self::assertStringContainsString('<dd>08/03/2025</dd>', $cuerpo);
        self::assertStringContainsString('Espectador: aprobarla lo hace organizador', $cuerpo);
    }

    /**
     * Una que se mudó de programación no tiene foto propia: se ve la de su sala allá, que el
     * administrador puede ver aunque no esté publicada.
     */
    public function testUnaMudadaMuestraLaFotoDeSuSala(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón', conFoto: false);

        self::assertStringContainsString('src="/salas/' . $this->salaId($id) . '/imagen"', $this->pedir('GET', "/solicitudes?id={$id}")->cuerpo());
    }

    public function testSiCuentasNoRespondeLaBandejaSeVeIgual(): void
    {
        $id = $this->solicitar(self::LENTO, 'El Galpón');

        $respuesta = $this->pedir('GET', "/solicitudes?id={$id}");

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('No pudimos consultar los datos de la cuenta.', $respuesta->cuerpo());
        // El nombre que guardó la solicitud igual se ve.
        self::assertStringContainsString('<dd>Eva Prueba</dd>', $respuesta->cuerpo());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function busquedas(): iterable
    {
        yield 'el nombre de la sala' => ['galp', ['El Galpón']];
        yield 'la localidad, sin tildes' => ['MERCEDES', ['La Terraza']];
        yield 'quien la mandó' => ['omar', ['La Terraza']];
        yield 'un comodín, tal cual' => ['%', []];
        yield 'una inyección' => ["' OR '1'='1", []];
    }

    /**
     * @param list<string> $esperadas
     */
    #[DataProvider('busquedas')]
    public function testElBuscadorFiltraPorSalaLocalidadYSolicitante(string $texto, array $esperadas): void
    {
        $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $this->solicitar(self::ORGANIZADOR, 'La Terraza', localidad: 'Mercedes', nombre: 'Omar Prueba');

        self::assertSame($esperadas, self::nombres($this->pedir('GET', '/solicitudes?q=' . rawurlencode($texto))));
    }

    /**
     * Aprobar le da el rol en cuentas, crea la sala en programación con el id que guardó la
     * solicitud, los datos propuestos y la foto, y recién ahí la deja aprobada.
     */
    public function testAprobarDaElRolYCreaLaSalaEnProgramacion(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $respuesta = $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/solicitudes?aprobada={$id}", $respuesta->cabecera('Location'));
        self::assertSame('aprobada', $this->estado($id));
        self::assertSame([['usuario' => self::ESPECTADORA, 'rol' => 'organizador', 'otorgado_por' => self::ADMINISTRADOR]], self::pedidosA('cuentas'));
        $foto = (string) $this->base->valor('SELECT imagen FROM solicitud_sala WHERE id = ?', [$id]);
        self::assertSame([[
            'ruta' => 'crear',
            'id' => $this->salaId($id),
            'organizador_id' => self::ESPECTADORA,
            'nombre' => 'El Galpón',
            'descripcion' => 'Un galpón con proyector.',
            'direccion' => 'Calle 1',
            'localidad' => 'Luján',
            'capacidad' => '40',
            'peliculas_por_funcion' => '2',
            'duracion_funcion' => '120',
            'tiempo_entre_funciones' => '15',
            // EF-07.6: con la solicitud, programación reconoce el reintento.
            'solicitud_id' => $id,
            'foto' => sha1_file("{$this->carpeta}/solicitudes/{$foto}"),
        ]], self::pedidosA('programacion'));
        self::assertStringContainsString(self::ADMINISTRADOR . " resolvió la solicitud de sala {$id}: aprobada.", $this->log());
        self::assertStringContainsString('Aprobaste la solicitud de «El Galpón»', $this->pedir('GET', "/solicitudes?aprobada={$id}")->cuerpo());
    }

    /**
     * Una que se mudó de programación ya tiene su sala allá, con su foto: va sin foto, y
     * programación la completa con los datos que se corrigieron acá.
     */
    public function testAprobarUnaMudadaCompletaSuSalaSinMandarFoto(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón', conFoto: false);

        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());

        $pedidos = self::pedidosA('programacion');
        self::assertCount(1, $pedidos);
        self::assertSame($this->salaId($id), $pedidos[0]['id']);
        self::assertSame('40', (string) $pedidos[0]['capacidad']);
        self::assertNull($pedidos[0]['foto']);
        self::assertSame('aprobada', $this->estado($id));
    }

    public function testRechazarGuardaElMotivoYNoTocaProgramacion(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'Falta la habilitación municipal.']);

        self::assertSame('rechazada', $this->estado($id));
        self::assertSame([], self::pedidosA('cuentas'));
        self::assertSame([], self::pedidosA('programacion'));
        self::assertSame('Falta la habilitación municipal.', $this->base->valor('SELECT motivo FROM solicitud_sala WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function resolucionesIncompletas(): iterable
    {
        yield 'sin motivo' => [['resolucion' => 'aprobar', 'motivo' => '   '], 'Escribí el motivo'];
        yield 'motivo de más' => [['resolucion' => 'aprobar', 'motivo' => str_repeat('a', 1001)], 'hasta 1000 caracteres'];
        yield 'revisión sin motivo' => [['resolucion' => 'revision', 'motivo' => ''], 'Escribí el motivo'];
        yield 'sin resolución' => [['motivo' => 'Todo en orden.'], 'Elegí si la aprobás, la rechazás o la pasás a revisión manual.'];
        yield 'otra resolución' => [['resolucion' => 'aprobar como VIP', 'motivo' => 'Todo en orden.'], 'Elegí si la aprobás, la rechazás o la pasás a revisión manual.'];
    }

    /**
     * @param array<string, string> $campos
     */
    #[DataProvider('resolucionesIncompletas')]
    public function testSinMotivoOSinResolucionNoSeResuelve(array $campos, string $error): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $respuesta = $this->resolver($id, $campos);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString($error, $respuesta->cuerpo());
        self::assertSame('pendiente', $this->estado($id));
        self::assertSame([], self::pedidosA('cuentas'));
    }

    /**
     * EF-07.6: quien la mandó se entera por correo, con el motivo. La dirección la da cuentas.
     */
    public function testAprobarLeMandaUnCorreoAQuienLaMando(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

        $correos = self::correos();
        self::assertCount(1, $correos);
        self::assertSame('<eva@ejemplo.com>', $correos[0]['para']);
        self::assertSame('Aprobamos tu sala «El Galpón»', $correos[0]['asunto']);
        self::assertStringContainsString('Todo en orden.', $correos[0]['texto']);
        self::assertStringContainsString('/solicitar-sala', $correos[0]['texto']);
    }

    public function testRechazarLeMandaElMotivoYDondeCorregirla(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'Falta la habilitación.']);

        $correos = self::correos();
        self::assertCount(1, $correos);
        self::assertSame('<eva@ejemplo.com>', $correos[0]['para']);
        self::assertSame('Rechazamos la solicitud de «El Galpón»', $correos[0]['asunto']);
        self::assertStringContainsString('Falta la habilitación.', $correos[0]['texto']);
        self::assertStringContainsString('corregirla y volver a mandarla en /solicitar-sala', $correos[0]['texto']);
    }

    /**
     * El correo es el aviso: lo que decidió el moderador no depende de él.
     */
    public function testSiCuentasNoRespondeElRechazoQuedaYElLogLoDice(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        self::tirar('cuentas');

        $respuesta = $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'Falta la habilitación.']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('rechazada', $this->estado($id));
        self::assertSame([], self::correos());
        self::assertStringContainsString("La solicitud de sala {$id} quedó rechazada, pero no se le avisó a quien la mandó", $this->log());
    }

    public function testSiFallaElCorreoLaAprobacionQueda(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        // En el puerto 1 no escucha nadie.
        $this->servidorDeCorreo = 'smtp://127.0.0.1:1';

        $respuesta = $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('aprobada', $this->estado($id));
        self::assertStringContainsString("La solicitud de sala {$id} quedó aprobada, pero no se le avisó a quien la mandó", $this->log());
    }

    /**
     * EF-07.6: pasarla a revisión manual (para coordinar una visita, por ejemplo) guarda el motivo
     * aparte de la resolución y la deja abierta, junto a las pendientes. Quien la mandó recibe el
     * correo. Ni cuentas ni programación se enteran.
     */
    public function testPasarARevisionManualGuardaElMotivoAparteYLaDejaAbierta(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $respuesta = $this->resolver($id, ['resolucion' => 'revision', 'motivo' => 'Coordinamos una visita al espacio.']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/solicitudes?en_revision={$id}", $respuesta->cabecera('Location'));
        self::assertSame(
            ['estado' => 'en_revision_manual', 'revision_motivo' => 'Coordinamos una visita al espacio.', 'revision_por' => self::ADMINISTRADOR, 'motivo' => null, 'resuelta_en' => null],
            $this->base->fila('SELECT estado, revision_motivo, revision_por, motivo, resuelta_en FROM solicitud_sala WHERE id = ?', [$id]),
        );
        self::assertSame([], self::pedidosA('cuentas'));
        self::assertSame([], self::pedidosA('programacion'));
        $correos = self::correos();
        self::assertCount(1, $correos);
        self::assertSame('<eva@ejemplo.com>', $correos[0]['para']);
        self::assertSame('Tu solicitud de «El Galpón» pasó a revisión manual', $correos[0]['asunto']);
        self::assertStringContainsString('Coordinamos una visita al espacio.', $correos[0]['texto']);
        self::assertStringContainsString('/solicitar-sala', $correos[0]['texto']);
        self::assertStringContainsString(self::ADMINISTRADOR . " resolvió la solicitud de sala {$id}: en_revision_manual.", $this->log());

        $pantalla = $this->pedir('GET', "/solicitudes?en_revision={$id}")->cuerpo();
        self::assertStringContainsString('Pasaste «El Galpón» a revisión manual', $pantalla);
        self::assertSame(['El Galpón'], self::nombres($this->pedir('GET', '/solicitudes')));
        self::assertStringContainsString('En revisión manual', $pantalla);
        self::assertStringContainsString('Coordinamos una visita al espacio.', $pantalla);
        // Sigue en las abiertas, y se cierra después con la misma resolución.
        self::assertStringContainsString("/solicitudes/{$id}/resolver", $pantalla);
        self::assertStringNotContainsString('value="revision"', $pantalla);
        self::assertSame([], self::nombres($this->pedir('GET', '/solicitudes?ver=historial')));
    }

    public function testUnaEnRevisionManualSeApruebaOSeRechazaYConservaElMotivoDeLaRevision(): void
    {
        $aprobada = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $rechazada = $this->solicitar(self::ORGANIZADOR, 'La Terraza');
        foreach ([$aprobada, $rechazada] as $id) {
            $this->resolver($id, ['resolucion' => 'revision', 'motivo' => 'Coordinamos una visita.']);
        }

        self::assertSame(303, $this->resolver($aprobada, ['resolucion' => 'aprobar', 'motivo' => 'La visita salió bien.'])->estado());
        self::assertSame(303, $this->resolver($rechazada, ['resolucion' => 'rechazar', 'motivo' => 'No coincide con las fotos.'])->estado());

        self::assertSame(
            [
                ['estado' => 'aprobada', 'revision_motivo' => 'Coordinamos una visita.', 'motivo' => 'La visita salió bien.'],
                ['estado' => 'rechazada', 'revision_motivo' => 'Coordinamos una visita.', 'motivo' => 'No coincide con las fotos.'],
            ],
            $this->base->filas('SELECT estado, revision_motivo, motivo FROM solicitud_sala ORDER BY estado'),
        );
        self::assertSame([$this->salaId($aprobada)], array_keys(self::salasDeProgramacion()));
        $pantalla = $this->pedir('GET', "/solicitudes?id={$aprobada}")->cuerpo();
        self::assertStringContainsString('Coordinamos una visita.', $pantalla);
        self::assertStringContainsString('La visita salió bien.', $pantalla);
    }

    /**
     * Sólo una pendiente pasa a revisión manual: la resuelta ya se resolvió, y la que está en
     * revisión se aprueba o se rechaza.
     */
    public function testNoPasaARevisionUnaResueltaNiUnaQueYaEsta(): void
    {
        $resuelta = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $this->resolver($resuelta, ['resolucion' => 'rechazar', 'motivo' => 'Falta la póliza.']);
        $enRevision = $this->solicitar(self::ORGANIZADOR, 'La Terraza');
        $this->resolver($enRevision, ['resolucion' => 'revision', 'motivo' => 'Coordinamos una visita.']);
        self::$correo->vaciar();

        self::assertSame("/moderacion/solicitudes?ya_resuelta={$resuelta}", $this->resolver($resuelta, ['resolucion' => 'revision', 'motivo' => 'Otra visita.'])->cabecera('Location'));
        $otraVez = $this->resolver($enRevision, ['resolucion' => 'revision', 'motivo' => 'Otra visita.']);
        self::assertSame(422, $otraVez->estado());
        self::assertStringContainsString('Ya está en revisión manual: aprobala o rechazala.', $otraVez->cuerpo());

        self::assertSame('rechazada', $this->estado($resuelta));
        self::assertNull($this->base->valor('SELECT revision_motivo FROM solicitud_sala WHERE id = ?', [$resuelta]));
        self::assertSame('Coordinamos una visita.', $this->base->valor('SELECT revision_motivo FROM solicitud_sala WHERE id = ?', [$enRevision]));
        self::assertSame([], self::correos());
    }

    /**
     * EF-07.6: el pedido de cambio de dirección de una sala publicada, con la dirección que tiene
     * ahora según programación al lado de la nueva.
     */
    private function pedirCambio(): string
    {
        $sala = Uuid::nuevo();
        self::salasEnProgramacion([$sala => [
            'nombre' => 'El Galpón', 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADOR, 'habilitada' => true,
            'descripcion' => 'Un galpón con proyector.', 'direccion' => 'Av. San Martín 450', 'capacidad' => 40,
            'peliculas_por_funcion' => 2, 'duracion_funcion' => 120, 'tiempo_entre_funciones' => 15,
        ]]);

        return $this->solicitar(self::ORGANIZADOR, 'El Galpón', localidad: 'Mercedes', conFoto: false, salaId: $sala, cambio: true);
    }

    public function testUnCambioDeDireccionSeVeConSuMarcaYLaDireccionActual(): void
    {
        $id = $this->pedirCambio();

        $pantalla = $this->pedir('GET', "/solicitudes?id={$id}")->cuerpo();

        self::assertSame(2, substr_count($pantalla, 'Cambio de dirección'), 'En la lista y en la elegida.');
        self::assertStringContainsString('Calle 1, Mercedes', $pantalla);
        self::assertStringContainsString('Ahora: Av. San Martín 450, Luján', $pantalla);
    }

    public function testAprobarUnCambioActualizaLaSalaYRechazarloNoLaToca(): void
    {
        $rechazado = $this->pedirCambio();
        $sala = $this->salaId($rechazado);
        self::assertSame(303, $this->resolver($rechazado, ['resolucion' => 'rechazar', 'motivo' => 'Falta la habilitación del lugar nuevo.'])->estado());
        self::assertSame([], self::pedidosA('programacion'));

        $aprobado = $this->solicitar(self::ORGANIZADOR, 'El Galpón', localidad: 'Mercedes', conFoto: false, salaId: $sala, cambio: true);
        self::assertSame(303, $this->resolver($aprobado, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());

        $pedidos = self::pedidosA('programacion');
        self::assertCount(1, $pedidos);
        self::assertSame([$sala, $aprobado, 'Calle 1', 'Mercedes', null], [$pedidos[0]['id'], $pedidos[0]['solicitud_id'], $pedidos[0]['direccion'], $pedidos[0]['localidad'], $pedidos[0]['foto']]);
        self::assertSame('Calle 1', self::salasDeProgramacion()[$sala]['direccion']);
    }

    public function testSiCuentasNoRespondeNoSeApruebaNiSeCreaLaSala(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        self::tirar('cuentas');

        $respuesta = $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

        self::assertSame(503, $respuesta->estado());
        self::assertSame('pendiente', $this->estado($id));
        self::assertSame([], self::pedidosA('programacion'));
    }

    /**
     * Cuentas ya dio el rol y programación no respondió: la solicitud sigue pendiente, y el
     * reintento, con programación de pie, la aprueba con la misma sala.
     */
    public function testSiProgramacionNoRespondeSigueEnPendienteYSeReintenta(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        self::tirar('programacion');

        self::assertSame(503, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());
        self::assertSame('pendiente', $this->estado($id));

        self::reiniciarServidores();
        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());
        self::assertSame('aprobada', $this->estado($id));
        self::assertSame([$this->salaId($id)], array_keys(self::salasDeProgramacion()));
    }

    public function testUnaYaResueltaNoSeVuelveAResolver(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'Falta la habilitación.']);

        $respuesta = $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Ahora sí.']);

        self::assertSame("/moderacion/solicitudes?ya_resuelta={$id}", $respuesta->cabecera('Location'));
        self::assertSame('rechazada', $this->estado($id));
        self::assertSame([], self::pedidosA('cuentas'));
        self::assertSame([], self::pedidosA('programacion'));
    }

    public function testElHistorialListaLasResueltasConSuMotivo(): void
    {
        $pendiente = $this->solicitar(self::ESPECTADORA, 'Pendiente');
        $rechazada = $this->solicitar(self::ORGANIZADOR, 'Rechazada');
        $this->resolver($rechazada, ['resolucion' => 'rechazar', 'motivo' => 'Falta <b>la póliza</b>.']);

        $historial = $this->pedir('GET', '/solicitudes?ver=historial');

        self::assertSame(['Rechazada'], self::nombres($historial));
        self::assertStringContainsString('Falta &lt;b&gt;la póliza&lt;/b&gt;.', $historial->cuerpo());
        self::assertStringNotContainsString('/resolver"', $historial->cuerpo());
        self::assertSame(['Pendiente'], self::nombres($this->pedir('GET', '/solicitudes')));
        // Elegir una resuelta abre el historial; una pendiente, la bandeja.
        self::assertSame(['Rechazada'], self::nombres($this->pedir('GET', "/solicitudes?id={$rechazada}")));
        self::assertSame(['Pendiente'], self::nombres($this->pedir('GET', "/solicitudes?id={$pendiente}")));
    }

    public function testLaElegidaMuestraLaDocumentacionConSusEnlaces(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $documentos = $this->conDocumentos($id);

        $cuerpo = $this->pedir('GET', "/solicitudes?id={$id}", 'moderador')->cuerpo();

        self::assertStringContainsString('Documentación', $cuerpo);
        self::assertStringContainsString("href=\"/moderacion/documentos/{$documentos['seguro']}\"", $cuerpo);
        self::assertStringContainsString("href=\"/moderacion/documentos/{$documentos['habilitacion']}\"", $cuerpo);
        self::assertStringContainsString('Vence el ' . new DateTimeImmutable('today')->modify('+1 year')->format('d/m/Y'), $cuerpo);
        self::assertStringContainsString('Vence el ' . new DateTimeImmutable('today')->modify('+2 years')->format('d/m/Y'), $cuerpo);
        self::assertStringNotContainsString('Sin documentación', $cuerpo);
    }

    /**
     * Grilling de EF-07, Q6: no hay un paso aparte para los documentos. Aprobar la solicitud los
     * acepta y rechazarla los rechaza.
     */
    public function testAprobarAceptaLosDocumentosYRechazarLosRechaza(): void
    {
        $aprobada = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $rechazada = $this->solicitar(self::ORGANIZADOR, 'La Terraza');
        $this->conDocumentos($aprobada);
        $this->conDocumentos($rechazada);

        self::assertSame(303, $this->resolver($aprobada, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'], 'moderador')->estado());
        self::assertSame(303, $this->resolver($rechazada, ['resolucion' => 'rechazar', 'motivo' => 'La póliza no cubre el espacio.'], 'moderador')->estado());

        self::assertSame(['seguro' => 'aceptado', 'habilitacion' => 'aceptado'], $this->estadosDeLosDocumentos($aprobada));
        self::assertSame(['seguro' => 'rechazado', 'habilitacion' => 'rechazado'], $this->estadosDeLosDocumentos($rechazada));
        self::assertStringContainsString('Aceptado', $this->pedir('GET', "/solicitudes?id={$aprobada}", 'moderador')->cuerpo());
        self::assertStringContainsString('Rechazado', $this->pedir('GET', "/solicitudes?id={$rechazada}", 'moderador')->cuerpo());
    }

    /**
     * Una que no se pudo aprobar (programación no respondió) sigue pendiente, y sus documentos
     * también.
     */
    public function testSiNoSeApruebaLosDocumentosSiguenPendientes(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $this->conDocumentos($id);
        self::tirar('programacion');

        $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

        self::assertSame(['seguro' => 'pendiente', 'habilitacion' => 'pendiente'], $this->estadosDeLosDocumentos($id));
    }

    public function testUnaSinDocumentacionSeVeConElAvisoYSeResuelveIgual(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        self::assertStringContainsString('Sin documentación: se mandó antes de que se pidiera.', $this->pedir('GET', "/solicitudes?id={$id}")->cuerpo());
        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());
        self::assertSame('aprobada', $this->estado($id));
    }

    public function testUnDocumentoVencidoSeVeComoVencido(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $this->conDocumentos($id, '-1 day');

        $cuerpo = $this->pedir('GET', "/solicitudes?id={$id}")->cuerpo();

        self::assertStringContainsString('Venció el ' . new DateTimeImmutable('today')->modify('-1 day')->format('d/m/Y'), $cuerpo);
        self::assertStringContainsString('Vence el ' . new DateTimeImmutable('today')->modify('+2 years')->format('d/m/Y'), $cuerpo);
    }

    /**
     * Una aprobada, con su sala ya publicada en programación.
     */
    private function aprobada(string $sala = 'El Galpón'): string
    {
        $id = $this->solicitar(self::ORGANIZADOR, $sala);
        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());
        file_put_contents(self::$registros['programacion'], '');

        return $id;
    }

    /**
     * @param array<string, string> $campos
     */
    private function cambiarLaSala(string $id, string $accion, array $campos = ['motivo_sala' => 'Venció la póliza del seguro.'], ?string $rol = 'administrador'): Respuesta
    {
        return $this->pedir('POST', "/solicitudes/{$id}/{$accion}", $rol, $campos + ['_csrf' => self::CSRF]);
    }

    /**
     * EF-07.2: el estado de la sala lo cuenta programación, y retirarla o volver a publicarla se le
     * pide por la API interna, con un motivo que queda en su registro con el administrador.
     */
    public function testLaSalaDeUnaAprobadaSeRetiraYSeVuelveAPublicarConMotivo(): void
    {
        $id = $this->aprobada();
        $sala = $this->salaId($id);
        self::assertStringContainsString('Publicada: se ve en el listado de salas', $this->pedir('GET', "/solicitudes?id={$id}")->cuerpo());

        $retirar = $this->cambiarLaSala($id, 'deshabilitar-sala');

        self::assertSame(303, $retirar->estado(), $retirar->cuerpo());
        self::assertSame([['ruta' => 'habilitacion', 'sala' => $sala, 'habilitada' => false, 'motivo' => 'Venció la póliza del seguro.', 'moderador_id' => self::ADMINISTRADOR]], self::pedidosA('programacion'));
        // nginx le saca el prefijo antes de pasársela a moderación.
        $pantalla = $this->pedir('GET', substr((string) $retirar->cabecera('Location'), strlen('/moderacion')))->cuerpo();
        self::assertStringContainsString('Retiraste «El Galpón» del sitio', $pantalla);
        self::assertStringContainsString('Retirada: no se ve en el sitio.', $pantalla);
        self::assertStringContainsString('Volver a publicar', $pantalla);

        self::assertSame(303, $this->cambiarLaSala($id, 'habilitar-sala', ['motivo_sala' => 'Mandó la póliza nueva.'])->estado());
        self::assertTrue(self::salasDeProgramacion()[$sala]['habilitada']);
    }

    /**
     * @return list<array<string, mixed>> las suspensiones de la sala, de la primera a la última
     */
    private function suspensiones(string $sala): array
    {
        return $this->base->filas(
            'SELECT causa, tipo_documento, motivo, abierta_por, levantada_en IS NOT NULL AS levantada, levantada_por FROM suspension WHERE sala_id = ? ORDER BY desde, id',
            [$sala],
        );
    }

    /**
     * EF-07.5 (grilling de EF-07, Q2 y Q13): retirar la sala es una suspensión con causa «retiro»,
     * que volver a publicarla levanta.
     */
    public function testRetirarAbreUnaSuspensionYVolverAPublicarLaLevanta(): void
    {
        $id = $this->aprobada();
        $sala = $this->salaId($id);

        self::assertSame(303, $this->cambiarLaSala($id, 'deshabilitar-sala', rol: 'moderador')->estado());
        self::assertSame(
            [['causa' => 'retiro', 'tipo_documento' => null, 'motivo' => 'Venció la póliza del seguro.', 'abierta_por' => self::MODERADORA, 'levantada' => 0, 'levantada_por' => null]],
            $this->suspensiones($sala),
        );

        self::assertSame(303, $this->cambiarLaSala($id, 'habilitar-sala', ['motivo_sala' => 'Mandó la póliza nueva.'])->estado());
        self::assertSame(1, $this->suspensiones($sala)[0]['levantada']);
        self::assertSame(self::ADMINISTRADOR, $this->suspensiones($sala)[0]['levantada_por']);
        self::assertTrue(self::salasDeProgramacion()[$sala]['habilitada']);
    }

    /**
     * Una sala con otra suspensión activa (acá, el seguro vencido) sigue apagada aunque se levante
     * el retiro: volver a publicarla no deshace la otra causa.
     */
    public function testConOtraSuspensionVolverAPublicarNoLaPublica(): void
    {
        $id = $this->aprobada();
        $sala = $this->salaId($id);
        self::assertSame(303, $this->cambiarLaSala($id, 'deshabilitar-sala')->estado());
        $this->base->ejecutar(
            "INSERT INTO suspension (id, sala_id, causa, tipo_documento, motivo, desde) VALUES (?, ?, 'vencimiento', 'seguro', 'Venció el seguro.', UTC_TIMESTAMP() + INTERVAL 1 SECOND)",
            [Uuid::nuevo(), $sala],
        );
        file_put_contents(self::$registros['programacion'], '');

        self::assertSame(303, $this->cambiarLaSala($id, 'habilitar-sala', ['motivo_sala' => 'Se resolvió el reclamo.'])->estado());

        self::assertSame([], self::pedidosA('programacion'));
        self::assertFalse(self::salasDeProgramacion()[$sala]['habilitada']);
        self::assertSame([1, 0], array_column($this->suspensiones($sala), 'levantada'));
    }

    public function testSiProgramacionNoRespondeNoSeAbreLaSuspension(): void
    {
        $id = $this->aprobada();
        self::tirar('programacion');

        self::assertSame(503, $this->cambiarLaSala($id, 'deshabilitar-sala')->estado());

        self::assertSame([], $this->suspensiones($this->salaId($id)));
    }

    public function testSinMotivoNoSeRetira(): void
    {
        $id = $this->aprobada();

        $respuesta = $this->cambiarLaSala($id, 'deshabilitar-sala', ['motivo_sala' => '  ']);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Escribí por qué: queda en el registro.', $respuesta->cuerpo());
        self::assertSame([], self::pedidosA('programacion'));
    }

    public function testSoloSeRetiraLaSalaDeUnaAprobada(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        self::assertSame(404, $this->cambiarLaSala($id, 'habilitar-sala')->estado());
        self::assertSame([], self::pedidosA('programacion'));
    }

    /**
     * Sin programación, la bandeja se ve igual y las pendientes se resuelven: sólo falta el estado
     * de las salas, y sin él no se ofrece publicarlas ni retirarlas.
     */
    public function testSiProgramacionNoRespondeLaBandejaSeVeSinElEstadoDeLaSala(): void
    {
        $id = $this->aprobada();
        $pendiente = $this->solicitar(self::ESPECTADORA, 'La Terraza');
        self::tirar('programacion');

        $respuesta = $this->pedir('GET', "/solicitudes?id={$id}");

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('No pudimos consultar si está publicada.', $respuesta->cuerpo());
        self::assertDoesNotMatchRegularExpression('#/(des)?habilitar-sala"#', $respuesta->cuerpo());
        self::assertSame(303, $this->resolver($pendiente, ['resolucion' => 'rechazar', 'motivo' => 'Falta la póliza.'])->estado());
    }

    public function testUnaSalaQueSuOrganizadorDioDeBajaSeMuestraAsi(): void
    {
        $id = $this->aprobada();
        // Programación no devuelve las dadas de baja.
        self::salasEnProgramacion([]);

        $respuesta = $this->pedir('GET', "/solicitudes?id={$id}");

        self::assertStringContainsString('Su organizador la dio de baja.', $respuesta->cuerpo());
        self::assertDoesNotMatchRegularExpression('#/(des)?habilitar-sala"#', $respuesta->cuerpo());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsQueNoExisten(): iterable
    {
        yield 'un id al azar' => ['0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f'];
        yield 'un id que no es UUID' => ['1'];
    }

    #[DataProvider('idsQueNoExisten')]
    public function testUnaQueNoExisteDa404(string $id): void
    {
        self::assertSame(404, $this->pedir('GET', "/solicitudes?id={$id}")->estado());
        self::assertSame(404, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());
        self::assertSame(404, $this->pedir('GET', '/solicitudes?id[]=1')->estado());
    }

    public function testSinElTokenCsrfNoSeResuelve(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $respuesta = $this->pedir('POST', "/solicitudes/{$id}/resolver", cuerpo: ['_csrf' => str_repeat('d', 64), 'resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

        self::assertSame(403, $respuesta->estado());
        self::assertSame('pendiente', $this->estado($id));
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function quienNoEntra(): iterable
    {
        yield 'sin sesión' => [null, 401];
        yield 'espectador' => ['espectador', 403];
        yield 'organizador' => ['organizador', 403];
    }

    /**
     * EF-07.3: la bandeja es del moderador; el administrador también entra, porque lo alcanza.
     */
    #[DataProvider('quienNoEntra')]
    public function testSoloEntranElModeradorYElAdministrador(?string $rol, int $estado): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $aprobada = $this->aprobada('La Terraza');

        self::assertSame($estado, $this->pedir('GET', '/solicitudes', $rol)->estado());
        self::assertSame($estado, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'], $rol)->estado());
        self::assertSame($estado, $this->cambiarLaSala($aprobada, 'deshabilitar-sala', rol: $rol)->estado());
        self::assertSame($estado, $this->cambiarLaSala($aprobada, 'habilitar-sala', rol: $rol)->estado());
        self::assertSame('pendiente', $this->estado($id));
        self::assertSame([], self::pedidosA('programacion'));
    }

    /**
     * El moderador resuelve como el administrador, y queda él como quien resolvió: en la solicitud,
     * en el rol que da cuentas y en el retiro que registra programación.
     */
    public function testElModeradorAtiendeLaBandeja(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $rechazada = $this->solicitar(self::ORGANIZADOR, 'La Terraza', '2026-10-02 10:00:00');

        self::assertSame(['El Galpón', 'La Terraza'], self::nombres($this->pedir('GET', '/solicitudes', 'moderador')));
        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'], 'moderador')->estado());
        self::assertSame(303, $this->resolver($rechazada, ['resolucion' => 'rechazar', 'motivo' => 'Falta la póliza.'], 'moderador')->estado());

        self::assertSame(['aprobada', 'rechazada'], [$this->estado($id), $this->estado($rechazada)]);
        self::assertSame([['resuelta_por' => self::MODERADORA]], $this->base->filas('SELECT DISTINCT resuelta_por FROM solicitud_sala'));
        self::assertSame(self::MODERADORA, self::pedidosA('cuentas')[0]['otorgado_por']);
        file_put_contents(self::$registros['programacion'], '');

        self::assertSame(303, $this->cambiarLaSala($id, 'deshabilitar-sala', rol: 'moderador')->estado());
        self::assertSame(self::MODERADORA, self::pedidosA('programacion')[0]['moderador_id']);
    }

    /**
     * EF-07.3 (grilling de IC-549, Q1): el moderador que pidió una sala no la resuelve: la ve sin
     * los formularios, y pedirlo igual da 403 sin tocar nada. La resuelve otro moderador o el
     * administrador.
     */
    public function testElModeradorNoResuelveSuPropiaSolicitud(): void
    {
        $id = $this->solicitar(self::MODERADORA, 'La Propia');

        $pantalla = $this->pedir('GET', "/solicitudes?id={$id}", 'moderador')->cuerpo();
        self::assertStringNotContainsString("/solicitudes/{$id}/resolver", $pantalla);
        self::assertStringContainsString('La mandaste vos: la resuelve otro moderador.', $pantalla);

        foreach (['aprobar', 'rechazar', 'revision'] as $resolucion) {
            self::assertSame(403, $this->resolver($id, ['resolucion' => $resolucion, 'motivo' => 'Todo en orden.'], 'moderador')->estado());
        }
        self::assertSame('pendiente', $this->estado($id));
        self::assertSame([], self::pedidosA('cuentas'));
        self::assertSame([], self::pedidosA('programacion'));

        // Otro moderador sí.
        self::assertSame(303, $this->pedir('POST', "/solicitudes/{$id}/resolver", 'moderador', ['resolucion' => 'rechazar', 'motivo' => 'Falta la póliza.', '_csrf' => self::CSRF], self::ESPECTADORA)->estado());
        self::assertSame('rechazada', $this->estado($id));
    }

    public function testElModeradorNoRetiraNiVuelveAPublicarSuPropiaSala(): void
    {
        $id = $this->solicitar(self::MODERADORA, 'La Propia');
        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());
        file_put_contents(self::$registros['programacion'], '');

        $pantalla = $this->pedir('GET', "/solicitudes?id={$id}", 'moderador')->cuerpo();
        self::assertDoesNotMatchRegularExpression('#/(des)?habilitar-sala"#', $pantalla);

        self::assertSame(403, $this->cambiarLaSala($id, 'deshabilitar-sala', rol: 'moderador')->estado());
        self::assertSame(403, $this->cambiarLaSala($id, 'habilitar-sala', rol: 'moderador')->estado());
        self::assertSame([], self::pedidosA('programacion'));
    }

    public function testElAdministradorResuelveLaDeUnModerador(): void
    {
        $id = $this->solicitar(self::MODERADORA, 'La Propia');

        self::assertStringContainsString("/solicitudes/{$id}/resolver", $this->pedir('GET', "/solicitudes?id={$id}")->cuerpo());
        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());
        self::assertSame('aprobada', $this->estado($id));
        self::assertSame(303, $this->cambiarLaSala($id, 'deshabilitar-sala')->estado());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function barras(): iterable
    {
        // El logo y las secciones que puede abrir: al moderador, sólo la bandeja.
        yield 'moderador' => ['moderador', ['/moderacion/documentos', '/moderacion/solicitudes']];
        yield 'administrador' => ['administrador', ['/cuenta/administracion/usuarios', '/funcion/administracion/reservas', '/moderacion/documentos', '/moderacion/solicitudes']];
    }

    /**
     * @param list<string> $enlaces
     */
    #[DataProvider('barras')]
    public function testLaBarraLateralSoloTieneLoQuePuedeAbrir(string $rol, array $enlaces): void
    {
        $cuerpo = $this->pedir('GET', '/solicitudes', $rol)->cuerpo();

        self::assertSame(1, preg_match('#<aside class="panel-menu">.*?</aside>#s', $cuerpo, $barra));
        preg_match_all('#href="([^"]*)"#', $barra[0], $hrefs);
        $unicos = array_values(array_unique($hrefs[1]));
        sort($unicos);
        self::assertSame($enlaces, $unicos);
        self::assertStringContainsString("rol-{$rol}", $barra[0]);
    }
}
