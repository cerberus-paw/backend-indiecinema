<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Modelo;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los cambios de estado de la función. La hora actual se pasa a mano para no depender del reloj.
 */
final class FuncionTest extends TestCase
{
    private const SALA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const OBRA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const OTRA_OBRA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const AHORA = '2026-10-11 18:00:00';
    private const FECHA = '2026-10-24 20:30:00';

    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AHORA);
    }

    private static function enVotacion(): Funcion
    {
        return Funcion::nueva(self::SALA, [self::OBRA, self::OTRA_OBRA], 40, 3500.0);
    }

    private static function enAcuerdo(): Funcion
    {
        $funcion = self::enVotacion();
        $funcion->cerrarVotacionTitulos([self::OBRA]);

        return $funcion;
    }

    private static function programada(): Funcion
    {
        $funcion = self::enAcuerdo();
        $funcion->fijarFechaHora(new DateTimeImmutable(self::FECHA), self::ahora());

        return $funcion;
    }

    private static function cancelada(): Funcion
    {
        $funcion = self::enVotacion();
        $funcion->cancelar();

        return $funcion;
    }

    private static function finalizada(): Funcion
    {
        $funcion = self::programada();
        $funcion->finalizar(new DateTimeImmutable(self::FECHA));

        return $funcion;
    }

    public function testRecorreElCicloDeVidaHastaFinalizar(): void
    {
        $funcion = self::enVotacion();
        self::assertSame(EstadoFuncion::EnVotacionTitulos, $funcion->estado);

        $funcion->cerrarVotacionTitulos([self::OTRA_OBRA]);
        self::assertSame(EstadoFuncion::EnAcuerdoFecha, $funcion->estado);
        self::assertSame([self::OTRA_OBRA], $funcion->obrasIds);
        self::assertNull($funcion->fechaHora);

        $fecha = new DateTimeImmutable(self::FECHA);
        $funcion->fijarFechaHora($fecha, self::ahora());
        self::assertSame(EstadoFuncion::Programada, $funcion->estado);
        self::assertEquals($fecha, $funcion->fechaHora);

        $funcion->finalizar($fecha->modify('+2 hours'));
        self::assertSame(EstadoFuncion::Finalizada, $funcion->estado);
    }

    public function testLaVotacionPuedeQuedarseConVariasObras(): void
    {
        $funcion = self::enVotacion();
        $funcion->cerrarVotacionTitulos([self::OTRA_OBRA, self::OBRA]);

        self::assertSame([self::OTRA_OBRA, self::OBRA], $funcion->obrasIds);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function eleccionesInvalidas(): iterable
    {
        yield 'ninguna' => [[]];
        yield 'una que no era candidata' => [['eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee']];
        yield 'una candidata y una que no' => [[self::OBRA, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee']];
        yield 'una candidata repetida' => [[self::OBRA, self::OBRA]];
    }

    /**
     * @param list<string> $elegidas
     */
    #[DataProvider('eleccionesInvalidas')]
    public function testSoloSeEligenObrasCandidatas(array $elegidas): void
    {
        $funcion = self::enVotacion();

        try {
            $funcion->cerrarVotacionTitulos($elegidas);
            self::fail('Tenía que rechazar la elección.');
        } catch (ReglaIncumplida) {
            self::assertSame(EstadoFuncion::EnVotacionTitulos, $funcion->estado);
            self::assertSame([self::OBRA, self::OTRA_OBRA], $funcion->obrasIds);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fechasNoFuturas(): iterable
    {
        yield 'la de ahora' => [self::AHORA];
        yield 'un segundo antes' => ['2026-10-11 17:59:59'];
        yield 'el año pasado' => ['2025-10-24 20:30:00'];
    }

    #[DataProvider('fechasNoFuturas')]
    public function testLaFechaTieneQueSerFutura(string $fecha): void
    {
        $funcion = self::enAcuerdo();

        try {
            $funcion->fijarFechaHora(new DateTimeImmutable($fecha), self::ahora());
            self::fail('Tenía que rechazar la fecha.');
        } catch (ReglaIncumplida $error) {
            self::assertSame('La fecha de la función tiene que ser futura.', $error->getMessage());
            self::assertSame(EstadoFuncion::EnAcuerdoFecha, $funcion->estado);
            self::assertNull($funcion->fechaHora);
        }
    }

    public function testNoFinalizaAntesDeSuFecha(): void
    {
        $funcion = self::programada();

        $this->expectException(ReglaIncumplida::class);
        $this->expectExceptionMessage('antes de su fecha');
        $funcion->finalizar(new DateTimeImmutable('2026-10-24 20:29:59'));
    }

    /**
     * @return iterable<string, array{callable(): Funcion}>
     */
    public static function cancelables(): iterable
    {
        yield 'en votación de títulos' => [self::enVotacion(...)];
        yield 'en acuerdo de fecha' => [self::enAcuerdo(...)];
        yield 'programada' => [self::programada(...)];
    }

    /**
     * @param callable(): Funcion $crear
     */
    #[DataProvider('cancelables')]
    public function testSeCancelaAntesDeTerminar(callable $crear): void
    {
        $funcion = $crear();
        $funcion->cancelar();

        self::assertSame(EstadoFuncion::Cancelada, $funcion->estado);
    }

    /**
     * Cada operación desde cada estado en que no corresponde: falla y la función queda igual.
     *
     * @return iterable<string, array{callable(): Funcion, callable(Funcion): void}>
     */
    public static function pasosProhibidos(): iterable
    {
        $cerrarVotacion = fn (Funcion $funcion) => $funcion->cerrarVotacionTitulos([self::OBRA]);
        $fijarFecha = fn (Funcion $funcion) => $funcion->fijarFechaHora(new DateTimeImmutable(self::FECHA), self::ahora());
        $cancelar = fn (Funcion $funcion) => $funcion->cancelar();
        $finalizar = fn (Funcion $funcion) => $funcion->finalizar(new DateTimeImmutable('2027-01-01'));

        yield 'fijar la fecha en votación' => [self::enVotacion(...), $fijarFecha];
        yield 'finalizar en votación' => [self::enVotacion(...), $finalizar];
        yield 'cerrar la votación en acuerdo' => [self::enAcuerdo(...), $cerrarVotacion];
        yield 'finalizar en acuerdo' => [self::enAcuerdo(...), $finalizar];
        yield 'cerrar la votación programada' => [self::programada(...), $cerrarVotacion];
        yield 'fijar la fecha programada' => [self::programada(...), $fijarFecha];
        yield 'cerrar la votación cancelada' => [self::cancelada(...), $cerrarVotacion];
        yield 'fijar la fecha cancelada' => [self::cancelada(...), $fijarFecha];
        yield 'cancelar cancelada' => [self::cancelada(...), $cancelar];
        yield 'finalizar cancelada' => [self::cancelada(...), $finalizar];
        yield 'cerrar la votación finalizada' => [self::finalizada(...), $cerrarVotacion];
        yield 'fijar la fecha finalizada' => [self::finalizada(...), $fijarFecha];
        yield 'cancelar finalizada' => [self::finalizada(...), $cancelar];
        yield 'finalizar finalizada' => [self::finalizada(...), $finalizar];
    }

    /**
     * @param callable(): Funcion       $crear
     * @param callable(Funcion): void $operacion
     */
    #[DataProvider('pasosProhibidos')]
    public function testNoPermitePasosFueraDelCicloDeVida(callable $crear, callable $operacion): void
    {
        $funcion = $crear();
        $antes = clone $funcion;

        try {
            $operacion($funcion);
            self::fail('Tenía que rechazar el paso.');
        } catch (ReglaIncumplida $error) {
            self::assertStringContainsString('no puede pasar a', $error->getMessage());
            self::assertEquals($antes, $funcion);
        }
    }

    public function testActualizarModificaDatosDeFuncion(): void
    {
        $funcion = self::programada();
        $otraSala = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $nuevaFecha = new DateTimeImmutable('2026-11-15 21:00:00');

        $funcion->actualizar(
            salaId: $otraSala,
            obrasIds: [self::OTRA_OBRA],
            cupo: 50,
            precioBase: 4200.0,
            fechaHora: $nuevaFecha,
            ahora: self::ahora(),
        );

        self::assertSame($otraSala, $funcion->salaId);
        self::assertSame([self::OTRA_OBRA], $funcion->obrasIds);
        self::assertSame(50, $funcion->cupo);
        self::assertSame(4200.0, $funcion->precioBase);
        self::assertEquals($nuevaFecha, $funcion->fechaHora);
        self::assertSame(EstadoFuncion::Programada, $funcion->estado);
    }

    /**
     * @return iterable<string, array{callable(): Funcion}>
     */
    public static function estadosQueNoSeEditan(): iterable
    {
        yield 'en votación de títulos' => [self::enVotacion(...)];
        yield 'en acuerdo de fecha' => [self::enAcuerdo(...)];
        yield 'cancelada' => [self::cancelada(...)];
        yield 'finalizada' => [self::finalizada(...)];
    }

    /**
     * Sólo se edita una programada: en votación no hay nada que editar y las finales ya terminaron.
     *
     * @param callable(): Funcion $crear
     */
    #[DataProvider('estadosQueNoSeEditan')]
    public function testActualizarSoloUnaProgramada(callable $crear): void
    {
        $funcion = $crear();
        $antes = clone $funcion;

        try {
            $funcion->actualizar(self::SALA, [self::OBRA], 50, 2000.0, new DateTimeImmutable('2026-11-15 21:00:00'), self::ahora());
            self::fail('Se editó una función que no está programada.');
        } catch (ReglaIncumplida $error) {
            self::assertStringContainsString('no se puede modificar', $error->getMessage());
        }
        self::assertEquals($antes, $funcion);
    }

    public function testActualizarRechazaFechaPasada(): void
    {
        $funcion = self::programada();

        $this->expectException(ReglaIncumplida::class);
        $this->expectExceptionMessage('La fecha de la función tiene que ser futura.');

        $funcion->actualizar(self::SALA, [self::OBRA], 40, 2000.0, new DateTimeImmutable('2025-01-01 10:00:00'), self::ahora());
    }
}
