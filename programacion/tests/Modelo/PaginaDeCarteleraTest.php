<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Modelo;

use IndieCinema\Programacion\Modelo\PaginaDeCartelera;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cuántas páginas hay y cuáles existen, que es lo que decide el 404 de la cartelera.
 */
final class PaginaDeCarteleraTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function totales(): iterable
    {
        yield 'sin funciones queda la primera' => [0, 10, 1];
        yield 'menos de una página' => [3, 10, 1];
        yield 'una página justa' => [10, 10, 1];
        yield 'una de más' => [11, 10, 2];
        yield 'de a una' => [4, 1, 4];
    }

    #[DataProvider('totales')]
    public function testCuentaLasPaginas(int $total, int $tamano, int $paginas): void
    {
        self::assertSame($paginas, new PaginaDeCartelera([], 1, $tamano, $total)->totalDePaginas());
    }

    public function testExistenDesdeLaPrimeraHastaLaUltima(): void
    {
        self::assertTrue(new PaginaDeCartelera([], 1, 10, 0)->existe());
        self::assertTrue(new PaginaDeCartelera([], 2, 10, 11)->existe());
        self::assertFalse(new PaginaDeCartelera([], 3, 10, 11)->existe());
        self::assertFalse(new PaginaDeCartelera([], 2, 10, 0)->existe());
    }
}
