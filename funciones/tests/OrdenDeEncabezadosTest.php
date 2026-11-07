<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Encabezados;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El orden de los encabezados de cada página de funciones (E4-10.2): un único h1 y ningún salto de
 * nivel, como los recorre un lector de pantalla. Con el programación y el cuentas de mentira de las
 * otras pruebas, y una reserva hecha antes, así Mis reservas, el comprobante y los paneles tienen
 * qué mostrar. Una página nueva entra con una línea en paginas().
 */
final class OrdenDeEncabezadosTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;
    use ConProgramacionDePrueba {
        setUpBeforeClass as iniciarProgramacion;
        tearDownAfterClass as detenerProgramacion;
    }

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ORGANIZADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const ADMINISTRADORA = '99999999-9999-4999-8999-999999999999';
    private const PROXIMA = '12121212-1212-4212-8212-121212121212';

    private static ServidorSmtpDePrueba $correo;

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::iniciarProgramacion();
        self::$correo = ServidorSmtpDePrueba::iniciar();
        self::iniciarCuentas();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerProgramacion();
        self::$correo->detener();
        self::detenerCuentas();
    }

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['programacion' => self::$url, 'cuentas' => self::$urlCuentas],
            'interno' => ['token' => 'token-de-funciones'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test', 'nombre' => 'IndieCinema'],
            'sitio' => ['url' => 'https://indiecinema.test'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @param array<string, string> $cuerpo
     */
    private function pedir(string $metodo, string $url, ?string $rol, array $cuerpo = []): Respuesta
    {
        $usuarios = ['espectador' => self::EVA, 'organizador' => self::ORGANIZADORA, 'administrador' => self::ADMINISTRADORA];
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuarios[$rol],
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Eva Espectadora'),
        ];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion($metodo, $url, [], [...$cuerpo, '_csrf' => self::CSRF], $cabeceras, ['csrf' => self::CSRF]));
    }

    /**
     * Cada página con quién la mira (null es un visitante sin sesión), el método, lo que se manda y
     * el estado que tiene que devolver. {reserva} es la reserva que Eva hace antes de pedirla.
     *
     * @return iterable<string, array{string, ?string, string, array<string, string>, int}>
     */
    public static function paginas(): iterable
    {
        yield 'reservar' => ['/reservar/' . self::PROXIMA, 'espectador', 'GET', [], 200];
        yield 'reservar con errores' => ['/reservar/' . self::PROXIMA, 'espectador', 'POST', ['cantidad' => '0'], 422];
        yield 'mis reservas' => ['/reservas', 'espectador', 'GET', [], 200];
        yield 'reserva confirmada' => ['/reservas/{reserva}', 'espectador', 'GET', [], 200];
        yield 'cancelar una reserva' => ['/reservas/{reserva}/cancelar', 'espectador', 'GET', [], 200];
        yield 'reservas de una función' => ['/organizador/funciones/' . self::PROXIMA . '/reservas', 'organizador', 'GET', [], 200];
        yield 'reservas del administrador' => ['/administracion/reservas', 'administrador', 'GET', [], 200];
        yield 'página que no existe' => ['/no-existe', null, 'GET', [], 404];
        yield 'sin sesión' => ['/reservas', null, 'GET', [], 401];
    }

    /**
     * @param array<string, string> $cuerpo
     */
    #[DataProvider('paginas')]
    public function testUnSoloH1YSinSaltosDeNivel(string $url, ?string $rol, string $metodo, array $cuerpo, int $estado): void
    {
        self::assertSame(303, $this->pedir('POST', '/reservar/' . self::PROXIMA, 'espectador', ['cantidad' => '1'])->estado());
        $reserva = (string) $this->base->valor('SELECT id FROM reserva WHERE usuario_id = ?', [self::EVA]);

        $respuesta = $this->pedir($metodo, str_replace('{reserva}', $reserva, $url), $rol, $cuerpo);

        self::assertSame($estado, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame([], Encabezados::problemas($respuesta->cuerpo()));
    }
}
