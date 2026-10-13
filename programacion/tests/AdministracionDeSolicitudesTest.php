<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeSolicitudes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La bandeja de solicitudes de sala de punta a punta: la aplicación entera con las rutas de
 * programación, las plantillas del paquete front, un MySQL de verdad y una cuentas de mentira
 * (Dobles/cuentas.php, con `php -S`) a la que se le piden los datos de quien la mandó y el rol.
 * En desarrollo, para que Twig falle si la plantilla usa una variable que el controlador no le pasa.
 */
final class AdministracionDeSolicitudesTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ADMINISTRADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    // Los usuarios que conoce Dobles/cuentas.php.
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const LENTO = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    /** @var resource|null */
    private static $servidor = null;

    private static string $cuentas;
    private static string $registro;

    private BaseDeDatos $base;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::$registro = (string) tempnam(sys_get_temp_dir(), 'roles');
        // Un puerto libre: el sistema da uno al pedir el 0, y se suelta para que lo use php -S.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$cuentas = "http://127.0.0.1:{$puerto}";

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/cuentas.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            ['REGISTRO' => self::$registro] + getenv(),
        );
        self::$servidor = $proceso === false ? null : $proceso;

        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail('No arrancó la cuentas de prueba.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
        @unlink(self::$registro);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$registro, '');
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    /**
     * @param ?string $cuentas por defecto, la de mentira
     */
    private function configuracion(?string $cuentas = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => sys_get_temp_dir() . '/sin-uso'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => $cuentas ?? self::$cuentas],
            'interno' => ['token' => 'token-de-programacion'],
            // La bandeja no manda correos: en el puerto 1 no escucha nadie.
            'correo' => ['servidor' => 'smtp://127.0.0.1:1', 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => 'equipo@indiecinema.test'],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $url, ?string $rol = 'administrador', array $cuerpo = [], ?Configuracion $configuracion = null): Respuesta
    {
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => self::ADMINISTRADOR, 'X-Rol' => $rol];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion ?? $this->configuracion(), new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF]));
    }

    /**
     * @param array<string, string> $campos
     */
    private function resolver(string $id, array $campos, ?string $rol = 'administrador', ?Configuracion $configuracion = null): Respuesta
    {
        return $this->pedir('POST', "/administracion/solicitudes/{$id}/resolver", $rol, $campos + ['_csrf' => self::CSRF], $configuracion);
    }

    /**
     * Una sala sin habilitar con su solicitud pendiente, como la deja el formulario.
     */
    private function solicitar(string $usuario, string $sala, string $enviada = '2026-10-01 10:00:00', string $localidad = 'Luján', ?string $nombre = 'Eva Prueba'): string
    {
        $salaNueva = new Sala(Uuid::nuevo(), $usuario, new DatosDeSala($sala, 'Un galpón con proyector.', 'Calle 1', $localidad, 40, 2, 120, 15), 'foto.png');
        (new RepositorioDeSalas($this->base))->agregar($salaNueva);
        $id = Uuid::nuevo();
        (new RepositorioDeSolicitudes($this->base))->agregar($id, $salaNueva->id, $usuario, $nombre, new DateTimeImmutable($enviada));

        return $id;
    }

    private function estado(string $id): string
    {
        return (string) $this->base->valor('SELECT estado FROM solicitud_sala WHERE id = ?', [$id]);
    }

    private function salaHabilitada(string $id): bool
    {
        return (bool) $this->base->valor('SELECT sa.habilitada FROM solicitud_sala so JOIN sala sa ON sa.id = so.sala_id WHERE so.id = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>> los roles que se le pidieron a cuentas
     */
    private static function rolesPedidos(): array
    {
        return array_map(static fn (string $linea): array => (array) json_decode($linea, true), array_values(array_filter(explode("\n", (string) file_get_contents(self::$registro)))));
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

        $respuesta = $this->pedir('GET', '/administracion/solicitudes');

        self::assertSame(['La Vieja', 'La Nueva'], self::nombres($respuesta));
        self::assertStringContainsString('<h2 id="titulo-solicitud" class="bandeja-detalle-titulo">La Vieja</h2>', $respuesta->cuerpo());
        self::assertStringContainsString("href=\"/administracion/solicitudes?id={$vieja}\" class=\"bandeja-item activo\"", $respuesta->cuerpo());
        self::assertStringContainsString("action=\"/administracion/solicitudes/{$vieja}/resolver\"", $respuesta->cuerpo());
        // La pestaña dice cuántas esperan.
        self::assertMatchesRegularExpression('#Solicitudes de sala <span class="bandeja-cuenta">2</span>#', $respuesta->cuerpo());
    }

    public function testLaElegidaMuestraLaSalaYLoQueCuentaCuentasDeQuienLaMando(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $cuerpo = $this->pedir('GET', "/administracion/solicitudes?id={$id}")->cuerpo();

        self::assertStringContainsString('Calle 1, Luján', $cuerpo);
        self::assertStringContainsString('40 lugares declarados', $cuerpo);
        self::assertStringContainsString('Un galpón con proyector.', $cuerpo);
        self::assertStringContainsString('Eva Prueba · <a href="mailto:eva@ejemplo.com">eva@ejemplo.com</a>', $cuerpo);
        self::assertStringContainsString('<dd>08/03/2025</dd>', $cuerpo);
        self::assertStringContainsString('Espectador: aprobarla lo hace organizador', $cuerpo);
    }

    public function testSiCuentasNoRespondeLaBandejaSeVeIgual(): void
    {
        $id = $this->solicitar(self::LENTO, 'El Galpón');

        $respuesta = $this->pedir('GET', "/administracion/solicitudes?id={$id}");

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

        self::assertSame($esperadas, self::nombres($this->pedir('GET', '/administracion/solicitudes?q=' . rawurlencode($texto))));
    }

    public function testAprobarPideElRolACuentasYPublicaLaSala(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $respuesta = $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/administracion/solicitudes?aprobada={$id}", $respuesta->cabecera('Location'));
        self::assertSame('aprobada', $this->estado($id));
        self::assertTrue($this->salaHabilitada($id));
        self::assertSame([['usuario' => self::ESPECTADORA, 'rol' => 'organizador', 'otorgado_por' => self::ADMINISTRADOR]], self::rolesPedidos());
        self::assertStringContainsString(self::ADMINISTRADOR . " resolvió la solicitud de sala {$id}: aprobada.", $this->log());
        self::assertStringContainsString('Aprobaste la solicitud de «El Galpón»', $this->pedir('GET', "/administracion/solicitudes?aprobada={$id}")->cuerpo());
    }

    public function testRechazarGuardaElMotivoYLaSalaSigueSinPublicar(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'Falta la habilitación municipal.']);

        self::assertSame('rechazada', $this->estado($id));
        self::assertFalse($this->salaHabilitada($id));
        self::assertSame([], self::rolesPedidos());
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
        self::assertSame([], self::rolesPedidos());
    }

    public function testSiCuentasNoRespondeNoSeApruebaNada(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        // En el puerto 1 no escucha nadie.
        $respuesta = $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'], configuracion: $this->configuracion('http://127.0.0.1:1'));

        self::assertSame(503, $respuesta->estado());
        self::assertSame('pendiente', $this->estado($id));
        self::assertFalse($this->salaHabilitada($id));
    }

    public function testUnaYaResueltaNoSeVuelveAResolver(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');
        $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'Falta la habilitación.']);

        $respuesta = $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Ahora sí.']);

        self::assertSame("/administracion/solicitudes?ya_resuelta={$id}", $respuesta->cabecera('Location'));
        self::assertSame('rechazada', $this->estado($id));
        self::assertFalse($this->salaHabilitada($id));
        self::assertSame([], self::rolesPedidos());
    }

    public function testElHistorialListaLasResueltasConSuMotivo(): void
    {
        $pendiente = $this->solicitar(self::ESPECTADORA, 'Pendiente');
        $rechazada = $this->solicitar(self::ORGANIZADOR, 'Rechazada');
        $this->resolver($rechazada, ['resolucion' => 'rechazar', 'motivo' => 'Falta <b>la póliza</b>.']);

        $historial = $this->pedir('GET', '/administracion/solicitudes?ver=historial');

        self::assertSame(['Rechazada'], self::nombres($historial));
        self::assertStringContainsString('Falta &lt;b&gt;la póliza&lt;/b&gt;.', $historial->cuerpo());
        self::assertStringNotContainsString('/resolver"', $historial->cuerpo());
        self::assertSame(['Pendiente'], self::nombres($this->pedir('GET', '/administracion/solicitudes')));
        // Elegir una resuelta abre el historial; una pendiente, la bandeja.
        self::assertSame(['Rechazada'], self::nombres($this->pedir('GET', "/administracion/solicitudes?id={$rechazada}")));
        self::assertSame(['Pendiente'], self::nombres($this->pedir('GET', "/administracion/solicitudes?id={$pendiente}")));
    }

    public function testLaSalaDeUnaAprobadaSeRetiraYSeVuelveAPublicar(): void
    {
        $id = $this->solicitar(self::ORGANIZADOR, 'El Galpón');
        $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

        $retirar = $this->pedir('POST', "/administracion/solicitudes/{$id}/deshabilitar-sala", cuerpo: ['_csrf' => self::CSRF]);
        self::assertSame(303, $retirar->estado());
        self::assertFalse($this->salaHabilitada($id));
        self::assertStringContainsString('Volver a publicar', $this->pedir('GET', (string) $retirar->cabecera('Location'))->cuerpo());

        $this->pedir('POST', "/administracion/solicitudes/{$id}/habilitar-sala", cuerpo: ['_csrf' => self::CSRF]);
        self::assertTrue($this->salaHabilitada($id));
        self::assertStringContainsString(self::ADMINISTRADOR . ' deshabilitó la sala', $this->log());
    }

    public function testSoloSeRetiraLaSalaDeUnaAprobada(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        self::assertSame(404, $this->pedir('POST', "/administracion/solicitudes/{$id}/habilitar-sala", cuerpo: ['_csrf' => self::CSRF])->estado());
        self::assertFalse($this->salaHabilitada($id));
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
        self::assertSame(404, $this->pedir('GET', "/administracion/solicitudes?id={$id}")->estado());
        self::assertSame(404, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'])->estado());
        self::assertSame(404, $this->pedir('GET', '/administracion/solicitudes?id[]=1')->estado());
    }

    public function testSinElTokenCsrfNoSeResuelve(): void
    {
        $id = $this->solicitar(self::ESPECTADORA, 'El Galpón');

        $respuesta = $this->pedir('POST', "/administracion/solicitudes/{$id}/resolver", cuerpo: ['_csrf' => str_repeat('d', 64), 'resolucion' => 'aprobar', 'motivo' => 'Todo en orden.']);

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

        self::assertSame($estado, $this->pedir('GET', '/administracion/solicitudes', $rol)->estado());
        self::assertSame($estado, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Todo en orden.'], $rol)->estado());
        self::assertSame('pendiente', $this->estado($id));
    }
}
