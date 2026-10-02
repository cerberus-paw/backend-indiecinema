<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Http;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Log;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El cliente llama por HTTP de verdad al subsistema de prueba, que atiende `php -S` con el núcleo
 * entero: así se prueban juntos los dos lados de la API interna, el que llama y el que recibe.
 */
final class ClienteInternoTest extends TestCase
{
    private const TOKEN = 'token-de-programacion';

    /** @var resource|null */
    private static $servidor = null;

    private static string $url;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        // Un puerto libre: el sistema da uno al pedir el 0, y se suelta para que lo use php -S.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$url = "http://127.0.0.1:{$puerto}";

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", dirname(__DIR__) . '/Dobles/servidor-interno.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
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
        self::fail('No arrancó el servidor de prueba.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
    }

    protected function setUp(): void
    {
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    private function cliente(string $token = self::TOKEN, int $esperaMaximaMs = 2000): ClienteInterno
    {
        return new ClienteInterno(
            'programacion',
            $token,
            // En el puerto 1 no escucha nadie: es un subsistema caído.
            ['prueba' => self::$url, 'caido' => 'http://127.0.0.1:1'],
            new Log('programacion', $this->salidaDelLog),
            $esperaMaximaMs,
        );
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testTraeElJsonYElOtroSabeQuienLlama(): void
    {
        $respuesta = $this->cliente()->get('prueba', '/interno/eco', ['q' => 'ñandú y más']);

        self::assertSame(['llamador' => 'programacion', 'q' => 'ñandú y más', 'cuerpo' => []], $respuesta);
    }

    public function testPostMandaLosDatosComoJson(): void
    {
        $datos = ['funcion' => 7, 'titulo' => 'Las acacias', 'gratis' => false];

        $respuesta = $this->cliente()->post('prueba', '/interno/eco', $datos);

        self::assertSame($datos, $respuesta['cuerpo']);
    }

    public function testUnRecursoQueNoExisteDevuelveNull(): void
    {
        self::assertNull($this->cliente()->get('prueba', '/interno/no-existe'));
    }

    public function testConOtroTokenFallaYAvisaQueRevisenElToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prueba respondió 403 a GET /interno/eco: revisar el token de programacion');

        $this->cliente('token-de-otro')->get('prueba', '/interno/eco');
    }

    public function testSiElOtroFallaCortaConUn503(): void
    {
        try {
            $this->cliente()->get('prueba', '/interno/falla');
            self::fail('No cortó.');
        } catch (ExcepcionHttp $error) {
            self::assertSame(503, $error->estado);
        }
        self::assertStringContainsString('WARNING prueba respondió 500 a GET /interno/falla.', $this->log());
    }

    public function testSiElOtroEstaCaidoCortaConUn503(): void
    {
        try {
            $this->cliente()->get('caido', '/interno/eco');
            self::fail('No cortó.');
        } catch (ExcepcionHttp $error) {
            self::assertSame(503, $error->estado);
        }
        self::assertStringContainsString('WARNING caido no respondió a GET /interno/eco', $this->log());
    }

    public function testSiElOtroTardaDemasiadoNoEsperaMasDeLaCuenta(): void
    {
        $inicio = microtime(true);
        try {
            // La ruta tarda 600 ms; el cliente espera 200.
            $this->cliente(esperaMaximaMs: 200)->get('prueba', '/interno/lento');
            self::fail('Esperó de más.');
        } catch (ExcepcionHttp $error) {
            self::assertSame(503, $error->estado);
        }
        self::assertLessThan(0.5, microtime(true) - $inicio);
        // Que el servidor de prueba termine con el pedido lento antes de la prueba siguiente.
        usleep(500_000);
    }

    public function testElTokenNoQuedaEnElLog(): void
    {
        try {
            $this->cliente()->get('caido', '/interno/eco');
        } catch (ExcepcionHttp) {
        }

        self::assertNotSame('', $this->log());
        self::assertStringNotContainsString(self::TOKEN, $this->log());
    }

    public function testSoloLlamaRutasInternas(): void
    {
        $this->expectException(LogicException::class);

        $this->cliente()->get('prueba', '/salas');
    }

    public function testUnSubsistemaSinUrlEsUnErrorDeConfiguracion(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Falta «subsistemas.moderacion» en config.ini.');

        $this->cliente()->get('moderacion', '/interno/estado');
    }
}
