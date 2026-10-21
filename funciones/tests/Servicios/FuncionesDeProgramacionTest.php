<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Pruebas\ConProgramacionDePrueba;
use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El cliente contra una programación de mentira, por HTTP de verdad (ConProgramacionDePrueba).
 */
final class FuncionesDeProgramacionTest extends TestCase
{
    use ConProgramacionDePrueba;

    private const PROGRAMADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const EN_VOTACION = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ROTA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const LENTA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private function funciones(string $token = 'token-de-funciones', string $url = ''): FuncionesDeProgramacion
    {
        return self::funcionesDeProgramacion($token, $url);
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
