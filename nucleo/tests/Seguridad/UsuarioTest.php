<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Seguridad;

use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use PHPUnit\Framework\TestCase;
use ValueError;

final class UsuarioTest extends TestCase
{
    public function testAlcanzaAceptaElRolComoTextoParaLasPlantillas(): void
    {
        $moderador = new Usuario('u-1', Rol::Moderador);

        self::assertTrue($moderador->alcanza('organizador'));
        self::assertTrue($moderador->alcanza(Rol::Moderador));
        self::assertFalse($moderador->alcanza('administrador'));
    }

    public function testUnRolMalEscritoFallaEnLugarDeDarFalso(): void
    {
        $this->expectException(ValueError::class);

        (new Usuario('u-1', Rol::Moderador))->alcanza('organisador');
    }
}
