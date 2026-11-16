<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Pruebas\Controladores\FormularioDeRegistroTest;
use IndieCinema\Cuentas\Repositorios\RepositorioDeTokens;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\ServicioDeTokens;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use PHPUnit\Framework\TestCase;

/**
 * Verificación de correo al registrarse (X-02.1 / IC-331): emisión de token SHA-256 con 24 h de
 * vigencia, envío SMTP, enlace de un solo uso y rechazo de tokens vencidos, usados, inexistentes o
 * de otro propósito.
 */
final class VerificacionDeCorreoTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

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
     * @param array<string, string> $cabeceras
     */
    private function pedir(
        string $metodo,
        string $ruta,
        array $consulta = [],
        array $cuerpo = [],
        array $cabeceras = [],
        ?Configuracion $configuracion = null,
    ): Respuesta {
        $aplicacion = new Aplicacion(
            dirname(__DIR__),
            $configuracion ?? $this->configuracion(),
            new Log('cuentas', $this->salidaDelLog),
        );

        return $aplicacion->atender(new Peticion($metodo, $ruta, $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF]));
    }

    private function registrar(string $correo = 'Mariana@Ejemplo.com', ?Configuracion $configuracion = null): Respuesta
    {
        return $this->pedir(
            'POST',
            '/registro',
            cuerpo: ['_csrf' => self::CSRF, 'email' => $correo] + FormularioDeRegistroTest::camposValidos(),
            configuracion: $configuracion,
        );
    }

    private function servicioDeTokens(): ServicioDeTokens
    {
        return new ServicioDeTokens(new RepositorioDeTokens($this->base), new RepositorioDeUsuarios($this->base));
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
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
            preg_match('#https://indiecinema\.test/cuenta/verificar\?token=([0-9a-f]{64})#', $texto, $coincidencias),
            $texto,
        );

        return $coincidencias[1];
    }

    public function testRegistrarseMandaElEnlaceYVerificaElCorreoUnaSolaVez(): void
    {
        $alta = $this->registrar();

        self::assertSame(303, $alta->estado());
        self::assertSame('/cuenta/registro?creada=1', $alta->cabecera('Location'));

        $correos = self::$correo->correos();
        self::assertCount(1, $correos);
        self::assertSame(['<Mariana@Ejemplo.com>'], $correos[0]['para']);

        $token = self::extraerTokenDelCorreo();
        $huella = hash('sha256', $token);

        $fila = $this->base->fila('SELECT huella, usuario_id, proposito, vence_en, creado_en, usado_en FROM token_de_cuenta');
        self::assertNotNull($fila);
        self::assertSame($huella, $fila['huella']);
        self::assertSame('verificar', $fila['proposito']);
        self::assertNull($fila['usado_en']);
        self::assertSame(
            1,
            $this->base->valor('SELECT COUNT(*) FROM token_de_cuenta WHERE huella = ? AND vence_en = creado_en + INTERVAL 24 HOUR', [$huella]),
        );

        $verificacion = $this->pedir('GET', '/verificar', ['token' => $token]);
        self::assertSame(200, $verificacion->estado(), $verificacion->cuerpo());
        PaginasParaValidar::guardar('verificar-correo-exito', $verificacion->cuerpo());
        self::assertStringContainsString('¡Correo verificado!', $verificacion->cuerpo());
        self::assertStringContainsString('href="/cuenta/ingresar"', $verificacion->cuerpo());

        self::assertNotNull($this->base->valor('SELECT correo_verificado_en FROM usuario WHERE id = ?', [$fila['usuario_id']]));
        self::assertNotNull($this->base->valor('SELECT usado_en FROM token_de_cuenta WHERE huella = ?', [$huella]));

        // Abrir el mismo enlace otra vez no vuelve a servir.
        $repetida = $this->pedir('GET', '/verificar', ['token' => $token]);
        self::assertSame(410, $repetida->estado());
        PaginasParaValidar::guardar('verificar-correo-usado', $repetida->cuerpo());
        self::assertStringContainsString('El enlace ya se usó.', $repetida->cuerpo());
    }

    public function testRechazaTokenVencidoSinVerificarLaCuenta(): void
    {
        $this->registrar();
        $token = self::extraerTokenDelCorreo();
        $huella = hash('sha256', $token);
        $this->base->ejecutar('UPDATE token_de_cuenta SET vence_en = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE huella = ?', [$huella]);

        $respuesta = $this->pedir('GET', '/verificar', ['token' => $token]);

        self::assertSame(410, $respuesta->estado());
        self::assertStringContainsString('venció', $respuesta->cuerpo());
        self::assertNull($this->base->valor('SELECT correo_verificado_en FROM usuario'));
        self::assertNull($this->base->valor('SELECT usado_en FROM token_de_cuenta WHERE huella = ?', [$huella]));
    }

    public function testAceptaJustoAntesDeLas24HorasYRechazaAlCumplirlas(): void
    {
        $this->registrar();
        $usuarioId = (string) $this->base->valor('SELECT id FROM usuario');
        $inicio = new DateTimeImmutable('2026-11-16 12:00:00', new DateTimeZone('UTC'));
        $tokens = $this->servicioDeTokens();

        $porVencer = $tokens->emitir($usuarioId, PropositoDeToken::Verificar, 'PT24H', $inicio);
        // Al cumplir exactamente las 24 h (>= vence_en) ya no sirve.
        $alLimite = $tokens->emitir($usuarioId, PropositoDeToken::Verificar, 'PT24H', $inicio);

        $tokens->consumir($porVencer, PropositoDeToken::Verificar, new DateTimeImmutable('2026-11-17 11:59:59', new DateTimeZone('UTC')));
        self::assertNotNull($this->base->valor('SELECT usado_en FROM token_de_cuenta WHERE huella = ?', [hash('sha256', $porVencer)]));

        $this->expectExceptionMessage('venció');
        $tokens->consumir($alLimite, PropositoDeToken::Verificar, new DateTimeImmutable('2026-11-17 12:00:00', new DateTimeZone('UTC')));
    }

    public function testRechazaTokenInexistenteMalFormadoOFaltante(): void
    {
        $inexistente = $this->pedir('GET', '/verificar', ['token' => str_repeat('a', 64)]);
        self::assertSame(400, $inexistente->estado());
        self::assertStringContainsString('no es válido', $inexistente->cuerpo());

        $malFormado = $this->pedir('GET', '/verificar', ['token' => 'no-es-un-token']);
        self::assertSame(400, $malFormado->estado());
        self::assertStringContainsString('no es válido', $malFormado->cuerpo());

        $sinToken = $this->pedir('GET', '/verificar');
        self::assertSame(400, $sinToken->estado());
        self::assertStringContainsString('incompleto', $sinToken->cuerpo());
    }

    public function testRechazaTokenDeOtroPropositoSinConsumirlo(): void
    {
        $this->registrar();
        $usuarioId = (string) $this->base->valor('SELECT id FROM usuario');
        $tokenRecuperar = $this->servicioDeTokens()->emitir($usuarioId, PropositoDeToken::Recuperar, 'PT1H');
        $huellaRecuperar = hash('sha256', $tokenRecuperar);

        $respuesta = $this->pedir('GET', '/verificar', ['token' => $tokenRecuperar]);

        self::assertSame(400, $respuesta->estado());
        self::assertStringContainsString('no es válido', $respuesta->cuerpo());
        self::assertNull($this->base->valor('SELECT correo_verificado_en FROM usuario WHERE id = ?', [$usuarioId]));
        self::assertNull($this->base->valor('SELECT usado_en FROM token_de_cuenta WHERE huella = ?', [$huellaRecuperar]));
    }

    public function testSiFallaElCorreoLaCuentaIgualSeCreaYEmiteElToken(): void
    {
        $respuesta = $this->registrar(configuracion: $this->configuracion('smtp://127.0.0.1:1'));

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/cuenta/registro?creada=1', $respuesta->cabecera('Location'));

        $usuarioId = $this->base->valor('SELECT id FROM usuario');
        self::assertIsString($usuarioId);
        self::assertNull($this->base->valor('SELECT correo_verificado_en FROM usuario WHERE id = ?', [$usuarioId]));
        self::assertSame(1, $this->base->valor("SELECT COUNT(*) FROM token_de_cuenta WHERE usuario_id = ? AND proposito = 'verificar'", [$usuarioId]));

        $log = $this->log();
        self::assertStringContainsString($usuarioId, $log);
        self::assertStringContainsString('No se pudo enviar el correo para verificar el correo', $log);
        self::assertStringNotContainsString('Mariana@Ejemplo.com', $log);
    }

    public function testConSesionAbiertaOfreceIrAMiCuenta(): void
    {
        $this->registrar();
        $usuarioId = (string) $this->base->valor('SELECT id FROM usuario');
        $token = self::extraerTokenDelCorreo();

        $respuesta = $this->pedir('GET', '/verificar', ['token' => $token], cabeceras: [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuarioId,
            'X-Rol' => 'espectador',
            'X-Usuario-Nombre' => rawurlencode('Mariana'),
        ]);

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('href="/cuenta/"', $respuesta->cuerpo());
        self::assertStringContainsString('Ir a Mi cuenta', $respuesta->cuerpo());
    }
}
