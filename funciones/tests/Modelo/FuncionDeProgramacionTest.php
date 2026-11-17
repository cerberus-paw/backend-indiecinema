<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Modelo;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * La ventana de asistencia (EF-05.2): desde una hora antes de la función hasta que termina, con los
 * dos bordes adentro. El fin es la fecha más la duración de la función de la sala, como cuenta
 * programación al finalizarla.
 */
final class FuncionDeProgramacionTest extends TestCase
{
    private static function funcion(?string $fechaHora, int $duracion = 120): FuncionDeProgramacion
    {
        return new FuncionDeProgramacion(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'programada',
            $fechaHora === null ? null : new DateTimeImmutable($fechaHora),
            3500.0,
            40,
            ['Zama'],
            true,
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'Cine Club El Galpón',
            'Luján',
            true,
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            salaDuracionFuncion: $duracion,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dentroDeLaVentana(): array
    {
        return [
            'justo una hora antes' => ['2026-11-14 19:00:00'],
            'a la hora de empezar' => ['2026-11-14 20:00:00'],
            'justo al terminar' => ['2026-11-14 22:00:00'],
        ];
    }

    #[DataProvider('dentroDeLaVentana')]
    public function testDentroDeLaVentanaSeRegistra(string $ahora): void
    {
        self::assertNull(self::funcion('2026-11-14 20:00:00')->motivoParaNoRegistrarAsistencia(new DateTimeImmutable($ahora)));
    }

    public function testAntesDeLaVentanaDiceDesdeCuando(): void
    {
        $funcion = self::funcion('2026-11-14 20:00:00');

        self::assertSame(
            'La asistencia se registra desde una hora antes de la función: desde las 19:00 del 14/11.',
            $funcion->motivoParaNoRegistrarAsistencia(new DateTimeImmutable('2026-11-14 18:59:00')),
        );
        self::assertNotNull($funcion->motivoParaNoRegistrarAsistencia(new DateTimeImmutable('2026-11-13 20:00:00')));
    }

    public function testDespuesDelFinDiceHastaCuando(): void
    {
        self::assertSame(
            'La función ya terminó: la asistencia se registraba hasta las 22:00.',
            self::funcion('2026-11-14 20:00:00')->motivoParaNoRegistrarAsistencia(new DateTimeImmutable('2026-11-14 22:01:00')),
        );
    }

    public function testElFinDependeDeLaDuracionDeLaSala(): void
    {
        $funcion = self::funcion('2026-11-14 20:00:00', 180);

        self::assertNull($funcion->motivoParaNoRegistrarAsistencia(new DateTimeImmutable('2026-11-14 23:00:00')));
        self::assertNotNull($funcion->motivoParaNoRegistrarAsistencia(new DateTimeImmutable('2026-11-14 23:01:00')));
        self::assertEquals(new DateTimeImmutable('2026-11-14 19:00:00'), $funcion->asistenciaDesde());
        self::assertEquals(new DateTimeImmutable('2026-11-14 23:00:00'), $funcion->asistenciaHasta());
    }

    public function testSinFechaNoHayAsistenciaQueRegistrar(): void
    {
        $funcion = self::funcion(null);

        self::assertSame(
            'La función todavía no tiene fecha: no hay asistencia que registrar.',
            $funcion->motivoParaNoRegistrarAsistencia(new DateTimeImmutable('2026-11-14 20:00:00')),
        );
        self::assertNull($funcion->asistenciaDesde());
        self::assertNull($funcion->asistenciaHasta());
    }
}
