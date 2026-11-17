<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use DateTimeImmutable;
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
 * La pestaña «Documentos» de la bandeja de moderación (EF-07.5), de punta a punta: las
 * renovaciones que suben los organizadores, que el moderador acepta o rechaza con un motivo. Con
 * la aplicación entera, un MySQL de verdad, una programación y una cuentas de mentira y un SMTP de
 * prueba, que recibe el correo al organizador.
 */
final class BandejaDeDocumentosTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const MODERADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const ADMINISTRADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    // El organizador que conoce Dobles/cuentas.php.
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;
    private string $sala;

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
        self::reiniciarServidores();
        self::$correo->vaciar();
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->sala = $this->sala('El Galpón', self::ORGANIZADOR, habilitada: false);
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => sys_get_temp_dir() . '/sin-archivos'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
        ]);
    }

    private function sala(string $nombre, string $organizador, bool $habilitada = true): string
    {
        $id = Uuid::nuevo();
        self::salasEnProgramacion(self::salasDeProgramacion() + [$id => ['nombre' => $nombre, 'localidad' => 'Luján', 'organizador_id' => $organizador, 'habilitada' => $habilitada]]);

        return $id;
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $url, ?string $rol = 'moderador', array $cuerpo = [], ?string $usuario = null): Respuesta
    {
        $usuario ??= $rol === 'administrador' ? self::ADMINISTRADOR : self::MODERADORA;
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('moderacion', fopen('php://memory', 'w')));

        return PaginasParaValidar::anotar($metodo, "/moderacion{$url}", $aplicacion->atender(new Peticion($metodo, $url, [], $cuerpo, $cabeceras, ['csrf' => self::CSRF])));
    }

    private function resolver(string $id, string $resolucion, string $motivo = 'El PDF se lee bien y está vigente.', ?string $rol = 'moderador', ?string $usuario = null): Respuesta
    {
        return $this->pedir('POST', "/documentos/{$id}/resolver", $rol, ['_csrf' => self::CSRF, 'resolucion' => $resolucion, 'motivo' => $motivo], $usuario);
    }

    /**
     * Una renovación pendiente, como la deja la documentación de la sala.
     */
    private function renovacion(string $tipo = 'seguro', ?string $sala = null, string $subido = '-1 day', ?string $solicitud = null): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO documento (id, sala_id, solicitud_id, tipo, archivo, vence_en, subido_en, estado)
             VALUES (?, ?, ?, ?, '0123456789abcdef0123456789abcdef.pdf', ?, ?, 'pendiente')",
            [$id, $sala ?? $this->sala, $solicitud, $tipo, new DateTimeImmutable('today')->modify('+1 year')->format('Y-m-d'), new DateTimeImmutable($subido)->format('Y-m-d H:i:s')],
        );

        return $id;
    }

    private function suspension(string $causa, ?string $tipo = null): void
    {
        $this->base->ejecutar(
            "INSERT INTO suspension (id, sala_id, causa, tipo_documento, motivo, desde) VALUES (?, ?, ?, ?, 'Un motivo.', UTC_TIMESTAMP())",
            [Uuid::nuevo(), $this->sala, $causa, $tipo],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function documento(string $id): array
    {
        return $this->base->fila('SELECT estado, motivo, resuelto_por, resuelto_en IS NOT NULL AS resuelto FROM documento WHERE id = ?', [$id]) ?? [];
    }

    private function activas(): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM suspension WHERE sala_id = ? AND levantada_en IS NULL', [$this->sala]);
    }

    /**
     * @return list<string> los asuntos de los correos que salieron
     */
    private static function asuntos(): array
    {
        return array_map(static function (array $correo): string {
            $asunto = iconv_mime_decode_headers(explode("\r\n\r\n", $correo['mensaje'], 2)[0], 0, 'UTF-8')['Subject'] ?? '';

            return is_array($asunto) ? implode(' ', $asunto) : (string) $asunto;
        }, self::$correo->correos());
    }

    public function testListaLasRenovacionesPendientesDeLaMasViejaALaMasNueva(): void
    {
        $nueva = $this->renovacion('habilitacion', subido: '-1 hour');
        $vieja = $this->renovacion('seguro', $this->sala('La Terraza', self::ORGANIZADOR), '-2 days');
        // La de una solicitud se resuelve con la solicitud, en su bandeja.
        $this->renovacion(solicitud: $this->solicitud());

        $cuerpo = $this->pedir('GET', '/documentos')->cuerpo();

        self::assertSame(2, substr_count($cuerpo, 'class="bandeja-documento"'));
        self::assertLessThan(strpos($cuerpo, $nueva), strpos($cuerpo, $vieja));
        self::assertStringContainsString('La Terraza', $cuerpo);
        self::assertStringContainsString("href=\"/moderacion/documentos/{$vieja}\"", $cuerpo);
        self::assertStringContainsString('vence el ' . new DateTimeImmutable('today')->modify('+1 year')->format('d/m/Y'), $cuerpo);
        self::assertStringContainsString("action=\"/moderacion/documentos/{$nueva}/resolver\"", $cuerpo);
        // La barra del moderador lleva a esta pestaña.
        self::assertStringContainsString('<a href="/moderacion/documentos" class="panel-menu-enlace activo"', $cuerpo);
    }

    /**
     * Grilling de EF-07, Q14: aceptar levanta la suspensión por vencimiento de ese papel; si era la
     * última, la sala vuelve a publicarse, y el organizador recibe el correo.
     */
    public function testAceptarLevantaLaSuspensionYPublicaLaSala(): void
    {
        $this->suspension('vencimiento', 'seguro');
        $id = $this->renovacion();

        $respuesta = $this->resolver($id, 'aceptar');

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('/moderacion/documentos?aceptado=' . $id, $respuesta->cabecera('Location'));
        self::assertSame(['estado' => 'aceptado', 'motivo' => 'El PDF se lee bien y está vigente.', 'resuelto_por' => self::MODERADORA, 'resuelto' => 1], $this->documento($id));
        self::assertSame(0, $this->activas());
        self::assertTrue(self::salasDeProgramacion()[$this->sala]['habilitada']);
        self::assertSame(['Aceptamos el seguro de «El Galpón»'], self::asuntos());
    }

    /**
     * Una sala retirada (o denunciada) sigue apagada: aceptar el papel sólo levanta su vencimiento.
     */
    public function testConOtraSuspensionAceptarNoPublicaLaSala(): void
    {
        $this->suspension('vencimiento', 'seguro');
        $this->suspension('retiro');
        $id = $this->renovacion();

        self::assertSame(303, $this->resolver($id, 'aceptar')->estado());

        self::assertSame(1, $this->activas());
        self::assertFalse(self::salasDeProgramacion()[$this->sala]['habilitada']);
        self::assertSame([], array_filter(self::pedidosA('programacion'), static fn (array $pedido): bool => ($pedido['ruta'] ?? '') === 'habilitacion'));
    }

    public function testRechazarGuardaElMotivoYLeAvisa(): void
    {
        $this->suspension('vencimiento', 'seguro');
        $id = $this->renovacion();

        self::assertSame(303, $this->resolver($id, 'rechazar', 'La póliza es de otra dirección.')->estado());

        self::assertSame(['estado' => 'rechazado', 'motivo' => 'La póliza es de otra dirección.', 'resuelto_por' => self::MODERADORA, 'resuelto' => 1], $this->documento($id));
        self::assertSame(1, $this->activas());
        self::assertSame(['Rechazamos el seguro de «El Galpón»'], self::asuntos());
    }

    public function testSiProgramacionNoRespondeElDocumentoSiguePendiente(): void
    {
        $this->suspension('vencimiento', 'seguro');
        $id = $this->renovacion();
        self::tirar('programacion');

        self::assertSame(503, $this->resolver($id, 'aceptar')->estado());

        self::assertSame('pendiente', $this->documento($id)['estado']);
        self::assertSame(1, $this->activas());
    }

    public function testSinMotivoOSinResolucionNoSeResuelve(): void
    {
        $id = $this->renovacion();

        self::assertSame(422, $this->resolver($id, 'aceptar', '  ')->estado());
        self::assertSame(422, $this->resolver($id, 'aprobar')->estado());

        self::assertSame('pendiente', $this->documento($id)['estado']);
    }

    public function testUnaYaResueltaOQueNoExisteDa404(): void
    {
        $id = $this->renovacion();
        $this->resolver($id, 'rechazar', 'No se lee.');

        self::assertSame(404, $this->resolver($id, 'aceptar')->estado());
        self::assertSame(404, $this->resolver(Uuid::nuevo(), 'aceptar')->estado());
        self::assertSame(404, $this->resolver('no-es-un-id', 'aceptar')->estado());
    }

    /**
     * Grilling de EF-07, Q1: el moderador no resuelve lo suyo; el administrador sí.
     */
    public function testElModeradorNoResuelveLaDeSuSala(): void
    {
        $propia = $this->renovacion(sala: $this->sala('La Propia', self::MODERADORA));

        self::assertSame(403, $this->resolver($propia, 'aceptar')->estado());
        self::assertSame('pendiente', $this->documento($propia)['estado']);
        self::assertSame(303, $this->resolver($propia, 'aceptar', rol: 'administrador')->estado());
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

    #[DataProvider('quienNoEntra')]
    public function testSoloEntranElModeradorYElAdministrador(?string $rol, int $estado): void
    {
        $id = $this->renovacion();

        self::assertSame($estado, $this->pedir('GET', '/documentos', $rol)->estado());
        self::assertSame($estado, $this->resolver($id, 'aceptar', rol: $rol)->estado());
        self::assertSame(200, $this->pedir('GET', '/documentos', 'administrador')->estado());
    }

    /**
     * Una solicitud pendiente cualquiera, para un documento que viene con ella.
     */
    private function solicitud(): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO solicitud_sala (id, sala_id, usuario_id, nombre, descripcion, direccion, localidad, capacidad,
                peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, enviada_en)
             VALUES (?, ?, ?, 'Otra', 'Una sala.', 'Calle 2', 'Luján', 30, 1, 90, 10, UTC_TIMESTAMP())",
            [$id, Uuid::nuevo(), self::ORGANIZADOR],
        );

        return $id;
    }
}
