<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Seguridad;

use IndieCinema\Nucleo\Seguridad\Rol;
use PHPUnit\Framework\TestCase;

final class RolTest extends TestCase
{
    public function testCadaRolAlcanzaLoDeLosAnteriores(): void
    {
        self::assertTrue(Rol::Administrador->alcanza(Rol::Moderador));
        self::assertTrue(Rol::Moderador->alcanza(Rol::Organizador));
        self::assertTrue(Rol::Organizador->alcanza(Rol::Espectador));
        self::assertTrue(Rol::Espectador->alcanza(Rol::Visitante));
        self::assertTrue(Rol::Organizador->alcanza(Rol::Organizador));
    }

    public function testNingunRolAlcanzaLoDeLosSiguientes(): void
    {
        self::assertFalse(Rol::Visitante->alcanza(Rol::Espectador));
        self::assertFalse(Rol::Espectador->alcanza(Rol::Organizador));
        self::assertFalse(Rol::Organizador->alcanza(Rol::Moderador));
        self::assertFalse(Rol::Moderador->alcanza(Rol::Administrador));
    }
}
