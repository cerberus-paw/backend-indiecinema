<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Servicios;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Log;
use IndieCinema\Recaudacion\Servicios\CobrosDeFunciones;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El cliente de los cobros de funciones (EF-02.5), por la red contra un funciones de mentira
 * (Dobles/funciones.php) con `php -S`, como en el compose.
 */
final class CobrosDeFuncionesTest extends TestCase
{
    private const PAGADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const A_LA_GORRA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ROTA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const LENTA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    /** @var resource|null */
    private static $servidor = null;

    private static string $url;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$url = "http://127.0.0.1:{$puerto}";
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", dirname(__DIR__) . '/Dobles/funciones.php'],
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
        self::fail('No arrancó el funciones de prueba.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
    }

    private static function cobros(string $token = 'token-de-recaudacion'): CobrosDeFunciones
    {
        return new CobrosDeFunciones(new ClienteInterno('recaudacion', $token, ['funciones' => self::$url], new Log('recaudacion', fopen('php://memory', 'w'))));
    }

    public function testDeUnaFuncionTraeCadaReservaYElNeto(): void
    {
        $cobros = self::cobros()->deUna(self::PAGADA);

        self::assertSame(self::PAGADA, $cobros->funcionId);
        self::assertSame('7000.00', $cobros->neto);
        self::assertCount(2, $cobros->reservas);
        self::assertSame(['7000.00', 'pago-1', 'asistio'], [$cobros->reservas[0]->cobrado, $cobros->reservas[0]->pagoId, $cobros->reservas[0]->estado]);
        self::assertSame(['3500.00', '0.00', null], [$cobros->reservas[1]->total, $cobros->reservas[1]->cobrado, $cobros->reservas[1]->pagoId]);
        self::assertFalse($cobros->reservas[1]->reembolsada);
    }

    public function testDeVariasEnElOrdenEnQueSePidieron(): void
    {
        $cobros = self::cobros()->deVarias([self::A_LA_GORRA, self::PAGADA, self::A_LA_GORRA]);

        self::assertSame([self::A_LA_GORRA, self::PAGADA], array_keys($cobros));
        self::assertSame(['0.00', '7000.00'], [$cobros[self::A_LA_GORRA]->neto, $cobros[self::PAGADA]->neto]);
        self::assertSame([], self::cobros()->deVarias([]));
    }

    public function testSiFuncionesRespondeOtraCosaEsUnErrorYNoUnCero(): void
    {
        $this->expectException(RuntimeException::class);

        self::cobros()->deUna(self::ROTA);
    }

    public function testUnIdQueNoEsUuidNiSePide(): void
    {
        $this->expectException(RuntimeException::class);

        self::cobros()->deVarias([self::PAGADA, '../interno/estado']);
    }

    /**
     * Para ClienteInterno, un 403 es un error de configuración (el token o los llamadores del otro
     * lado), no que el otro no responda.
     */
    public function testConOtroTokenFuncionesNoLoAtiende(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('respondió 403');

        self::cobros('otro-token')->deUna(self::PAGADA);
    }

    public function testSiFuncionesNoRespondeATiempoCortaCon503(): void
    {
        try {
            self::cobros()->deUna(self::LENTA);
            self::fail('Esperó a funciones más de lo que debía.');
        } catch (ExcepcionHttp $error) {
            self::assertSame(503, $error->estado);
        }
    }
}
