<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Seguridad;

use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Seguridad\Identificacion;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use PHPUnit\Framework\TestCase;

final class IdentificacionTest extends TestCase
{
    /**
     * @param array<string, string> $cabeceras
     */
    private static function usuarioCon(array $cabeceras): ?Usuario
    {
        $peticion = new Peticion('GET', '/', cabeceras: $cabeceras);

        return (new Identificacion('secreto'))->identificar($peticion)->usuario();
    }

    public function testConElSecretoArmaElUsuarioConSuNombre(): void
    {
        $usuario = self::usuarioCon([
            'X-Nginx-Secreto' => 'secreto',
            'X-Usuario-Id' => 'u-1',
            'X-Rol' => 'organizador',
            'X-Usuario-Nombre' => rawurlencode('Tomás Resnik'),
        ]);

        self::assertEquals(new Usuario('u-1', Rol::Organizador, 'Tomás Resnik'), $usuario);
    }

    public function testSinNombreElUsuarioIgualSeArma(): void
    {
        $usuario = self::usuarioCon(['X-Nginx-Secreto' => 'secreto', 'X-Usuario-Id' => 'u-1', 'X-Rol' => 'espectador']);

        self::assertNotNull($usuario);
        self::assertNull($usuario->nombre);
    }

    public function testSinSesionCuentasMandaLasCabecerasVacias(): void
    {
        self::assertNull(self::usuarioCon(['X-Nginx-Secreto' => 'secreto', 'X-Usuario-Id' => '', 'X-Rol' => '']));
    }

    public function testUnRolDesconocidoNoArmaUsuario(): void
    {
        self::assertNull(self::usuarioCon(['X-Nginx-Secreto' => 'secreto', 'X-Usuario-Id' => 'u-1', 'X-Rol' => 'dios']));
        self::assertNull(self::usuarioCon(['X-Nginx-Secreto' => 'secreto', 'X-Usuario-Id' => 'u-1', 'X-Rol' => 'visitante']));
    }

    public function testSinElSecretoIgnoraLasCabecerasYLoRegistra(): void
    {
        $this->expectErrorLog();

        self::assertNull(self::usuarioCon(['X-Usuario-Id' => 'u-1', 'X-Rol' => 'administrador']));
    }

    public function testConOtroSecretoIgnoraLasCabecerasYLoRegistra(): void
    {
        $this->expectErrorLog();

        self::assertNull(self::usuarioCon(['X-Nginx-Secreto' => 'otro', 'X-Usuario-Id' => 'u-1', 'X-Rol' => 'administrador']));
    }
}
