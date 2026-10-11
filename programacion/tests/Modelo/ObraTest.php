<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Modelo;

use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\TipoObra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObraTest extends TestCase
{
    private const DUENO = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private static function nueva(string $titulo = 'La sombra del molino', int $anio = 2024, int $duracion = 18): Obra
    {
        return Obra::nueva(self::DUENO, $titulo, 'Un molinero y su sombra.', $anio, 'Rocío Paz', $duracion, TipoObra::Cortometraje, OrigenCopia::ObraPropia);
    }

    public function testConTodoBienTieneIdYDueno(): void
    {
        $obra = self::nueva();

        self::assertTrue(Uuid::esValido($obra->id));
        self::assertSame(self::DUENO, $obra->duenoId);
        self::assertSame(TipoObra::Cortometraje, $obra->tipo);
    }

    public function testAceptaElAnioActualYElPrimero(): void
    {
        self::assertSame((int) date('Y'), self::nueva(anio: (int) date('Y'))->anio);
        self::assertSame(1888, self::nueva(anio: 1888)->anio);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function invalidas(): iterable
    {
        yield 'sin título' => ['', 2024, 18];
        yield 'título en blanco' => ["  \t", 2024, 18];
        yield 'duración cero' => ['Título', 2024, 0];
        yield 'duración negativa' => ['Título', 2024, -5];
        yield 'antes del cine' => ['Título', 1887, 18];
        yield 'del año que viene' => ['Título', (int) date('Y') + 1, 18];
    }

    #[DataProvider('invalidas')]
    public function testRechazaLosDatosQueNoPuedenSer(string $titulo, int $anio, int $duracion): void
    {
        $this->expectException(ReglaIncumplida::class);
        self::nueva($titulo, $anio, $duracion);
    }
}
