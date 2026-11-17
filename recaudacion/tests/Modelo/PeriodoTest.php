<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Modelo;

use DateTimeImmutable;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PeriodoTest extends TestCase
{
    public function testPorDefectoSonLosUltimosNoventaDiasConHoy(): void
    {
        $periodo = Periodo::porDefecto(new DateTimeImmutable('2026-11-12 18:30'));

        self::assertSame(['2026-08-15', '2026-11-12'], [$periodo->desde->format('Y-m-d'), $periodo->hasta->format('Y-m-d')]);
    }

    public function testUnSoloDiaYUnAnioYUnDiaSirven(): void
    {
        self::assertSame('2026-11-12', Periodo::entre('2026-11-12', '2026-11-12')->hasta->format('Y-m-d'));
        self::assertSame('2027-11-12', Periodo::entre('2026-11-11', '2027-11-12')->hasta->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function periodosQueNoSirven(): iterable
    {
        yield 'sin el primer día' => ['', '2026-11-30', 'El primer día no es una fecha válida.'];
        yield 'el 31 de febrero' => ['2026-02-01', '2026-02-31', 'El último día no es una fecha válida.'];
        yield 'al revés' => ['2026-11-30', '2026-11-01', 'El último día no puede ser anterior al primero.'];
        yield 'más de un año' => ['2026-11-01', '2027-11-03', 'El período puede ser de un año como mucho.'];
    }

    #[DataProvider('periodosQueNoSirven')]
    public function testUnPeriodoQueNoSirveDicePorQue(string $desde, string $hasta, string $mensaje): void
    {
        $this->expectException(ReglaIncumplida::class);
        $this->expectExceptionMessage($mensaje);

        Periodo::entre($desde, $hasta);
    }
}
