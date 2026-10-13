<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use Dom\HTMLDocument;
use IndieCinema\Cuentas\Controladores\FormularioDeSolicitud;
use IndieCinema\Cuentas\Pruebas\Controladores\FormularioDeSolicitudTest;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La solicitud para ser organizador de punta a punta: la aplicación entera con las rutas de
 * cuentas, la plantilla del paquete front, un MySQL de verdad con las tablas de las migraciones
 * y un servidor SMTP de mentira, que recibe el correo al equipo. En desarrollo, para que Twig
 * falle si la plantilla usa una variable que el controlador no le pasa.
 */
final class SolicitudDeOrganizadorTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const URL = '/solicitud';
    private const ESPECTADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const EQUIPO = 'equipo@indiecinema.test';

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::$correo = ServidorSmtpDePrueba::iniciar();
    }

    public static function tearDownAfterClass(): void
    {
        self::$correo->detener();
    }

    protected function setUp(): void
    {
        self::$correo->vaciar();
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->base->ejecutar(
            "INSERT INTO usuario (id, nombre, correo, contrasena_hash) VALUES (?, 'Eva Prueba', 'eva@ejemplo.com', 'sin uso')",
            [self::ESPECTADOR],
        );
    }

    /**
     * @param ?string $servidorDeCorreo por defecto, el de mentira
     */
    private function configuracion(?string $servidorDeCorreo = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'correo' => ['servidor' => $servidorDeCorreo ?? self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => self::EQUIPO],
        ]);
    }

    /**
     * Como llega de nginx: sin el prefijo y, con un rol, con las cabeceras de la sesión.
     *
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo = 'GET', array $cuerpo = [], ?string $rol = 'espectador', string $url = self::URL, ?Configuracion $configuracion = null): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $configuracion ??= $this->configuracion();
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => self::ESPECTADOR,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Eva Prueba'),
        ];
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('cuentas', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF]));
    }

    /**
     * @param array<string, string> $cambios
     */
    private function enviar(array $cambios = [], ?string $rol = 'espectador'): Respuesta
    {
        return $this->pedir('POST', $cambios + ['_csrf' => self::CSRF] + FormularioDeSolicitudTest::camposValidos(), $rol);
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testMuestraElFormularioConElToken(): void
    {
        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('<form action="/cuenta/solicitud" method="post">', $respuesta->cuerpo());
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $respuesta->cuerpo());
        self::assertStringNotContainsString('No se pudo enviar la solicitud', $respuesta->cuerpo());
        self::assertDoesNotMatchRegularExpression('/name="(habilitacion|derechos)"[^>]*checked/', $respuesta->cuerpo());
    }

    /**
     * El navegador pide lo mismo que el backend: cada atributo sale de FormularioDeSolicitud::REGLAS
     * y el formulario no lleva novalidate.
     */
    public function testElNavegadorValidaConLasReglasDelBackend(): void
    {
        $documento = HTMLDocument::createFromString($this->pedir()->cuerpo(), LIBXML_NOERROR);
        $formulario = $documento->querySelector('form[action="/cuenta/solicitud"]');
        self::assertNotNull($formulario);
        self::assertFalse($formulario->hasAttribute('novalidate'));

        foreach (FormularioDeSolicitud::REGLAS as $campo => $reglas) {
            $control = $formulario->querySelector("[name=\"{$campo}\"]");
            self::assertNotNull($control, $campo);
            self::assertSame($reglas['requerido'], $control->hasAttribute('required'), $campo);
            self::assertSame((string) $reglas['maximo'], $control->getAttribute('maxlength'), $campo);
            self::assertSame(isset($reglas['minimo']) ? (string) $reglas['minimo'] : null, $control->getAttribute('minlength'), $campo);
            self::assertSame($reglas['patron'] ?? null, $control->getAttribute('pattern'), $campo);
        }
        self::assertSame('email', $formulario->querySelector('[name="correo"]')?->getAttribute('type'));
        foreach (array_keys(FormularioDeSolicitud::DECLARACIONES) as $casilla) {
            self::assertTrue($formulario->querySelector("input[type=\"checkbox\"][name=\"{$casilla}\"]")?->hasAttribute('required'), $casilla);
        }
    }

    /**
     * Con la validación del navegador apagada, o con el POST armado a mano, el servidor rechaza
     * los mismos datos y lo dice junto a cada campo.
     */
    #[DataProvider('camposInvalidos')]
    public function testElServidorRechazaLoMismoQueElNavegador(string $campo, string $valor, string $mensaje): void
    {
        $respuesta = $this->enviar([$campo => $valor]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Revisá los campos marcados.', $respuesta->cuerpo());
        self::assertMatchesRegularExpression('#id="error-' . $campo . '"[^>]*>.*' . preg_quote($mensaje, '#') . '#s', $respuesta->cuerpo());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function camposInvalidos(): iterable
    {
        return FormularioDeSolicitudTest::camposInvalidos();
    }

    public function testConErroresVuelveConLoCargadoYEscapado(): void
    {
        $respuesta = $this->enviar(['sala' => '<script>alert(1)</script>', 'correo' => 'galpon@', 'derechos' => '']);
        $cuerpo = $respuesta->cuerpo();

        self::assertSame(422, $respuesta->estado());
        // Lo que escribió vuelve escapado: Twig no deja pasar HTML.
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $cuerpo);
        self::assertStringNotContainsString('<script>alert(1)', $cuerpo);
        self::assertStringContainsString('value="galpon@"', $cuerpo);
        self::assertStringContainsString('Galpón ferroviario reciclado', $cuerpo);
        self::assertMatchesRegularExpression('/name="habilitacion"[^>]*checked/', $cuerpo);
        self::assertDoesNotMatchRegularExpression('/name="derechos"[^>]*checked/', $cuerpo);
    }

    public function testSinElTokenCsrfSeRechaza(): void
    {
        self::assertSame(403, $this->enviar(['_csrf' => str_repeat('d', 64)])->estado());
        self::assertSame(403, $this->pedir('POST', FormularioDeSolicitudTest::camposValidos())->estado());
    }

    public function testSinSesionHayQueIngresar(): void
    {
        self::assertSame(401, $this->pedir(rol: null)->estado());
        self::assertSame(401, $this->enviar(rol: null)->estado());
    }

    public function testConTodoBienVuelveConElAviso(): void
    {
        $respuesta = $this->enviar();

        // 303: recargar no vuelve a mandar la solicitud.
        self::assertSame(303, $respuesta->estado());
        self::assertSame('/cuenta/solicitud?enviada=1', $respuesta->cabecera('Location'));
        // Le llegó al equipo, a la dirección de la configuración.
        $correos = self::$correo->correos();
        self::assertCount(1, $correos);
        self::assertSame(['<' . self::EQUIPO . '>'], $correos[0]['para']);
        // Y quedó guardada, pendiente, con lo que mandó.
        $guardada = $this->base->fila('SELECT usuario_id, sala, localidad, correo, telefono, estado FROM solicitud');
        self::assertSame([
            'usuario_id' => self::ESPECTADOR,
            'sala' => 'El Galpón de la Estación',
            'localidad' => 'Luján',
            'correo' => 'galpon@ejemplo.com',
            'telefono' => '+54 2323 44-1234',
            'estado' => 'pendiente',
        ], $guardada);

        $aviso = $this->pedir(url: '/solicitud?enviada=1');
        self::assertSame(200, $aviso->estado());
        self::assertStringContainsString('Solicitud enviada', $aviso->cuerpo());
        self::assertStringNotContainsString('action="/cuenta/solicitud"', $aviso->cuerpo());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rolesQueYaPublicanSalas(): iterable
    {
        yield 'organizador' => ['organizador'];
        yield 'moderador' => ['moderador'];
        yield 'administrador' => ['administrador'];
    }

    #[DataProvider('rolesQueYaPublicanSalas')]
    public function testQuienYaEsOrganizadorNoTieneQuePedirlo(string $rol): void
    {
        $pantalla = $this->pedir(rol: $rol);

        self::assertSame(200, $pantalla->estado());
        self::assertStringContainsString('Ya sos organizador', $pantalla->cuerpo());
        self::assertStringNotContainsString('action="/cuenta/solicitud"', $pantalla->cuerpo());
        self::assertSame(409, $this->enviar(rol: $rol)->estado());
    }

    public function testMientrasEstaPendienteMuestraElEstadoYNoElFormulario(): void
    {
        $this->enviar();
        $pantalla = $this->pedir()->cuerpo();

        self::assertStringContainsString('En revisión', $pantalla);
        self::assertStringContainsString('«El Galpón de la Estación»', $pantalla);
        self::assertStringNotContainsString('action="/cuenta/solicitud"', $pantalla);
    }

    public function testNoSePuedeMandarOtraMientrasEstaPendiente(): void
    {
        $this->enviar();
        $otra = $this->enviar(['sala' => 'Otra sala']);

        self::assertSame(409, $otra->estado());
        self::assertStringContainsString('En revisión', $otra->cuerpo());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM solicitud'));
    }

    public function testSiLaRechazaronMuestraElMotivoYDejaMandarOtra(): void
    {
        $this->enviar();
        $this->base->ejecutar("UPDATE solicitud SET estado = 'rechazada', motivo = 'Falta <b>la habilitación</b>.', resuelta_en = NOW()");

        $pantalla = $this->pedir()->cuerpo();
        self::assertStringContainsString('no fue aprobada', $pantalla);
        self::assertStringContainsString('Falta &lt;b&gt;la habilitación&lt;/b&gt;.', $pantalla);
        self::assertStringContainsString('action="/cuenta/solicitud"', $pantalla);

        self::assertSame(303, $this->enviar()->estado());
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM solicitud'));
    }

    /**
     * El correo es el aviso: si no sale, la solicitud queda igual y el administrador la ve en su
     * bandeja.
     */
    public function testSiFallaElCorreoLaSolicitudQuedaGuardada(): void
    {
        // En el puerto 1 no escucha nadie.
        $respuesta = $this->pedir('POST', ['_csrf' => self::CSRF] + FormularioDeSolicitudTest::camposValidos(), configuracion: $this->configuracion('smtp://127.0.0.1:1'));

        self::assertSame(303, $respuesta->estado());
        self::assertSame(1, $this->base->valor("SELECT COUNT(*) FROM solicitud WHERE estado = 'pendiente'"));
        self::assertStringContainsString('quedó guardada, pero no se le avisó al equipo por correo', $this->log());
    }

    /**
     * Lo que le llega al equipo: la solicitud entera, y responder le escribe al contacto que dejó.
     */
    public function testElCorreoAlEquipoTraeLaSolicitudYSeRespondeAlContacto(): void
    {
        $this->enviar(['presentacion' => "Galpón ferroviario reciclado como espacio cultural.\nEntran 60 personas."]);

        $correos = self::$correo->correos();
        self::assertCount(1, $correos);
        [$crudas, $cuerpo] = explode("\r\n\r\n", $correos[0]['mensaje'], 2);
        $cabeceras = [];
        foreach (explode("\r\n", (string) preg_replace("/\r\n(?=[ \t])/", '', $crudas)) as $linea) {
            [$nombre, $valor] = explode(': ', $linea, 2);
            $cabeceras[$nombre] = mb_decode_mimeheader($valor);
        }
        $texto = (string) base64_decode(str_replace("\r\n", '', $cuerpo), true);

        self::assertSame('Solicitud para ser organizador: El Galpón de la Estación', $cabeceras['Subject']);
        self::assertSame('<galpon@ejemplo.com>', $cabeceras['Reply-To']);
        foreach ([
            'Eva Prueba pidió ser organizador',
            'Sala: El Galpón de la Estación',
            'Localidad: Luján',
            'Correo de contacto: galpon@ejemplo.com',
            'Teléfono: +54 2323 44-1234',
            "Galpón ferroviario reciclado como espacio cultural.\r\nEntran 60 personas.",
            '/cuenta/administracion/solicitudes',
        ] as $parte) {
            self::assertStringContainsString($parte, $texto);
        }
    }

    public function testSinTelefonoElCorreoLoDice(): void
    {
        $this->enviar(['telefono' => '']);

        $mensaje = self::$correo->correos()[0]['mensaje'];
        $texto = (string) base64_decode(str_replace("\r\n", '', explode("\r\n\r\n", $mensaje, 2)[1]), true);
        self::assertStringContainsString('Teléfono: no lo dejó', $texto);
        self::assertNull($this->base->valor('SELECT telefono FROM solicitud'));
    }

    /**
     * Con datos inválidos, sin el token o si ya es organizador, no se guarda ni se manda nada.
     */
    public function testSiNoPasaNoSeGuardaNiSeMandaNada(): void
    {
        $this->enviar(['correo' => 'galpon@']);
        $this->enviar(['_csrf' => str_repeat('d', 64)]);
        $this->enviar(rol: 'organizador');

        self::assertSame([], self::$correo->correos());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM solicitud'));
    }

    /**
     * El log registra quién la mandó por su id, sin los datos de contacto.
     */
    public function testElLogNoGuardaLosDatosDeContacto(): void
    {
        $this->enviar();

        self::assertStringContainsString('El usuario ' . self::ESPECTADOR . ' pidió ser organizador', $this->log());
        self::assertStringNotContainsString('galpon@ejemplo.com', $this->log());
        self::assertStringNotContainsString('2323', $this->log());
    }
}
