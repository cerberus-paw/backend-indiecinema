<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Controladores\TarjetaDeFuncion;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
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
            'lugares_libres' => null,
            'agotada' => false,
        ], TarjetaDeFuncion::datos($funcion));
    }

    public function testArmaLaTarjetaParaFuncionEnVotacion(): void
    {
        $funcion = new FuncionEnCartelera(
            'f-votacion',
            null,
            2500.0,
            's',
            'Cine Club Areco',
            'San Antonio de Areco',
            [new ObraEnCartelera('a', 'Viaje a la Luna', null), new ObraEnCartelera('b', 'Metrópolis', 'afiche.jpg')],
            \IndieCinema\Programacion\Modelo\EstadoFuncion::EnVotacionTitulos,
        );

        self::assertSame([
            'id' => 'f-votacion',
            'titulo' => 'Viaje a la Luna + Metrópolis',
            'estado' => 'EN_VOTACION_TITULOS',
            'precio' => '$ 2.500',
            'sala' => 'Cine Club Areco',
            'localidad' => 'San Antonio de Areco',
            'fecha' => null,
            'lugares_libres' => null,
            'agotada' => false,
        ], TarjetaDeFuncion::datos($funcion, null, 0));
    }

    /** Por darse: los lugares libres sólo se muestran de una función que todavía no pasó. */
    private static function programada(string $id = 'f', int $cupo = 40, string $fecha = '+10 days 20:30'): FuncionEnCartelera
    {
        return new FuncionEnCartelera($id, new DateTimeImmutable($fecha), 3500.0, 's', 'Cine Club Areco', 'San Antonio de Areco', [new ObraEnCartelera('a', 'Zama', null)], EstadoFuncion::Programada, $cupo);
    }

    public function testLosLugaresLibresSonElCupoMenosLosOcupados(): void
    {
        $tarjeta = TarjetaDeFuncion::datos(self::programada(), null, 22);

        self::assertSame(18, $tarjeta['lugares_libres']);
        self::assertFalse($tarjeta['agotada']);
    }

    public function testSinLugaresEstaAgotadaAunqueHayaMasOcupadosQueCupo(): void
    {
        // El organizador bajó el cupo después de que reservaran.
        self::assertSame(['lugares_libres' => 0, 'agotada' => true], array_intersect_key(
            TarjetaDeFuncion::datos(self::programada(cupo: 10), null, 12),
            ['lugares_libres' => 0, 'agotada' => 0],
        ));
    }

    public function testUnaFuncionQueYaPasoNoEstaAgotadaNiMuestraLugares(): void
    {
        // Con todos los lugares tomados: ya se dio, así que la ficha no dice «Función agotada».
        $tarjeta = TarjetaDeFuncion::datos(self::programada(cupo: 10, fecha: '-1 day'), null, 10);

        self::assertNull($tarjeta['lugares_libres']);
        self::assertFalse($tarjeta['agotada']);
        self::assertSame([], TarjetaDeFuncion::conLugares([self::programada('a', fecha: '-1 day')]));
    }

    public function testSinElDatoDeFuncionesNoSeMuestranLosLugares(): void
    {
        $tarjeta = TarjetaDeFuncion::datos(self::programada());

        self::assertNull($tarjeta['lugares_libres']);
        self::assertFalse($tarjeta['agotada']);
    }

    public function testTodasTomaLosOcupadosDeCadaUna(): void
    {
        $tarjetas = TarjetaDeFuncion::todas([self::programada('a'), self::programada('b'), self::programada('c')], [], ['a' => 40, 'b' => 3]);

        self::assertSame([0, 37, null], array_column($tarjetas, 'lugares_libres'));
        self::assertSame([null, null], array_column(TarjetaDeFuncion::todas([self::programada('a'), self::programada('b')], [], null), 'lugares_libres'));
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

    public function testFormateaFechaHora(): void
    {
        self::assertSame('Sáb 24 oct · 20:30', TarjetaDeFuncion::fecha(new DateTimeImmutable('2026-10-24 20:30:00')));
        self::assertSame('Dom 1 nov · 18:00', TarjetaDeFuncion::fecha(new DateTimeImmutable('2026-11-01 18:00:00')));
    }
}
