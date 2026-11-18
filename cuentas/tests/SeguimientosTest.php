<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Seguir y dejar de seguir una sala o a su organizador (EF-15.4), de punta a punta contra MySQL:
 * el estado por JSON para el botón de la ficha, el POST con y sin JavaScript, y «Lo que seguís»
 * en Mi cuenta. Sin programación configurada: la lista de salas avisa que no pudo traerlas.
 */
final class SeguimientosTest extends TestCase
{
    use ConBaseDePruebas;
    use ConModeracionDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const SALA = '00000001-0000-4000-8000-000000000001';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::iniciarModeracion();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerModeracion();
    }

    protected function setUp(): void
    {
        self::reiniciarModeracion();
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['moderacion' => self::$urlDeModeracion],
            'interno' => ['token' => 'token-de-cuentas'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    private function agregar(string $nombre, string $correo): string
    {
        $id = Uuid::nuevo();
        (new RepositorioDeUsuarios($this->base))->agregar(new Usuario(
            $id,
            $nombre,
            $correo,
            password_hash('Clave 2026', PASSWORD_ARGON2ID),
            EstadoCuenta::Activa,
            [Rol::Espectador],
            new DateTimeImmutable('2025-03-10 18:30:00', new DateTimeZone('UTC')),
        ));

        return $id;
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @param array<string, string> $consulta
     */
    private function pedir(string $id, string $metodo, string $ruta, array $cuerpo = [], array $consulta = [], bool $json = false): Respuesta
    {
        $cabeceras = [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $id,
            'X-Rol' => 'espectador',
            'X-Usuario-Nombre' => rawurlencode('Eva'),
            'X-Correo-Verificado' => '1',
        ] + ($json ? ['Accept' => 'application/json'] : []);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion($metodo, $ruta, $consulta, $cuerpo + ($metodo === 'POST' ? ['_csrf' => self::CSRF] : []), $cabeceras, ['csrf' => self::CSRF], []));
    }

    /**
     * @return array<string, mixed>
     */
    private function estado(string $id, string $tipo, string $objeto): array
    {
        $respuesta = $this->pedir($id, 'GET', '/seguimientos', consulta: ['tipo' => $tipo, 'id' => $objeto], json: true);
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());

        return json_decode($respuesta->cuerpo(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testSeguirYDejarDeSeguirUnaSalaPorJson(): void
    {
        $eva = $this->agregar('Eva Ramos', 'eva@ejemplo.com');
        self::assertSame(['sigue' => false], $this->estado($eva, 'sala', self::SALA));

        $seguir = $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'sala', 'id' => self::SALA, 'accion' => 'seguir'], json: true);
        self::assertSame(200, $seguir->estado(), $seguir->cuerpo());
        self::assertSame(['sigue' => true], json_decode($seguir->cuerpo(), true));
        self::assertSame(['sigue' => true], $this->estado($eva, 'sala', self::SALA));

        // Seguirla otra vez no duplica ni falla.
        $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'sala', 'id' => self::SALA, 'accion' => 'seguir'], json: true);
        self::assertSame(1, (int) $this->base->valor('SELECT COUNT(*) FROM seguimiento WHERE usuario_id = ?', [$eva]));

        $dejar = $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'sala', 'id' => self::SALA, 'accion' => 'dejar'], json: true);
        self::assertSame(['sigue' => false], json_decode($dejar->cuerpo(), true));
        self::assertSame(['sigue' => false], $this->estado($eva, 'sala', self::SALA));
    }

    public function testSinJavaScriptAlternaYVuelveALaFicha(): void
    {
        $eva = $this->agregar('Eva Ramos', 'eva@ejemplo.com');
        $volver = '/salas/' . self::SALA;

        $primero = $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'sala', 'id' => self::SALA, 'accion' => 'alternar', 'volver' => $volver]);
        self::assertSame(303, $primero->estado());
        self::assertSame($volver, $primero->cabecera('Location'));
        self::assertSame(['sigue' => true], $this->estado($eva, 'sala', self::SALA));

        $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'sala', 'id' => self::SALA, 'accion' => 'alternar', 'volver' => $volver]);
        self::assertSame(['sigue' => false], $this->estado($eva, 'sala', self::SALA));
    }

    public function testNoVuelveAOtroSitio(): void
    {
        $eva = $this->agregar('Eva Ramos', 'eva@ejemplo.com');
        foreach (['https://otro.test/', '//otro.test/', '/\\otro.test'] as $volver) {
            $respuesta = $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'sala', 'id' => self::SALA, 'accion' => 'seguir', 'volver' => $volver]);
            self::assertSame('/cuenta/', $respuesta->cabecera('Location'), $volver);
        }
    }

    public function testPideCsrfYDatosValidos(): void
    {
        $eva = $this->agregar('Eva Ramos', 'eva@ejemplo.com');
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', fopen('php://memory', 'w')));
        $sinToken = $aplicacion->atender(new Peticion('POST', '/seguimientos', [], ['tipo' => 'sala', 'id' => self::SALA, 'accion' => 'seguir'], [
            'X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $eva, 'X-Rol' => 'espectador', 'X-Usuario-Nombre' => 'Eva', 'X-Correo-Verificado' => '1',
        ], ['csrf' => self::CSRF], []));
        self::assertSame(403, $sinToken->estado());

        self::assertSame(400, $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'obra', 'id' => self::SALA, 'accion' => 'seguir'], json: true)->estado());
        self::assertSame(400, $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'sala', 'id' => 'no-es-uuid', 'accion' => 'seguir'], json: true)->estado());
        self::assertSame(400, $this->pedir($eva, 'GET', '/seguimientos', consulta: ['tipo' => 'sala'], json: true)->estado());
        // Nadie se sigue a sí mismo.
        self::assertSame(422, $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'organizador', 'id' => $eva, 'accion' => 'seguir'], json: true)->estado());
    }

    public function testMiCuentaListaLosOrganizadoresYAvisaSiNoPuedeTraerLasSalas(): void
    {
        $eva = $this->agregar('Eva Ramos', 'eva@ejemplo.com');
        $omar = $this->agregar('Omar Organizador', 'omar@ejemplo.com');

        $vacia = $this->pedir($eva, 'GET', '/');
        self::assertStringContainsString('Lo que seguís', $vacia->cuerpo());
        self::assertStringContainsString('Todavía no seguís ninguna sala', $vacia->cuerpo());

        $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'organizador', 'id' => $omar, 'accion' => 'seguir'], json: true);
        $this->pedir($eva, 'POST', '/seguimientos', ['tipo' => 'sala', 'id' => self::SALA, 'accion' => 'seguir'], json: true);
        $cuenta = $this->pedir($eva, 'GET', '/');
        self::assertSame(200, $cuenta->estado());
        self::assertStringContainsString('Omar Organizador', $cuenta->cuerpo());
        self::assertStringContainsString('Dejar de seguir a Omar Organizador', $cuenta->cuerpo());
        self::assertStringContainsString('Ahora no podemos mostrar las salas que seguís', $cuenta->cuerpo());

        // Lo que sigue uno no lo ve otro: Omar no sigue nada.
        self::assertStringContainsString('Todavía no seguís ninguna sala', $this->pedir($omar, 'GET', '/')->cuerpo());
    }
}
