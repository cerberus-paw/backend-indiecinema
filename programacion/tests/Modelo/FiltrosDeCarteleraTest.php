<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Modelo;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\OrdenDeCartelera;
use PHPUnit\Framework\TestCase;

/**
 * Un filtro en blanco es como no haberlo puesto, la fecha es el día entero y el orden por defecto
 * es por fecha ascendente.
 */
final class FiltrosDeCarteleraTest extends TestCase
{
    public function testLosFiltrosEnBlancoNoFiltran(): void
    {
        self::assertEquals(new FiltrosDeCartelera(), new FiltrosDeCartelera('  ', '', null, "\t", ' '));
        self::assertNull(new FiltrosDeCartelera(texto: ' ')->texto);
        self::assertSame(OrdenDeCartelera::FechaAscendente, (new FiltrosDeCartelera())->orden);
    }

    public function testSacaLosEspaciosDeAlrededor(): void
    {
        $filtros = new FiltrosDeCartelera('  la ciénaga ', ' Luján');

        self::assertSame('la ciénaga', $filtros->texto);
        self::assertSame('Luján', $filtros->localidad);
    }

    public function testLaFechaEsElDiaSinLaHora(): void
    {
        $filtros = new FiltrosDeCartelera(fecha: new DateTimeImmutable('2026-10-12 21:30:00'));

        self::assertEquals(new DateTimeImmutable('2026-10-12 00:00:00'), $filtros->fecha);
    }

    public function testGuardaElOrdenElegido(): void
    {
        $filtros = new FiltrosDeCartelera(orden: OrdenDeCartelera::PrecioDescendente);

        self::assertSame(OrdenDeCartelera::PrecioDescendente, $filtros->orden);
    }
}
