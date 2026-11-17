<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;
use IndieCinema\Cuentas\Repositorios\RepositorioDeTokens;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\CredencialesInvalidas;
use IndieCinema\Cuentas\Servicios\ServicioDeIngreso;
use IndieCinema\Cuentas\Servicios\ServicioDeRecuperacion;
use IndieCinema\Cuentas\Servicios\ServicioDeSesiones;
use IndieCinema\Cuentas\Servicios\ServicioDeTokens;
use IndieCinema\Cuentas\Servicios\TokenInvalido;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Formularios;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use PHPUnit\Framework\TestCase;

/**
 * Recuperación de contraseña (X-02.2 / IC-332): enlace con vencimiento de 1 hora, respuesta
 * uniforme para correos existentes e inexistentes, rechazo de enlaces usados/vencidos/de otro
 * propósito y cierre de todas las sesiones al cambiar la contraseña.
 */
final class RecuperacionDeContrasenaTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const CONTRASENA_INICIAL = 'butaca 2026';

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;
    private RepositorioDeUsuarios $usuarios;
    private ServicioDeSesiones $sesiones;
    private string $mariana;

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
        $this->usuarios = new RepositorioDeUsuarios($this->base);
        $this->sesiones = new ServicioDeSesiones(new RepositorioDeSesiones($this->base));
        $usuario = Usuario::nuevo('Mariana Rossi', 'mariana@ejemplo.com', password_hash(self::CONTRASENA_INICIAL, PASSWORD_ARGON2ID));
        $this->usuarios->agregar($usuario);
        $this->mariana = $usuario->id;
    }

    private function configuracion(?string $servidorDeCorreo = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'sitio' => ['url' => 'https://indiecinema.test'],
            'correo' => [
                'servidor' => $servidorDeCorreo ?? self::$correo->url(),
                'remitente' => 'no-responder@indiecinema.test',
                'nombre' => 'IndieCinema',
            ],
        ]);
    }

    /**
     * @param array<string, mixed>  $consulta
     * @param array<string, mixed>  $cuerpo
     * @param array<string, string> $cookies
     */
    private function pedir(
        string $metodo,
        string $ruta,
        array $consulta = [],
        array $cuerpo = [],
        array $cookies = [],
        ?Configuracion $configuracion = null,
    ): Respuesta {
        $aplicacion = new Aplicacion(
            dirname(__DIR__),
            $configuracion ?? $this->configuracion(),
            new Log('cuentas', $this->salidaDelLog),
        );

        return $aplicacion->atender(new Peticion($metodo, $ruta, $consulta, $cuerpo, [], $cookies + ['csrf' => self::CSRF]));
    }

    private static function textoDelCorreo(): string
    {
        $correos = self::$correo->correos();
        self::assertCount(1, $correos);

        return (string) base64_decode(str_replace("\r\n", '', explode("\r\n\r\n", $correos[0]['mensaje'], 2)[1]), true);
    }

    private static function extraerTokenDelCorreo(): string
    {
        $texto = self::textoDelCorreo();
        $coincidencias = [];
        self::assertSame(
            1,
            preg_match('#https://indiecinema\.test/cuenta/recuperar/nueva\?token=([0-9a-f]{64})#', $texto, $coincidencias),
            $texto,
        );

        return $coincidencias[1];
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testIngresarEnlazaARecuperarContrasena(): void
    {
        $respuesta = $this->pedir('GET', '/ingresar');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('href="/cuenta/recuperar"', $respuesta->cuerpo());
        self::assertStringContainsString('¿Te olvidaste la contraseña?', $respuesta->cuerpo());
    }

    public function testMuestraFormularioYValidaElCorreoSinEnviarNada(): void
    {
        $get = $this->pedir('GET', '/recuperar');
        self::assertSame(200, $get->estado());
        PaginasParaValidar::guardar('recuperar', $get->cuerpo());
        self::assertStringContainsString('action="/cuenta/recuperar"', $get->cuerpo());

        $invalido = $this->pedir('POST', '/recuperar', cuerpo: ['_csrf' => self::CSRF, 'email' => 'mariana@']);
        $invalido->terminar();
        self::assertSame(422, $invalido->estado());
        PaginasParaValidar::guardar('recuperar-con-errores', $invalido->cuerpo());
        self::assertStringContainsString('Escribí un correo válido', $invalido->cuerpo());
        self::assertCount(0, self::$correo->correos());
    }

    public function testRespondeIgualConCorreoRegistradoInexistenteOSuspendido(): void
    {
        $inexistente = $this->pedir('POST', '/recuperar', cuerpo: ['_csrf' => self::CSRF, 'email' => 'nadie@ejemplo.com']);
        $inexistente->terminar();
        self::assertSame(303, $inexistente->estado());
        self::assertSame('/cuenta/recuperar?enviada=1', $inexistente->cabecera('Location'));
        self::assertSame([], self::$correo->correos());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM token_de_cuenta'));

        $registrado = $this->pedir('POST', '/recuperar', cuerpo: ['_csrf' => self::CSRF, 'email' => 'Mariana@Ejemplo.COM']);
        $registrado->terminar();
        self::assertSame(303, $registrado->estado());
        self::assertSame('/cuenta/recuperar?enviada=1', $registrado->cabecera('Location'));

        $pantalla = $this->pedir('GET', '/recuperar', ['enviada' => '1']);
        self::assertSame(200, $pantalla->estado());
        PaginasParaValidar::guardar('recuperar-enviada', $pantalla->cuerpo());
        self::assertStringContainsString('Si el correo está registrado, te mandamos un enlace.', $pantalla->cuerpo());

        $correos = self::$correo->correos();
        self::assertCount(1, $correos);
        self::assertSame(['<mariana@ejemplo.com>'], $correos[0]['para']);

        $token = self::extraerTokenDelCorreo();
        self::assertSame(
            1,
            $this->base->valor(
                "SELECT COUNT(*) FROM token_de_cuenta WHERE huella = ? AND proposito = 'recuperar' AND vence_en = creado_en + INTERVAL 1 HOUR",
                [hash('sha256', $token)],
            ),
        );

        // Una cuenta suspendida recibe la misma respuesta pero no dispara correos.
        self::$correo->vaciar();
        $this->usuarios->cambiarEstado($this->mariana, EstadoCuenta::Suspendida);
        $suspendida = $this->pedir('POST', '/recuperar', cuerpo: ['_csrf' => self::CSRF, 'email' => 'mariana@ejemplo.com']);
        $suspendida->terminar();
        self::assertSame('/cuenta/recuperar?enviada=1', $suspendida->cabecera('Location'));
        self::assertSame([], self::$correo->correos());
    }

    public function testRestablecerCambiaLaContrasenaCierraTodasLasSesionesYNoSirveDosVeces(): void
    {
        $otra = Usuario::nuevo('Otro Usuario', 'otro@ejemplo.com', password_hash('otra clave 1', PASSWORD_ARGON2ID));
        $this->usuarios->agregar($otra);

        $sesionWeb = $this->sesiones->abrir($this->mariana)->identificador;
        $sesionMovil = $this->sesiones->abrir($this->mariana)->identificador;
        $sesionAjena = $this->sesiones->abrir($otra->id)->identificador;

        $solicitud = $this->pedir('POST', '/recuperar', cuerpo: ['_csrf' => self::CSRF, 'email' => 'mariana@ejemplo.com']);
        $solicitud->terminar();
        $token = self::extraerTokenDelCorreo();

        $formulario = $this->pedir('GET', '/recuperar/nueva', ['token' => $token]);
        self::assertSame(200, $formulario->estado());
        PaginasParaValidar::guardar('recuperar-nueva', $formulario->cuerpo());
        self::assertStringContainsString('action="/cuenta/recuperar/nueva"', $formulario->cuerpo());
        self::assertStringContainsString('name="token" value="' . $token . '"', $formulario->cuerpo());
        $autocompletado = Formularios::autocompletado($formulario->cuerpo());
        self::assertSame('new-password', $autocompletado['password']);
        self::assertSame('new-password', $autocompletado['password_confirmacion']);

        // Cambiar con contraseñas inválidas no consume el token ni cierra sesiones.
        $debil = $this->pedir('POST', '/recuperar/nueva', cuerpo: [
            '_csrf' => self::CSRF,
            'token' => $token,
            'password' => 'sololetras',
            'password_confirmacion' => 'distinta',
        ]);
        self::assertSame(422, $debil->estado());
        PaginasParaValidar::guardar('recuperar-nueva-errores', $debil->cuerpo());
        self::assertNotNull($this->sesiones->validar($sesionWeb));

        $cambio = $this->pedir(
            'POST',
            '/recuperar/nueva',
            cuerpo: [
                '_csrf' => self::CSRF,
                'token' => $token,
                'password' => 'butaca nueva 2027',
                'password_confirmacion' => 'butaca nueva 2027',
            ],
            cookies: ['sesion' => $sesionWeb],
        );
        self::assertSame(303, $cambio->estado());
        self::assertSame('/cuenta/ingresar?recuperada=1', $cambio->cabecera('Location'));

        $ingreso = new ServicioDeIngreso($this->usuarios, $this->sesiones);
        // La contraseña vieja deja de entrar y la nueva entra con Argon2id.
        try {
            $ingreso->ingresar('mariana@ejemplo.com', self::CONTRASENA_INICIAL, null);
            self::fail('La contraseña anterior siguió sirviendo.');
        } catch (CredencialesInvalidas) {
        }
        self::assertSame(64, strlen($ingreso->ingresar('mariana@ejemplo.com', 'butaca nueva 2027', null)->identificador));

        // Todas las sesiones abiertas de Mariana se cerraron; las ajenas siguen abiertas.
        self::assertNull($this->sesiones->validar($sesionWeb));
        self::assertNull($this->sesiones->validar($sesionMovil));
        self::assertNotNull($this->sesiones->validar($sesionAjena));

        $avisoIngreso = $this->pedir('GET', '/ingresar', ['recuperada' => '1']);
        self::assertSame(200, $avisoIngreso->estado());
        PaginasParaValidar::guardar('ingresar-recuperada', $avisoIngreso->cuerpo());
        self::assertStringContainsString('Ya podés iniciar sesión con tu nueva contraseña.', $avisoIngreso->cuerpo());

        // El enlace ya no sirve una segunda vez.
        $repetido = $this->pedir('GET', '/recuperar/nueva', ['token' => $token]);
        self::assertSame(410, $repetido->estado());
        PaginasParaValidar::guardar('recuperar-nueva-invalida', $repetido->cuerpo());
        self::assertStringContainsString('El enlace ya se usó.', $repetido->cuerpo());
    }

    public function testRechazaTokenVencidoInexistenteYDeOtroPropositoSinConsumirlo(): void
    {
        $tokens = new ServicioDeTokens(new RepositorioDeTokens($this->base), $this->usuarios);
        $inicio = new DateTimeImmutable('2026-11-16 12:00:00', new DateTimeZone('UTC'));
        $tokenPorVencer = $tokens->emitir($this->mariana, PropositoDeToken::Recuperar, ServicioDeRecuperacion::DURACION_RECUPERACION, $inicio);
        $tokens->verificarDisponible($tokenPorVencer, PropositoDeToken::Recuperar, new DateTimeImmutable('2026-11-16 12:59:59', new DateTimeZone('UTC')));

        try {
            $tokens->verificarDisponible($tokenPorVencer, PropositoDeToken::Recuperar, new DateTimeImmutable('2026-11-16 13:00:00', new DateTimeZone('UTC')));
            self::fail('Aceptó el token justo al cumplir la hora.');
        } catch (TokenInvalido $error) {
            self::assertStringContainsString('venció', $error->getMessage());
        }

        // Vencido por HTTP.
        $this->base->ejecutar('UPDATE token_de_cuenta SET vence_en = UTC_TIMESTAMP() - INTERVAL 1 SECOND');
        $vencido = $this->pedir('GET', '/recuperar/nueva', ['token' => $tokenPorVencer]);
        self::assertSame(410, $vencido->estado());
        self::assertStringContainsString('venció', $vencido->cuerpo());
        self::assertStringNotContainsString('<form action="/cuenta/recuperar/nueva"', $vencido->cuerpo());

        // Inexistente / faltante.
        self::assertSame(400, $this->pedir('GET', '/recuperar/nueva')->estado());
        self::assertSame(400, $this->pedir('GET', '/recuperar/nueva', ['token' => str_repeat('f', 64)])->estado());

        // Token de verificar correo no sirve para recuperar ni se marca usado.
        $tokenVerificar = $tokens->emitir($this->mariana, PropositoDeToken::Verificar, 'PT24H');
        $otroProposito = $this->pedir('POST', '/recuperar/nueva', cuerpo: [
            '_csrf' => self::CSRF,
            'token' => $tokenVerificar,
            'password' => 'butaca nueva 2027',
            'password_confirmacion' => 'butaca nueva 2027',
        ]);
        self::assertSame(400, $otroProposito->estado());
        self::assertNull($this->base->valor('SELECT usado_en FROM token_de_cuenta WHERE huella = ?', [hash('sha256', $tokenVerificar)]));
    }

    public function testSiFallaElSmtpNoFallaLaPeticionNiDejaDatosPersonalesEnElLog(): void
    {
        $respuesta = $this->pedir(
            'POST',
            '/recuperar',
            cuerpo: ['_csrf' => self::CSRF, 'email' => 'mariana@ejemplo.com'],
            configuracion: $this->configuracion('smtp://127.0.0.1:1'),
        );
        self::assertSame(303, $respuesta->estado());
        self::assertSame('/cuenta/recuperar?enviada=1', $respuesta->cabecera('Location'));

        $respuesta->terminar();
        $log = $this->log();
        self::assertStringContainsString($this->mariana, $log);
        self::assertStringContainsString('recuperar la contraseña', $log);
        self::assertStringNotContainsString('mariana@ejemplo.com', $log);
    }
}
