<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\ServicioDeSesiones;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Seguridad\Rol;
use PHPUnit\Framework\TestCase;

/**
 * Las sesiones contra un MySQL de verdad, con la tabla de la migración.
 */
final class SesionesTest extends TestCase
{
    use ConBaseDePruebas;

    private BaseDeDatos $base;
    private ServicioDeSesiones $servicio;
    private RepositorioDeUsuarios $usuarios;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->servicio = new ServicioDeSesiones(new RepositorioDeSesiones($this->base));
        $this->usuarios = new RepositorioDeUsuarios($this->base);
    }

    private function usuario(string $correo = 'ana@ejemplo.com'): string
    {
        $usuario = Usuario::nuevo('Ana Pérez', $correo, password_hash('butaca 2026', PASSWORD_ARGON2ID));
        $this->usuarios->agregar($usuario);

        return $usuario->id;
    }

    private function sesionesDe(string $usuarioId): int
    {
        return $this->base->valor('SELECT COUNT(*) FROM sesion WHERE usuario_id = ?', [$usuarioId]);
    }

    public function testAbrirGuardaSoloLaHuellaYLaSesionSirve(): void
    {
        $usuario = $this->usuario();

        $identificador = $this->servicio->abrir($usuario)->identificador;

        self::assertSame([['huella' => hash('sha256', $identificador)]], $this->base->filas('SELECT huella FROM sesion'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sesion WHERE huella = ?', [$identificador]));
        self::assertSame($usuario, $this->servicio->validar($identificador)?->usuarioId);
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM sesion WHERE vence_en = creada_en + INTERVAL 7 DAY'));
    }

    public function testCadaUsoQuedaAnotado(): void
    {
        $identificador = $this->servicio->abrir($this->usuario())->identificador;
        $this->base->ejecutar('UPDATE sesion SET usada_en = creada_en - INTERVAL 1 HOUR');

        $this->servicio->validar($identificador);

        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM sesion WHERE usada_en >= creada_en'));
    }

    public function testUnaSesionVencidaNoSeAceptaYSeBorra(): void
    {
        $identificador = $this->servicio->abrir($this->usuario())->identificador;
        $this->base->ejecutar('UPDATE sesion SET vence_en = UTC_TIMESTAMP() - INTERVAL 1 SECOND');

        self::assertNull($this->servicio->validar($identificador));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sesion'));
    }

    public function testBorrarLaFilaCierraLaSesionEnElPedidoSiguiente(): void
    {
        $identificador = $this->servicio->abrir($this->usuario())->identificador;
        self::assertNotNull($this->servicio->validar($identificador));

        $this->base->ejecutar('DELETE FROM sesion');

        self::assertNull($this->servicio->validar($identificador));
    }

    public function testCerrarSoloCierraEsaSesion(): void
    {
        $usuario = $this->usuario();
        $celular = $this->servicio->abrir($usuario)->identificador;
        $computadora = $this->servicio->abrir($usuario)->identificador;

        $this->servicio->cerrar($celular);

        self::assertNull($this->servicio->validar($celular));
        self::assertNotNull($this->servicio->validar($computadora));
    }

    public function testUnIdentificadorQueNoEsDeNingunaSesionNoSirve(): void
    {
        $this->servicio->abrir($this->usuario());

        self::assertNull($this->servicio->validar(null));
        self::assertNull($this->servicio->validar(''));
        self::assertNull($this->servicio->validar(Sesion::nuevoIdentificador()));
        // La huella de una sesión no sirve como identificador.
        self::assertNull($this->servicio->validar((string) $this->base->valor('SELECT huella FROM sesion')));
    }

    public function testCambiarElRolOElEstadoCierraLasSesionesDelUsuario(): void
    {
        $ana = $this->usuario();
        $otro = $this->usuario('otro@ejemplo.com');
        $this->servicio->abrir($otro);

        $this->servicio->abrir($ana);
        $this->servicio->abrir($ana);
        $this->usuarios->agregarRol($ana, Rol::Organizador);
        self::assertSame(0, $this->sesionesDe($ana));

        $this->servicio->abrir($ana);
        $this->usuarios->quitarRol($ana, Rol::Organizador);
        self::assertSame(0, $this->sesionesDe($ana));

        $identificador = $this->servicio->abrir($ana)->identificador;
        $this->usuarios->cambiarEstado($ana, EstadoCuenta::Suspendida);
        self::assertNull($this->servicio->validar($identificador));

        // Las del resto no se tocan.
        self::assertSame(1, $this->sesionesDe($otro));
        self::assertSame(['espectador'], array_column($this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ?', [$ana]), 'tipo'));
        self::assertSame('suspendida', $this->base->valor('SELECT estado FROM usuario WHERE id = ?', [$ana]));
    }

    public function testAlBorrarUnUsuarioSeVanSusSesiones(): void
    {
        $usuario = $this->usuario();
        $this->servicio->abrir($usuario);

        $this->base->ejecutar('DELETE FROM usuario WHERE id = ?', [$usuario]);

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sesion'));
    }
}
