<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\CredencialesInvalidas;
use IndieCinema\Cuentas\Servicios\DatosDePrueba;
use IndieCinema\Cuentas\Servicios\ServicioDeIngreso;
use IndieCinema\Cuentas\Servicios\ServicioDeSesiones;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Los usuarios de prueba contra un MySQL de verdad: que queden con su rol y Argon2id, que se pueda
 * entrar con las contraseñas del README, y que en producción se carguen sólo con la confirmación y
 * con las contraseñas del config.ini, nunca con las públicas.
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

    public function testCadaUsuarioQuedaConSuIdFijo(): void
    {
        $this->cargar();

        foreach (DatosDePrueba::USUARIOS as $datos) {
            $this->assertSame($datos['id'], $this->usuarios->buscarPorCorreo($datos['correo'])?->id);
        }
        // Los datos de prueba de programación ponen sus salas y obras a nombre de este id, y lo
        // controlan en su propia prueba: si cambia acá, tiene que cambiar allá.
        $this->assertSame('00000000-0000-4000-8000-000000000002', $this->usuarios->buscarPorCorreo('organizador@indiecinema.test')?->id);
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

    public function testNoSeCarganFueraDeDesarrolloSinLaConfirmacion(): void
    {
        try {
            DatosDePrueba::para($this->produccion(), $this->usuarios);
            $this->fail('Se cargaron datos de prueba en producción.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('--confirmar-produccion', $error->getMessage());
        }
        $this->assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM usuario'));
    }

    public function testEnProduccionCargaTresConLasContrasenasDelConfig(): void
    {
        $resultado = DatosDePrueba::para($this->produccion(), $this->usuarios, true)->cargar();

        $this->assertSame([
            'administrador@indiecinema.test' => true,
            'organizador@indiecinema.test' => true,
            'eva@indiecinema.test' => true,
        ], $resultado);
        $this->assertSame('00000000-0000-4000-8000-000000000002', $this->usuarios->buscarPorCorreo('organizador@indiecinema.test')?->id);

        $ingreso = new ServicioDeIngreso($this->usuarios, new ServicioDeSesiones(new RepositorioDeSesiones($this->base)));
        $this->assertSame(64, strlen($ingreso->ingresar('administrador@indiecinema.test', 'clave-del-vps-admin', null)->identificador));
        // La del README, que es pública, no entra al sitio publicado.
        $this->expectException(CredencialesInvalidas::class);
        $ingreso->ingresar('administrador@indiecinema.test', 'administrador2026', null);
    }

    public function testEnProduccionNoCargaNadaSiFaltaUnaContrasena(): void
    {
        $sinEspectador = $this->produccion(['administrador' => 'clave-del-vps-admin', 'organizador' => 'clave-del-vps-orga']);

        try {
            DatosDePrueba::para($sinEspectador, $this->usuarios, true);
            $this->fail('Cargó usuarios sin todas las contraseñas.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('datos_de_prueba.espectador', $error->getMessage());
        }
        $this->assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM usuario'));
    }

    public function testEnProduccionRechazaLaContrasenaPublicaDelReadme(): void
    {
        $conLaPublica = $this->produccion(['administrador' => 'clave-del-vps-admin', 'organizador' => 'organizador2026', 'espectador' => 'clave-del-vps-espe']);

        try {
            DatosDePrueba::para($conLaPublica, $this->usuarios, true);
            $this->fail('Aceptó la contraseña pública en producción.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('datos_de_prueba.organizador', $error->getMessage());
            $this->assertStringNotContainsString('organizador2026', $error->getMessage());
        }
        $this->assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM usuario'));
    }

    /**
     * @param array<string, string> $contrasenas la sección [datos_de_prueba]
     */
    private function produccion(array $contrasenas = [
        'administrador' => 'clave-del-vps-admin',
        'organizador' => 'clave-del-vps-orga',
        'espectador' => 'clave-del-vps-espe',
    ]): Configuracion {
        return new Configuracion([
            'app' => ['entorno' => 'produccion'],
            'base' => self::baseDePruebas(),
            'datos_de_prueba' => $contrasenas,
        ]);
    }
}
