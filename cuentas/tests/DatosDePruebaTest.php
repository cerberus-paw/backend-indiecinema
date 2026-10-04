<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\DatosDePrueba;
use IndieCinema\Cuentas\Servicios\ServicioDeIngreso;
use IndieCinema\Cuentas\Servicios\ServicioDeSesiones;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Los usuarios de prueba contra un MySQL de verdad: que queden con su rol y Argon2id, que se pueda
 * entrar con las contraseñas del README y que nunca se carguen fuera de desarrollo.
 */
final class DatosDePruebaTest extends TestCase
{
    use ConBaseDePruebas;

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeUsuarios $usuarios;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->usuarios = new RepositorioDeUsuarios($this->base);
    }

    /**
     * @return array<string, bool>
     */
    private function cargar(): array
    {
        return DatosDePrueba::para($this->configuracion, $this->usuarios)->cargar();
    }

    public function testCargaLosCuatroUsuariosConSusRoles(): void
    {
        $this->assertSame(array_fill_keys(array_column(DatosDePrueba::USUARIOS, 'correo'), true), $this->cargar());

        $roles = $this->base->filas(
            'SELECT u.correo, GROUP_CONCAT(r.tipo ORDER BY r.tipo) AS roles, u.estado
             FROM usuario u JOIN rol r ON r.usuario_id = u.id GROUP BY u.id ORDER BY u.correo',
        );
        $this->assertSame([
            ['correo' => 'administrador@indiecinema.test', 'roles' => 'espectador,administrador', 'estado' => 'activa'],
            ['correo' => 'elias@indiecinema.test', 'roles' => 'espectador', 'estado' => 'activa'],
            ['correo' => 'eva@indiecinema.test', 'roles' => 'espectador', 'estado' => 'activa'],
            ['correo' => 'organizador@indiecinema.test', 'roles' => 'espectador,organizador', 'estado' => 'activa'],
        ], $roles);
    }

    public function testGuardaLasContrasenasConArgon2id(): void
    {
        $this->cargar();

        foreach (DatosDePrueba::USUARIOS as $datos) {
            $hash = (string) $this->base->valor('SELECT contrasena_hash FROM usuario WHERE correo = ?', [$datos['correo']]);
            $this->assertSame('argon2id', password_get_info($hash)['algoName']);
            $this->assertTrue(password_verify($datos['contrasena'], $hash));
        }
    }

    public function testSePuedeEntrarConLasContrasenasDelReadme(): void
    {
        $this->cargar();
        $ingreso = new ServicioDeIngreso($this->usuarios, new ServicioDeSesiones(new RepositorioDeSesiones($this->base)));

        $sesion = $ingreso->ingresar('organizador@indiecinema.test', 'organizador2026', null);

        $this->assertSame(64, strlen($sesion->identificador));
    }

    public function testCorrerloDeNuevoNoDuplicaNiPisaLoQueYaEsta(): void
    {
        $this->cargar();
        $this->base->ejecutar("UPDATE usuario SET nombre = 'Cambiado a mano' WHERE correo = 'eva@indiecinema.test'");
        $this->base->ejecutar("DELETE FROM usuario WHERE correo = 'elias@indiecinema.test'");

        $resultado = $this->cargar();

        $this->assertSame([
            'administrador@indiecinema.test' => false,
            'organizador@indiecinema.test' => false,
            'eva@indiecinema.test' => false,
            'elias@indiecinema.test' => true,
        ], $resultado);
        $this->assertSame(4, (int) $this->base->valor('SELECT COUNT(*) FROM usuario'));
        $this->assertSame('Cambiado a mano', $this->base->valor("SELECT nombre FROM usuario WHERE correo = 'eva@indiecinema.test'"));
    }

    public function testNoSeCarganFueraDeDesarrollo(): void
    {
        $produccion = new Configuracion(['app' => ['entorno' => 'produccion'], 'base' => self::baseDePruebas()]);

        try {
            DatosDePrueba::para($produccion, $this->usuarios);
            $this->fail('Se cargaron datos de prueba en producción.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('desarrollo', $error->getMessage());
        }
        $this->assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM usuario'));
    }
}
