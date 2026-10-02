<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Seguridad;

use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Identificacion;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use PHPUnit\Framework\TestCase;

final class IdentificacionTest extends TestCase
{
    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    /**
     * @param array<string, string> $cabeceras
     */
    private function usuarioCon(array $cabeceras): ?Usuario
    {
        $peticion = new Peticion('GET', '/', cabeceras: $cabeceras);

        return (new Identificacion('secreto', new Log('prueba', $this->salidaDelLog)))->identificar($peticion)->usuario();
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testConElSecretoArmaElUsuarioConSuNombre(): void
    {
        $usuario = $this->usuarioCon([
            'X-Nginx-Secreto' => 'secreto',
            'X-Usuario-Id' => 'u-1',
            'X-Rol' => 'organizador',
            'X-Usuario-Nombre' => rawurlencode('Tomás Resnik'),
        ]);

        self::assertEquals(new Usuario('u-1', Rol::Organizador, 'Tomás Resnik'), $usuario);
    }

    public function testSinNombreElUsuarioIgualSeArma(): void
    {
        $usuario = $this->usuarioCon(['X-Nginx-Secreto' => 'secreto', 'X-Usuario-Id' => 'u-1', 'X-Rol' => 'espectador']);

        self::assertNotNull($usuario);
        self::assertNull($usuario->nombre);
    }

    public function testSinSesionCuentasMandaLasCabecerasVacias(): void
    {
        self::assertNull($this->usuarioCon(['X-Nginx-Secreto' => 'secreto', 'X-Usuario-Id' => '', 'X-Rol' => '']));
    }

    public function testUnRolDesconocidoNoArmaUsuario(): void
    {
        self::assertNull($this->usuarioCon(['X-Nginx-Secreto' => 'secreto', 'X-Usuario-Id' => 'u-1', 'X-Rol' => 'dios']));
        self::assertNull($this->usuarioCon(['X-Nginx-Secreto' => 'secreto', 'X-Usuario-Id' => 'u-1', 'X-Rol' => 'visitante']));
    }

    public function testSinElSecretoIgnoraLasCabecerasYLoRegistra(): void
    {
        self::assertNull($this->usuarioCon(['X-Usuario-Id' => 'u-1', 'X-Rol' => 'administrador']));
        self::assertStringContainsString('WARNING Cabeceras de identidad sin el secreto de nginx en GET /', $this->log());
    }

    public function testConOtroSecretoIgnoraLasCabecerasYLoRegistra(): void
    {
        self::assertNull($this->usuarioCon(['X-Nginx-Secreto' => 'otro', 'X-Usuario-Id' => 'u-1', 'X-Rol' => 'administrador']));
        self::assertStringContainsString('WARNING Cabeceras de identidad sin el secreto de nginx', $this->log());
    }
}
