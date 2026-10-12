<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Controladores\TarjetaDeFuncion;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\ObraEnCartelera;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lo que recibe la tarjeta de función: la fecha y el precio ya escritos como se leen.
 */
final class TarjetaDeFuncionTest extends TestCase
{
    public function testArmaLaTarjetaConTodasLasObras(): void
    {
        $funcion = new FuncionEnCartelera(
            'f',
            new DateTimeImmutable('2026-10-24 20:30:00'),
            3500.0,
            's',
            'Cine Club Areco',
            'San Antonio de Areco',
            [new ObraEnCartelera('a', 'Viaje a la Luna', null), new ObraEnCartelera('b', 'Metrópolis', 'afiche.jpg')],
        );

        self::assertSame([
            'id' => 'f',
            'titulo' => 'Viaje a la Luna + Metrópolis',
            'estado' => 'PROGRAMADA',
            'precio' => '$ 3.500',
            'sala' => 'Cine Club Areco',
            'localidad' => 'San Antonio de Areco',
            'fecha' => 'Sáb 24 oct · 20:30',
        ], TarjetaDeFuncion::datos($funcion));
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function precios(): iterable
    {
        yield 'a la gorra' => [0.0, 'A la gorra'];
        yield 'entero' => [800.0, '$ 800'];
        yield 'con miles' => [12500.0, '$ 12.500'];
        yield 'con centavos' => [1234.5, '$ 1.234,50'];
    }

    #[DataProvider('precios')]
    public function testElPrecio(float $precio, string $texto): void
    {
        self::assertSame($texto, TarjetaDeFuncion::precio($precio));
    }
}
