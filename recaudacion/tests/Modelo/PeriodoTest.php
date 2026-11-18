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
    private const HOY = '2026-11-12 18:30';

    /**
     * @return array{string, string, bool, bool}
     */
    private static function dias(Periodo $periodo): array
    {
        return [$periodo->desde->format('Y-m-d'), $periodo->hasta->format('Y-m-d'), $periodo->sinDesde, $periodo->sinHasta];
    }

    public function testSinElegirNadaEsTodoHastaHoy(): void
    {
        self::assertSame([Periodo::PRINCIPIO, '2026-11-12', true, true], self::dias(Periodo::todo(new DateTimeImmutable(self::HOY))));
        self::assertSame([Periodo::PRINCIPIO, '2026-11-12', true, true], self::dias(Periodo::entre('', '', new DateTimeImmutable(self::HOY))));
    }

    public function testSinElPrimerDiaEntraTodoLoAnteriorAlUltimo(): void
    {
        self::assertSame([Periodo::PRINCIPIO, '2026-10-31', true, false], self::dias(Periodo::entre('', '2026-10-31', new DateTimeImmutable(self::HOY))));
    }

    public function testSinElUltimoDiaLlegaHastaHoy(): void
    {
        self::assertSame(['2026-10-01', '2026-11-12', false, true], self::dias(Periodo::entre('2026-10-01', '', new DateTimeImmutable(self::HOY))));
        // Desde un día que todavía no llegó: ese día solo, vacío, y no un período al revés.
        self::assertSame(['2026-12-01', '2026-12-01', false, true], self::dias(Periodo::entre('2026-12-01', '', new DateTimeImmutable(self::HOY))));
    }

    public function testUnSoloDiaYMasDeUnAnioSirven(): void
    {
        self::assertSame('2026-11-12', Periodo::entre('2026-11-12', '2026-11-12')->hasta->format('Y-m-d'));
        self::assertSame('2028-11-12', Periodo::entre('2026-11-11', '2028-11-12')->hasta->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function periodosQueNoSirven(): iterable
    {
        yield 'el primer día no es una fecha' => ['2026-13-01', '2026-11-30', 'El primer día no es una fecha válida.'];
        yield 'el 31 de febrero' => ['2026-02-01', '2026-02-31', 'El último día no es una fecha válida.'];
        yield 'al revés' => ['2026-11-30', '2026-11-01', 'El último día no puede ser anterior al primero.'];
    }

    #[DataProvider('periodosQueNoSirven')]
    public function testUnPeriodoQueNoSirveDicePorQue(string $desde, string $hasta, string $mensaje): void
    {
        $this->expectException(ReglaIncumplida::class);
        $this->expectExceptionMessage($mensaje);

        Periodo::entre($desde, $hasta);
    }
}
