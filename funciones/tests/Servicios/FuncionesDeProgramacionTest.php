<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Log;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El cliente pide por HTTP de verdad a una programación de mentira (Dobles/programacion.php, con
 * `php -S`), con el ClienteInterno del núcleo y el token de funciones, como en el compose.
 */
final class FuncionesDeProgramacionTest extends TestCase
{
    private const PROGRAMADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const EN_VOTACION = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ROTA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const LENTA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    /** @var resource|null */
    private static $servidor = null;

    private static string $url;

    public static function setUpBeforeClass(): void
    {
        // Un puerto libre: el sistema da uno al pedir el 0, y se suelta para que lo use php -S.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$url = "http://127.0.0.1:{$puerto}";

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", dirname(__DIR__) . '/Dobles/programacion.php'],
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
        self::fail('No arrancó la programación de prueba.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
    }

    private function funciones(string $token = 'token-de-funciones', string $url = ''): FuncionesDeProgramacion
    {
        return new FuncionesDeProgramacion(new ClienteInterno(
            'funciones',
            $token,
            ['programacion' => $url === '' ? self::$url : $url],
            new Log('funciones', fopen('php://memory', 'w')),
            500,
        ));
    }

    public function testTraeUnaProgramadaConSuFechaSalaYOrganizador(): void
    {
        $funcion = $this->funciones()->buscar(self::PROGRAMADA);

        self::assertNotNull($funcion);
        self::assertSame(self::PROGRAMADA, $funcion->id);
        self::assertSame('programada', $funcion->estado);
        self::assertEquals(new DateTimeImmutable('2026-10-24 20:30:00'), $funcion->fechaHora);
        self::assertSame(3500.0, $funcion->precioBase);
        self::assertSame(40, $funcion->cupo);
        self::assertSame(['Zama'], $funcion->titulos);
        self::assertTrue($funcion->titulosElegidos);
        self::assertSame('Cine Club El Galpón', $funcion->salaNombre);
        self::assertTrue($funcion->estaEnCurso());
        self::assertTrue($funcion->esDe('ffffffff-ffff-4fff-8fff-ffffffffffff'));
        self::assertFalse($funcion->esDe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
    }

    public function testEnVotacionNoTieneFechaYLosTitulosSonLasCandidatas(): void
    {
        $funcion = $this->funciones()->buscar(self::EN_VOTACION);

        self::assertNotNull($funcion);
        self::assertNull($funcion->fechaHora);
        self::assertSame(['La ciénaga', 'Zama'], $funcion->titulos);
        self::assertFalse($funcion->titulosElegidos);
    }

    public function testUnaQueNoExisteEsNullYUnIdMalFormadoNiSePide(): void
    {
        self::assertNull($this->funciones()->buscar('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'));
        // Con este id, si se pidiera, el cliente iría a otra ruta.
        self::assertNull($this->funciones()->buscar('../estado'));
    }

    public function testSiProgramacionCambiaLoQueMandaEsUnErrorClaro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no respondió una función');

        $this->funciones()->buscar(self::ROTA);
    }

    /**
     * Un token equivocado es un problema de configuración: termina como un 500 en el log.
     */
    public function testConOtroTokenEsUnErrorDeConfiguracion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revisar el token de funciones');

        $this->funciones('otro-token')->buscar(self::PROGRAMADA);
    }

    public function testSiProgramacionNoRespondeATiempoEsUn503(): void
    {
        foreach ([[self::LENTA, ''], [self::PROGRAMADA, 'http://127.0.0.1:1']] as [$id, $url]) {
            try {
                $this->funciones(url: $url)->buscar($id);
                self::fail('Tenía que cortar con un 503.');
            } catch (ExcepcionHttp $error) {
                self::assertSame(503, $error->estado);
            }
        }
    }
}
