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
    private const DRAMA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const COMEDIA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    /**
     * @param list<string> $generosIds
     */
    private static function nueva(
        string $titulo = 'La sombra del molino',
        int $anio = 2024,
        int $duracion = 18,
        array $generosIds = [self::DRAMA],
        ?string $afiche = null,
    ): Obra {
        return Obra::nueva(self::DUENO, $titulo, 'Un molinero y su sombra.', $anio, 'Rocío Paz', $duracion, TipoObra::Cortometraje, OrigenCopia::ObraPropia, $generosIds, $afiche);
    }

    public function testConTodoBienTieneIdYDueno(): void
    {
        $obra = self::nueva();

        self::assertTrue(Uuid::esValido($obra->id));
        self::assertSame(self::DUENO, $obra->duenoId);
        self::assertSame(TipoObra::Cortometraje, $obra->tipo);
    }

    public function testGuardaSusGenerosYElAfiche(): void
    {
        $obra = self::nueva(generosIds: [self::COMEDIA, self::DRAMA], afiche: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4.jpg');

        self::assertSame([self::COMEDIA, self::DRAMA], $obra->generosIds);
        self::assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4.jpg', $obra->afiche);
    }

    public function testElAficheEsOpcional(): void
    {
        self::assertNull(self::nueva()->afiche);
    }

    public function testAceptaElAnioActualYElPrimero(): void
    {
        self::assertSame((int) date('Y'), self::nueva(anio: (int) date('Y'))->anio);
        self::assertSame(1888, self::nueva(anio: 1888)->anio);
    }

    /**
     * @return iterable<string, array{string, int, int, list<string>}>
     */
    public static function invalidas(): iterable
    {
        yield 'sin título' => ['', 2024, 18, [self::DRAMA]];
        yield 'título en blanco' => ["  \t", 2024, 18, [self::DRAMA]];
        yield 'duración cero' => ['Título', 2024, 0, [self::DRAMA]];
        yield 'duración negativa' => ['Título', 2024, -5, [self::DRAMA]];
        yield 'antes del cine' => ['Título', 1887, 18, [self::DRAMA]];
        yield 'del año que viene' => ['Título', (int) date('Y') + 1, 18, [self::DRAMA]];
        yield 'sin géneros' => ['Título', 2024, 18, []];
        yield 'género repetido' => ['Título', 2024, 18, [self::DRAMA, self::DRAMA]];
    }

    /**
     * @param list<string> $generosIds
     */
    #[DataProvider('invalidas')]
    public function testRechazaLosDatosQueNoPuedenSer(string $titulo, int $anio, int $duracion, array $generosIds): void
    {
        $this->expectException(ReglaIncumplida::class);
        self::nueva($titulo, $anio, $duracion, $generosIds);
    }
}
