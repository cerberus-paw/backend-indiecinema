<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Ruteo\Ruta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El control de acceso del panel del organizador, ruta por ruta, como PanelDeAdministracionTest
 * con el del administrador: todas las de /organizador/ piden ese rol, y un espectador o alguien
 * sin sesión no llega al controlador. Que no toque lo de otro organizador lo prueban
 * SalasDelOrganizadorTest y FuncionesDelOrganizadorTest, porque eso sí necesita la base.
 *
 * También fija cuáles son las rutas públicas: una ruta nueva que se olvide del rol hace fallar la
 * prueba hasta que se la agregue acá a propósito.
 *
 * No necesita la base. Los POST llevan el token CSRF, para que el 403 sea por el rol.
 */
final class PanelDelOrganizadorTest extends TestCase
{
    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** Las que se ven sin ingresar: la portada, la cartelera y las salas. */
    private const PUBLICAS = [
        'GET /',
        'GET /acerca-de',
        'GET /cartelera',
        'GET /cartelera.csv',
        'GET /cartelera/{id}',
        'GET /salas',
        'GET /salas/{id}',
        'GET /salas/{id}/imagen',
    ];

    /**
     * @return list<Ruta>
     */
    private static function rutas(): array
    {
        $router = new Router();
        (require dirname(__DIR__) . '/rutas.php')($router);

        return $router->rutas();
    }

    /**
     * @return list<Ruta>
     */
    private static function rutasDelPanel(): array
    {
        return array_values(array_filter(
            self::rutas(),
            static fn (Ruta $ruta): bool => str_starts_with($ruta->patron, '/organizador/'),
        ));
    }

    /**
     * La ruta con un id cualquiera en cada parámetro, como llega de nginx.
     */
    private function pedir(Ruta $ruta, ?string $rol): Respuesta
    {
        $configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'X-Rol' => $rol,
        ];
        $url = (string) preg_replace_callback('/\{\w+\}/', static fn (): string => Uuid::nuevo(), $ruta->patron);
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion($ruta->metodo, $url, [], ['_csrf' => self::CSRF], $cabeceras, ['csrf' => self::CSRF]));
    }

    public function testTodasLasRutasDelPanelPidenElRolDeOrganizador(): void
    {
        $rutas = self::rutasDelPanel();

        self::assertNotEmpty($rutas);
        foreach ($rutas as $ruta) {
            self::assertSame(Rol::Organizador, $ruta->rolMinimo, "{$ruta->metodo} {$ruta->patron}");
        }
    }

    public function testSinRolSoloQuedanLasPublicasYNingunaCambiaNada(): void
    {
        $sinRol = [];
        foreach (self::rutas() as $ruta) {
            if (!$ruta->esInterna() && $ruta->rolMinimo === Rol::Visitante) {
                $sinRol[] = "{$ruta->metodo} {$ruta->patron}";
            }
        }

        self::assertSame(self::PUBLICAS, $sinRol);
    }

    /**
     * @return iterable<string, array{?string, int, string}>
     */
    public static function quienesNoSonOrganizadores(): iterable
    {
        yield 'sin sesión' => [null, 401, 'Tenés que ingresar para ver esta página.'];
        yield 'espectador' => ['espectador', 403, 'No tenés permiso para entrar acá.'];
    }

    #[DataProvider('quienesNoSonOrganizadores')]
    public function testQuienNoEsOrganizadorNoEntraANingunaRutaDelPanel(?string $rol, int $estado, string $mensaje): void
    {
        foreach (self::rutasDelPanel() as $ruta) {
            $respuesta = $this->pedir($ruta, $rol);

            self::assertSame($estado, $respuesta->estado(), "{$ruta->metodo} {$ruta->patron}");
            self::assertStringContainsString($mensaje, $respuesta->cuerpo(), "{$ruta->metodo} {$ruta->patron}");
        }
    }
}
