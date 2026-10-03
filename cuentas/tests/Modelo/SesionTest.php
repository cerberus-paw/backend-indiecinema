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

    public function testSigueVivaHastaElVencimientoSinIncluirlo(): void
    {
        $sesion = Sesion::nueva(str_repeat('f', 64), self::USUARIO, new DateTimeImmutable('2026-10-03 12:00:00'));

        self::assertTrue($sesion->sigueViva(new DateTimeImmutable('2026-10-03 12:00:00')));
        self::assertTrue($sesion->sigueViva(new DateTimeImmutable('2026-10-10 11:59:59')));
        self::assertFalse($sesion->sigueViva(new DateTimeImmutable('2026-10-10 12:00:00')));
        self::assertFalse($sesion->sigueViva(new DateTimeImmutable('2027-01-01 00:00:00')));
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
}
