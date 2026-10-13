<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\DatosDeSolicitud;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSolicitudes;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * La bandeja de solicitudes del administrador de punta a punta: la aplicación entera con las
 * rutas de cuentas, la plantilla del paquete front y un MySQL de verdad. En desarrollo, para que
 * Twig falle si la plantilla usa una variable que el controlador no le pasa. Quién entra lo
 * prueba PanelDeAdministracionTest, que recorre todas las rutas del panel.
 */
final class AdministracionDeSolicitudesTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const URL = '/administracion/solicitudes';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeUsuarios $usuarios;
    private RepositorioDeSolicitudes $solicitudes;
    private string $administrador;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            // La bandeja no manda correos, pero el servicio de solicitudes los sabe mandar.
            'correo' => ['servidor' => 'smtp://127.0.0.1:1', 'remitente' => 'no-responder@indiecinema.test'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->usuarios = new RepositorioDeUsuarios($this->base);
        $this->solicitudes = new RepositorioDeSolicitudes($this->base);
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->administrador = $this->agregarUsuario('Ana Pérez', 'ana@ejemplo.com', [Rol::Administrador]);
    }

    /**
     * Como llega de nginx: sin el prefijo y con las cabeceras de la sesión del administrador.
     *
     * @param array<string, string> $cuerpo
     */
    private function pedir(string $url = self::URL, string $metodo = 'GET', array $cuerpo = []): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $cabeceras = ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $this->administrador, 'X-Rol' => 'administrador'];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF]));
    }

    private function resolver(string $id, string $accion, string $motivo = 'La habilitación está al día.'): Respuesta
    {
        return $this->pedir(self::URL . "/{$id}/{$accion}", 'POST', ['_csrf' => self::CSRF, 'motivo' => $motivo]);
    }

    /**
     * @param list<Rol> $roles además de espectador
     */
    private function agregarUsuario(string $nombre, string $correo, array $roles = []): string
    {
        $id = Uuid::nuevo();
        $this->usuarios->agregar(new Usuario($id, $nombre, $correo, 'hash', EstadoCuenta::Activa, [Rol::Espectador, ...$roles]));

        return $id;
    }

    private function agregarSolicitud(string $usuarioId, string $sala, string $enviadaEn, string $correo = 'contacto@ejemplo.com'): string
    {
        $id = Uuid::nuevo();
        $this->solicitudes->agregar($id, $usuarioId, new DatosDeSolicitud(
            $sala,
            'Luján',
            $correo,
            null,
            "Galpón reciclado como espacio cultural.\nEntran 60 personas.",
        ), new DateTimeImmutable($enviadaEn, new DateTimeZone('UTC')));

        return $id;
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testListaLasPendientesDeLaMasViejaALaMasNuevaYMuestraLaPrimera(): void
    {
        $this->agregarSolicitud($this->agregarUsuario('Eva', 'eva@ejemplo.com'), 'La Terraza', '2026-10-12 10:00');
        $this->agregarSolicitud($this->agregarUsuario('Juan', 'juan@ejemplo.com'), 'El Galpón', '2026-10-10 10:00');
        $resuelta = $this->agregarSolicitud($this->agregarUsuario('Sol', 'sol@ejemplo.com'), 'Sótano 9', '2026-10-01 10:00');
        $this->resolver($resuelta, 'rechazar');

        $respuesta = $this->pedir();
        $cuerpo = $respuesta->cuerpo();

        self::assertSame(200, $respuesta->estado());
        preg_match_all('#<span class="bandeja-item-sala">([^<]*)</span>#', $cuerpo, $salas);
        self::assertSame(['El Galpón', 'La Terraza'], $salas[1]);
        // La elegida es la que lleva más tiempo esperando, con su presentación y quién la mandó.
        self::assertStringContainsString('<h2 id="titulo-solicitud" class="bandeja-detalle-titulo">El Galpón</h2>', $cuerpo);
        self::assertStringContainsString("Galpón reciclado como espacio cultural.<br />\nEntran 60 personas.", $cuerpo);
        self::assertStringContainsString('Juan · juan@ejemplo.com', $cuerpo);
        self::assertStringContainsString('action="/cuenta/administracion/solicitudes/', $cuerpo);
    }

    public function testConElIdMuestraEsaYSiNoExisteDa404(): void
    {
        $this->agregarSolicitud($this->agregarUsuario('Juan', 'juan@ejemplo.com'), 'El Galpón', '2026-10-10 10:00');
        $terraza = $this->agregarSolicitud($this->agregarUsuario('Eva', 'eva@ejemplo.com'), 'La Terraza', '2026-10-12 10:00');

        self::assertStringContainsString('class="bandeja-detalle-titulo">La Terraza</h2>', $this->pedir(self::URL . "?id={$terraza}")->cuerpo());
        self::assertSame(404, $this->pedir(self::URL . '?id=' . Uuid::nuevo())->estado());
        self::assertSame(404, $this->pedir(self::URL . '?id=no-es-un-id')->estado());
    }

    public function testSinSolicitudesLoDice(): void
    {
        $cuerpo = $this->pedir()->cuerpo();

        self::assertStringContainsString('No hay solicitudes esperando.', $cuerpo);
        self::assertStringNotContainsString('bandeja-detalle', $cuerpo);
    }

    public function testAprobarlaLeDaElRolCierraSusSesionesYQuedaRegistrada(): void
    {
        $eva = $this->agregarUsuario('Eva', 'eva@ejemplo.com');
        $solicitud = $this->agregarSolicitud($eva, 'El Galpón', '2026-10-10 10:00');
        $this->base->ejecutar('INSERT INTO sesion (huella, usuario_id, creada_en, usada_en, vence_en) VALUES (?, ?, NOW(), NOW(), NOW() + INTERVAL 1 DAY)', [str_repeat('a', 64), $eva]);

        $respuesta = $this->resolver($solicitud, 'aprobar', 'Todo en orden.');

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/cuenta/administracion/solicitudes?aprobada={$solicitud}", $respuesta->cabecera('Location'));
        self::assertSame(
            ['estado' => 'aprobada', 'motivo' => 'Todo en orden.', 'resuelta_por' => $this->administrador],
            $this->base->fila('SELECT estado, motivo, resuelta_por FROM solicitud WHERE id = ?', [$solicitud]),
        );
        self::assertSame(['espectador', 'organizador'], array_column($this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ? ORDER BY tipo', [$eva]), 'tipo'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sesion WHERE usuario_id = ?', [$eva]));
        self::assertStringContainsString("resolvió la solicitud {$solicitud}: aprobada", $this->log());
        self::assertStringContainsString("le dio el rol de organizador a {$eva}", $this->log());

        $aviso = $this->pedir(self::URL . "?aprobada={$solicitud}")->cuerpo();
        self::assertStringContainsString('Aprobaste la solicitud de «El Galpón».', $aviso);
        self::assertStringContainsString('No hay solicitudes esperando.', $aviso);
    }

    public function testRechazarlaNoDaElRol(): void
    {
        $eva = $this->agregarUsuario('Eva', 'eva@ejemplo.com');
        $solicitud = $this->agregarSolicitud($eva, 'El Galpón', '2026-10-10 10:00');

        $respuesta = $this->resolver($solicitud, 'rechazar', 'Falta la habilitación municipal.');

        self::assertSame("/cuenta/administracion/solicitudes?rechazada={$solicitud}", $respuesta->cabecera('Location'));
        self::assertSame('rechazada', $this->base->valor('SELECT estado FROM solicitud WHERE id = ?', [$solicitud]));
        self::assertSame(['espectador'], array_column($this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ?', [$eva]), 'tipo'));

        // Ya resuelta, se ve con su motivo y sin el formulario.
        $detalle = $this->pedir(self::URL . "?id={$solicitud}")->cuerpo();
        self::assertStringContainsString('Falta la habilitación municipal.', $detalle);
        self::assertStringNotContainsString('name="motivo"', $detalle);
    }

    public function testSinMotivoNoSeResuelve(): void
    {
        $solicitud = $this->agregarSolicitud($this->agregarUsuario('Eva', 'eva@ejemplo.com'), 'El Galpón', '2026-10-10 10:00');

        $sinMotivo = $this->resolver($solicitud, 'aprobar', "  \n ");
        $largo = $this->resolver($solicitud, 'rechazar', str_repeat('a', 1001));
        // Como el maxlength del navegador, cada emoji cuenta dos: 501 son 1002.
        $conEmojis = $this->resolver($solicitud, 'rechazar', str_repeat('🎬', 501));

        self::assertSame(422, $sinMotivo->estado());
        self::assertMatchesRegularExpression('#id="error-motivo"[^>]*>.*Escribí el motivo#s', $sinMotivo->cuerpo());
        self::assertSame(422, $largo->estado());
        self::assertStringContainsString('El motivo puede tener hasta 1000 caracteres.', $largo->cuerpo());
        self::assertStringContainsString('maxlength="1000"', $largo->cuerpo());
        self::assertSame(422, $conEmojis->estado());
        self::assertStringContainsString('El motivo puede tener hasta 1000 caracteres.', $conEmojis->cuerpo());
        self::assertSame('pendiente', $this->base->valor('SELECT estado FROM solicitud WHERE id = ?', [$solicitud]));
    }

    public function testUnaYaResueltaNoCambia(): void
    {
        $solicitud = $this->agregarSolicitud($this->agregarUsuario('Eva', 'eva@ejemplo.com'), 'El Galpón', '2026-10-10 10:00');
        $this->resolver($solicitud, 'aprobar', 'Todo en orden.');

        $otra = $this->resolver($solicitud, 'rechazar', 'Me arrepentí.');

        self::assertSame("/cuenta/administracion/solicitudes?ya_resuelta={$solicitud}", $otra->cabecera('Location'));
        self::assertSame(['estado' => 'aprobada', 'motivo' => 'Todo en orden.'], $this->base->fila('SELECT estado, motivo FROM solicitud WHERE id = ?', [$solicitud]));
        self::assertStringContainsString('ya estaba resuelta', $this->pedir(self::URL . "?ya_resuelta={$solicitud}")->cuerpo());
    }

    public function testUnaQueNoExisteDa404(): void
    {
        self::assertSame(404, $this->resolver(Uuid::nuevo(), 'aprobar')->estado());
    }

    public function testSinElTokenCsrfNoSeResuelve(): void
    {
        $solicitud = $this->agregarSolicitud($this->agregarUsuario('Eva', 'eva@ejemplo.com'), 'El Galpón', '2026-10-10 10:00');

        $respuesta = $this->pedir(self::URL . "/{$solicitud}/aprobar", 'POST', ['motivo' => 'Todo en orden.']);

        self::assertSame(403, $respuesta->estado());
        self::assertSame('pendiente', $this->base->valor('SELECT estado FROM solicitud WHERE id = ?', [$solicitud]));
    }

    public function testLoQueEscribioElEspectadorSeMuestraEscapado(): void
    {
        $this->agregarSolicitud($this->agregarUsuario('<i>Eva</i>', 'eva@ejemplo.com'), '<script>alert(1)</script>', '2026-10-10 10:00');

        $cuerpo = $this->pedir()->cuerpo();

        self::assertStringNotContainsString('<script>alert(1)', $cuerpo);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $cuerpo);
        self::assertStringContainsString('&lt;i&gt;Eva&lt;/i&gt;', $cuerpo);
    }

    public function testElCorreoDeContactoVaCodificadoEnElEnlace(): void
    {
        // Es un correo válido para el navegador; sin codificar, el ? arrancaría los parámetros del
        // mailto: y le agregaría una copia al borrador del administrador.
        $this->agregarSolicitud($this->agregarUsuario('Eva', 'eva@ejemplo.com'), 'El Galpón', '2026-10-10 10:00', 'ana?cc=otro@ejemplo.com');

        $cuerpo = $this->pedir()->cuerpo();

        self::assertStringContainsString('href="mailto:ana%3Fcc%3Dotro@ejemplo.com"', $cuerpo);
        self::assertStringNotContainsString('mailto:ana?', $cuerpo);
    }
}
