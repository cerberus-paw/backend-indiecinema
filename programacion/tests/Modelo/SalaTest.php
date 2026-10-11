<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Modelo;

use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La creación de funciones, que es lo que la sala controla: estar habilitada y que el cupo entre.
 */
final class SalaTest extends TestCase
{
    private const OBRA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const OTRA_OBRA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private static function sala(bool $habilitada = true): Sala
    {
        $datos = new DatosDeSala('Cine Club El Galpón', 'Un galpón con proyector.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15);

        return new Sala('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $datos, 'sala.jpg', $habilitada);
    }

    public function testCreaLaFuncionEnVotacionYSinFecha(): void
    {
        $sala = self::sala();
        $funcion = $sala->crearFuncion([self::OBRA, self::OTRA_OBRA], 40, 3500.0);

        self::assertSame($sala->id, $funcion->salaId);
        self::assertSame([self::OBRA, self::OTRA_OBRA], $funcion->obrasIds);
        self::assertSame(40, $funcion->cupo);
        self::assertSame(3500.0, $funcion->precioBase);
        self::assertSame(EstadoFuncion::EnVotacionTitulos, $funcion->estado);
        self::assertNull($funcion->fechaHora);
    }

    public function testElCupoPuedeSerIgualALaCapacidad(): void
    {
        self::assertSame(60, self::sala()->crearFuncion([self::OBRA], 60, 0.0)->cupo);
    }

    public function testElCupoNoPuedeSuperarLaCapacidad(): void
    {
        $this->expectException(ReglaIncumplida::class);
        $this->expectExceptionMessage('capacidad de la sala (60)');
        self::sala()->crearFuncion([self::OBRA], 61, 0.0);
    }

    public function testUnaSalaSinHabilitarNoProgramaFunciones(): void
    {
        $this->expectException(ReglaIncumplida::class);
        $this->expectExceptionMessage('no está habilitada');
        self::sala(habilitada: false)->crearFuncion([self::OBRA], 10, 0.0);
    }

    /**
     * @return iterable<string, array{list<string>, int, float}>
     */
    public static function invalidas(): iterable
    {
        yield 'sin obras' => [[], 10, 0.0];
        yield 'obra repetida' => [[self::OBRA, self::OBRA], 10, 0.0];
        yield 'cupo cero' => [[self::OBRA], 0, 0.0];
        yield 'cupo negativo' => [[self::OBRA], -1, 0.0];
        yield 'precio negativo' => [[self::OBRA], 10, -0.01];
    }

    /**
     * @param list<string> $obrasIds
     */
    #[DataProvider('invalidas')]
    public function testRechazaLosDatosQueNoPuedenSer(array $obrasIds, int $cupo, float $precio): void
    {
        $this->expectException(ReglaIncumplida::class);
        self::sala()->crearFuncion($obrasIds, $cupo, $precio);
    }
}
