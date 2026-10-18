<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas\Modelo;

use DateTimeImmutable;
use IndieCinema\Cuentas\Modelo\Sesion;
use PHPUnit\Framework\TestCase;

final class SesionTest extends TestCase
{
    private const USUARIO = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testVenceASieteDiasDeAbrirse(): void
    {
        $sesion = Sesion::nueva(str_repeat('f', 64), self::USUARIO, new DateTimeImmutable('2026-10-03 12:00:00'));

        self::assertEquals(new DateTimeImmutable('2026-10-03 12:00:00'), $sesion->creadaEn);
        self::assertEquals($sesion->creadaEn, $sesion->usadaEn);
        self::assertEquals(new DateTimeImmutable('2026-10-10 12:00:00'), $sesion->venceEn);
    }

    public function testSigueVivaHastaElVencimientoSinIncluirloAunqueSeUse(): void
    {
        $sesion = self::usada('2026-10-10 11:30:00');

        self::assertTrue($sesion->sigueViva(new DateTimeImmutable('2026-10-10 11:59:59')));
        self::assertFalse($sesion->sigueViva(new DateTimeImmutable('2026-10-10 12:00:00')));
        self::assertFalse($sesion->sigueViva(new DateTimeImmutable('2027-01-01 00:00:00')));
    }

    public function testSeCierraADosHorasDelUltimoUsoSinIncluirlo(): void
    {
        $nueva = Sesion::nueva(str_repeat('f', 64), self::USUARIO, new DateTimeImmutable('2026-10-03 12:00:00'));
        self::assertTrue($nueva->sigueViva(new DateTimeImmutable('2026-10-03 12:00:00')));
        self::assertTrue($nueva->sigueViva(new DateTimeImmutable('2026-10-03 13:59:59')));
        self::assertFalse($nueva->sigueViva(new DateTimeImmutable('2026-10-03 14:00:00')));

        $usada = self::usada('2026-10-05 20:00:00');
        self::assertTrue($usada->sigueViva(new DateTimeImmutable('2026-10-05 21:59:59')));
        self::assertFalse($usada->sigueViva(new DateTimeImmutable('2026-10-05 22:00:00')));
    }

    public function testElIdentificadorEsAlAzarYSoloSeGuardaSuHuella(): void
    {
        $identificador = Sesion::nuevoIdentificador();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $identificador);
        self::assertNotSame($identificador, Sesion::nuevoIdentificador());
        self::assertSame(hash('sha256', $identificador), Sesion::huellaDe($identificador));
    }

    public function testUnIdentificadorSinLaFormaDeUnoNoTieneHuella(): void
    {
        self::assertNull(Sesion::huellaDe(''));
        self::assertNull(Sesion::huellaDe(str_repeat('f', 63)));
        self::assertNull(Sesion::huellaDe(str_repeat('F', 64)));
        self::assertNull(Sesion::huellaDe(str_repeat('f', 64) . "\n"));
        self::assertNull(Sesion::huellaDe("' OR 1=1 --"));
    }

    /**
     * Una sesión abierta el 3/10 a las 12, que vence el 10/10 a la misma hora, con el último uso
     * anotado en ese momento.
     */
    private static function usada(string $usadaEn): Sesion
    {
        $abierta = Sesion::nueva(str_repeat('f', 64), self::USUARIO, new DateTimeImmutable('2026-10-03 12:00:00'));

        return new Sesion($abierta->huella, $abierta->usuarioId, $abierta->creadaEn, new DateTimeImmutable($usadaEn), $abierta->venceEn);
    }
}
