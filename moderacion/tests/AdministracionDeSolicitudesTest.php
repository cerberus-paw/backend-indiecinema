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
    // Los usuarios que conoce Dobles/cuentas.php.
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const LENTO = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private BaseDeDatos $base;
    private string $carpeta;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::iniciarServidores();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerServidores();
    }

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        self::reiniciarServidores();
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
            // La bandeja no manda correos: en el puerto 1 no escucha nadie.
            'correo' => ['servidor' => 'smtp://127.0.0.1:1', 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => 'equipo@indiecinema.test'],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $url, ?string $rol = 'administrador', array $cuerpo = []): Respuesta
    {
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => self::ADMINISTRADOR, 'X-Rol' => $rol];
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
    private function solicitar(string $usuario, string $sala, string $enviada = '2026-10-01 10:00:00', string $localidad = 'Luján', ?string $nombre = 'Eva Prueba', bool $conFoto = true, ?string $salaId = null): string
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
        ));

        return $id;
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
        yield 'sin resolución' => [['motivo' => 'Todo en orden.'], 'Elegí si la aprobás o la rechazás.'];
        yield 'otra resolución' => [['resolucion' => 'aprobar como VIP', 'motivo' => 'Todo en orden.'], 'Elegí si la aprobás o la rechazás.'];
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
        yield 'moderador' => ['moderador', 403];
    }

    #[DataProvider('quienNoEntra')]
    public function testSoloEntraElAdministrador(?string $rol, int $estado): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        self::assertSame($estado, $this->pedir('GET', '/solicitudes', $rol)->estado());
        self::assertSame($estado, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'], $rol)->estado());
        self::assertSame('pendiente', $this->estado($id));
    }
}
