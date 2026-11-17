<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Modelo;

use DateTimeImmutable;
use IndieCinema\Recaudacion\Modelo\ParametroDeReparto;
use IndieCinema\Recaudacion\Modelo\ParteDelReparto;
use IndieCinema\Recaudacion\Modelo\Reparto;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Modelo\RolDelReparto;
use PHPUnit\Framework\TestCase;

/**
 * La cuenta del reparto (EF-08.2), sin base ni red: cómo se parte lo cobrado entre la sala, los
 * dueños de las obras y la plataforma, y que las partes sumen siempre lo cobrado, que es lo que
 * hace que no quede plata sin dueño.
 */
final class RepartoTest extends TestCase
{
    private const FUNCION = '50000000-0000-4000-8000-000000000001';
    private const ORGANIZADOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const DUENA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const OTRO_DUENO = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    private static function parametro(int $sala = 7000, int $dueno = 1500, int $plataforma = 1500): ParametroDeReparto
    {
        return new ParametroDeReparto(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            $sala,
            $dueno,
            $plataforma,
            new DateTimeImmutable('2000-01-01 00:00:00'),
            null,
            new DateTimeImmutable('2000-01-01 00:00:00'),
        );
    }

    /**
     * @param list<string> $duenos
     */
    private static function calcular(int $centavos, array $duenos = [self::DUENA], ?ParametroDeReparto $parametro = null): Reparto
    {
        return Reparto::calcular(
            self::FUNCION,
            new DateTimeImmutable('2026-11-20 23:20:00'),
            $centavos,
            self::ORGANIZADOR,
            $duenos,
            $parametro ?? self::parametro(),
            new DateTimeImmutable('2026-11-20 23:20:30'),
        );
    }

    /**
     * @return array<string, int> el monto de cada parte, por rol y beneficiario
     */
    private static function partes(Reparto $reparto): array
    {
        $partes = [];
        foreach ($reparto->partes as $parte) {
            $partes[$parte->rol->value . ':' . ($parte->beneficiarioId ?? 'sitio')] = $parte->centavos;
        }

        return $partes;
    }

    public function testDiezMilPesosCon70Y15Y15DaSieteMilMilQuinientosYMilQuinientos(): void
    {
        $reparto = self::calcular(1_000_000);

        self::assertSame([
            'sala:' . self::ORGANIZADOR => 700_000,
            'dueno:' . self::DUENA => 150_000,
            'plataforma:sitio' => 150_000,
        ], self::partes($reparto));
        self::assertSame('10000.00', $reparto->cobrado());
        self::assertFalse($reparto->sinPartes());
    }

    public function testConVariasObrasLaParteDelDuenoSeDivideEnPartesIgualesYLasPartesSumanLoCobrado(): void
    {
        // $ 100, tres obras: dos de una dueña y una de otro.
        $reparto = self::calcular(10_000, [self::DUENA, self::DUENA, self::OTRO_DUENO]);

        self::assertSame([
            'sala:' . self::ORGANIZADOR => 7_000,
            // 15 % son 1.500, a 500 por obra: dos obras de la misma dueña son una sola parte.
            'dueno:' . self::DUENA => 1_000,
            'dueno:' . self::OTRO_DUENO => 500,
            'plataforma:sitio' => 1_500,
        ], self::partes($reparto));
        self::assertSame(10_000, array_sum(self::partes($reparto)));
    }

    /**
     * Las divisiones enteras pierden centavos y la plataforma los junta: si se calculara también
     * con su porcentaje, las tres partes sumarían menos que lo cobrado.
     */
    public function testElRedondeoQuedaEnLaParteDeLaPlataforma(): void
    {
        // 1.000,01 entre tres obras: ni el 70 % ni el 15 % ni la división por tres dan exactos.
        $reparto = self::calcular(100_001, [self::DUENA, self::OTRO_DUENO, self::DUENA]);

        $partes = self::partes($reparto);
        self::assertSame(100_001, array_sum($partes));
        self::assertSame(70_000, $partes['sala:' . self::ORGANIZADOR]);
        // 15 % de 100.001 son 15.000,15 → 15.000, a 5.000 por obra.
        self::assertSame(10_000, $partes['dueno:' . self::DUENA]);
        self::assertSame(5_000, $partes['dueno:' . self::OTRO_DUENO]);
        self::assertSame(15_001, $partes['plataforma:sitio']);
    }

    public function testConPorcentajesConDecimalesTambienCierraAlCentavo(): void
    {
        $reparto = self::calcular(100_000, [self::DUENA, self::OTRO_DUENO], self::parametro(3_333, 3_333, 3_334));

        self::assertSame(100_000, array_sum(self::partes($reparto)));
    }

    public function testUnaFuncionSinPlataQuedaRegistradaSinPartes(): void
    {
        $reparto = Reparto::sinPlata(self::FUNCION, new DateTimeImmutable('2026-11-22 22:20:00'));

        self::assertTrue($reparto->sinPartes());
        self::assertSame('0.00', $reparto->cobrado());
        self::assertNull($reparto->parametroId);
    }

    public function testSinPlataNiObrasNoHayNadaQueCalcular(): void
    {
        $this->expectException(ReglaIncumplida::class);

        self::calcular(0);
    }

    public function testUnaFuncionQueNoProyectoNadaNoSeReparte(): void
    {
        $this->expectException(ReglaIncumplida::class);

        self::calcular(1_000_000, []);
    }

    public function testLaParteDeLaPlataformaNoTieneBeneficiarioYLasOtrasSi(): void
    {
        $this->expectException(ReglaIncumplida::class);

        new ParteDelReparto(RolDelReparto::Plataforma, self::ORGANIZADOR, 100);
    }

    public function testUnaParteNoPuedeSerNegativa(): void
    {
        $this->expectException(ReglaIncumplida::class);

        new ParteDelReparto(RolDelReparto::Sala, self::ORGANIZADOR, -1);
    }
}
