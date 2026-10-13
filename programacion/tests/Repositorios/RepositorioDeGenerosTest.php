<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Repositorios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use PHPUnit\Framework\TestCase;

/**
 * Los géneros que ofrece el filtro de la cartelera, contra un MySQL de verdad.
 */
final class RepositorioDeGenerosTest extends TestCase
{
    use ConBaseDePruebas;

    public function testTodosPorNombre(): void
    {
        $generos = new RepositorioDeGeneros(self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()])));
        $drama = new Genero(Uuid::nuevo(), 'Drama', 'Obras de drama.');
        $comedia = new Genero(Uuid::nuevo(), 'Comedia', 'Obras de comedia.');
        $generos->agregar($drama);
        $generos->agregar($comedia);

        self::assertEquals([$comedia, $drama], $generos->todos());
        self::assertEquals($drama, $generos->buscar($drama->id));
    }

    /**
     * Como llega en la URL de la cartelera: cualquiera lo puede escribir a mano.
     */
    public function testPorNombreSinDistinguirMayusculasNiTildes(): void
    {
        $generos = new RepositorioDeGeneros(self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()])));
        $ficcion = new Genero(Uuid::nuevo(), 'Ciencia ficción', 'Obras de ciencia ficción.');
        $generos->agregar($ficcion);
        $generos->agregar(new Genero(Uuid::nuevo(), 'Drama', 'Obras de drama.'));

        foreach (['Ciencia ficción', 'ciencia ficcion', 'CIENCIA FICCIÓN'] as $nombre) {
            self::assertEquals($ficcion, $generos->porNombre($nombre), $nombre);
        }
        self::assertNull($generos->porNombre('Ciencia'));
        self::assertNull($generos->porNombre($ficcion->id));
        self::assertNull($generos->porNombre("' OR 1=1 -- "));
    }
}
