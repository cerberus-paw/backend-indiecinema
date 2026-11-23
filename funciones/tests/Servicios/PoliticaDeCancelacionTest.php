<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\PoliticaDeCancelacion;
use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hasta cuándo se cancela una reserva (EF-22.9), sin base ni red.
 */
final class PoliticaDeCancelacionTest extends TestCase
{
    private const AHORA = '2026-11-23 12:00:00';

    private static function funcion(?string $fechaHora): FuncionDeProgramacion
    {
        return new FuncionDeProgramacion(
            '12121212-1212-4212-8212-121212121212',
            'programada',
            $fechaHora === null ? null : new DateTimeImmutable($fechaHora),
            3500.0,
            40,
            ['Zama'],
            true,
            'ssssssss-ssss-4sss-8sss-ssssssssssss',
            'El Alambique',
            'Luján',
            true,
            'oooooooo-oooo-4ooo-8ooo-oooooooooooo',
        );
    }

    private static function reserva(EstadoReserva $estado): Reserva
    {
        return new Reserva('rrrrrrrr-rrrr-4rrr-8rrr-rrrrrrrrrrrr', '12121212-1212-4212-8212-121212121212', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'ABCD1234', 2, '7000.00', $estado, new DateTimeImmutable('2026-11-01 10:00:00'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function momentos(): iterable
    {
        yield 'faltan dos días' => ['2026-11-25 12:00:00', true];
        yield 'faltan 24 horas y un minuto' => ['2026-11-24 12:01:00', true];
        yield 'faltan justo 24 horas' => ['2026-11-24 12:00:00', false];
        yield 'faltan cinco horas' => ['2026-11-23 17:00:00', false];
        yield 'ya empezó' => ['2026-11-23 11:00:00', false];
    }

    #[DataProvider('momentos')]
    public function testLaConfirmadaSeCancelaHastaLasHorasMinimas(string $funcion, bool $seCancela): void
    {
        $motivo = PoliticaDeCancelacion::deHoras(24)->motivoParaNoCancelar(self::reserva(EstadoReserva::Confirmada), self::funcion($funcion), new DateTimeImmutable(self::AHORA));

        self::assertSame($seCancela, $motivo === null, (string) $motivo);
    }

    public function testLaPendienteSeCancelaHastaQueEmpieza(): void
    {
        $politica = PoliticaDeCancelacion::deHoras(24);
        $pendiente = self::reserva(EstadoReserva::PendientePago);
        $ahora = new DateTimeImmutable(self::AHORA);

        self::assertNull($politica->motivoParaNoCancelar($pendiente, self::funcion('2026-11-23 17:00:00'), $ahora));
        self::assertSame('La función ya empezó: la reserva ya no se puede cancelar.', $politica->motivoParaNoCancelar($pendiente, self::funcion('2026-11-23 11:00:00'), $ahora));
    }

    public function testSinFechaOSinFuncionSeCancela(): void
    {
        $politica = PoliticaDeCancelacion::deHoras(24);
        $confirmada = self::reserva(EstadoReserva::Confirmada);

        self::assertNull($politica->motivoParaNoCancelar($confirmada, self::funcion(null)));
        self::assertNull($politica->motivoParaNoCancelar($confirmada, null));
        self::assertNull($politica->limite(null));
    }

    public function testLaQueYaTerminoNoSeCancela(): void
    {
        foreach ([EstadoReserva::Cancelada, EstadoReserva::Asistio, EstadoReserva::Ausente] as $estado) {
            self::assertNotNull(PoliticaDeCancelacion::deHoras(24)->motivoParaNoCancelar(self::reserva($estado), self::funcion('2026-12-25 20:00:00')));
        }
    }

    public function testElLimiteEsLaFechaMenosLasHoras(): void
    {
        self::assertEquals(new DateTimeImmutable('2026-11-24 20:30:00'), PoliticaDeCancelacion::deHoras(24)->limite(self::funcion('2026-11-25 20:30:00')));
        self::assertEquals(new DateTimeImmutable('2026-11-25 18:30:00'), PoliticaDeCancelacion::deHoras(2)->limite(self::funcion('2026-11-25 20:30:00')));
    }

    public function testConCeroSeCancelaHastaQueEmpieza(): void
    {
        $politica = PoliticaDeCancelacion::deHoras(0);

        self::assertNull($politica->motivoParaNoCancelar(self::reserva(EstadoReserva::Confirmada), self::funcion('2026-11-23 12:05:00'), new DateTimeImmutable(self::AHORA)));
        self::assertSame('Podés cancelar tu reserva hasta que empiece la función.', $politica->texto());
    }

    /**
     * Sin la clave, o con un valor que no sirve, va la de 24: un error de tipeo no deja sin política.
     *
     * @return iterable<string, array{array<string, array<string, mixed>>}>
     */
    public static function configuraciones(): iterable
    {
        yield 'sin [reservas]' => [[]];
        yield 'negativa' => [['reservas' => ['horas_minimas_cancelacion' => -3]]];
        yield 'texto' => [['reservas' => ['horas_minimas_cancelacion' => 'muchas']]];
    }

    /**
     * @param array<string, array<string, mixed>> $secciones
     */
    #[DataProvider('configuraciones')]
    public function testSinUnValorQueSirvaVanVeinticuatro(array $secciones): void
    {
        $politica = new PoliticaDeCancelacion(new Configuracion($secciones));

        self::assertSame(24, $politica->horasMinimas);
        self::assertSame('Podés cancelar tu reserva hasta 24 horas antes de la función; después, ya no.', $politica->texto());
    }

    public function testElTextoDiceLasHorasDelParametro(): void
    {
        self::assertSame('Podés cancelar tu reserva hasta 1 hora antes de la función; después, ya no.', PoliticaDeCancelacion::deHoras(1)->texto());
        self::assertSame('Podés cancelar tu reserva hasta 48 horas antes de la función; después, ya no.', PoliticaDeCancelacion::deHoras(48)->texto());
    }
}
