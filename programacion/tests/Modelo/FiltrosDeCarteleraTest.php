<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Modelo;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use PHPUnit\Framework\TestCase;

/**
 * Un filtro en blanco es como no haberlo puesto, y la fecha es el día entero.
 */
final class FiltrosDeCarteleraTest extends TestCase
{
    public function testLosFiltrosEnBlancoNoFiltran(): void
    {
        self::assertEquals(new FiltrosDeCartelera(), new FiltrosDeCartelera('  ', '', null, "\t"));
        self::assertNull(new FiltrosDeCartelera(texto: ' ')->texto);
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
}
