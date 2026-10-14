<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

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
 * El control de acceso del panel del administrador en cuentas, ruta por ruta: todas las de
 * /administracion/ piden ese rol, y quien no lo tiene recibe 403 (o 401 sin sesión) antes de
 * llegar al controlador. Recorre las rutas de rutas.php, así una ruta nueva del panel que se
 * olvide del rol hace fallar la prueba. Las del panel en programación (la bandeja de
 * solicitudes de sala) tienen la misma prueba allá.
 *
 * No necesita la base: el rol se controla antes de que un controlador la pida, así que corre
 * siempre, también sin MySQL. Los POST llevan el token CSRF, para que el 403 sea por el rol.
 */
final class PanelDeAdministracionTest extends TestCase
{
    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /**
     * @return list<Ruta>
     */
    private static function rutasDelPanel(): array
    {
        $router = new Router();
        (require dirname(__DIR__) . '/rutas.php')($router);

        return array_values(array_filter(
            $router->rutas(),
            static fn (Ruta $ruta): bool => str_starts_with($ruta->patron, '/administracion/'),
        ));
    }

    /**
     * La ruta con un id cualquiera en cada parámetro, como llega de nginx.
     */
    private function pedir(Ruta $ruta, ?string $rol): Respuesta
    {
        $configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'X-Rol' => $rol,
        ];
        $url = (string) preg_replace_callback('/\{\w+\}/', static fn (): string => Uuid::nuevo(), $ruta->patron);
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('cuentas', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion($ruta->metodo, $url, [], ['_csrf' => self::CSRF], $cabeceras, ['csrf' => self::CSRF]));
    }

    public function testTodasLasRutasDelPanelPidenElRolDeAdministrador(): void
    {
        $rutas = self::rutasDelPanel();

        self::assertNotEmpty($rutas);
        foreach ($rutas as $ruta) {
            self::assertSame(Rol::Administrador, $ruta->rolMinimo, "{$ruta->metodo} {$ruta->patron}");
        }
    }

    /**
     * @return iterable<string, array{?string, int, string}>
     */
    public static function quienesNoSonAdministradores(): iterable
    {
        yield 'sin sesión' => [null, 401, 'Tenés que ingresar para ver esta página.'];
        yield 'espectador' => ['espectador', 403, 'No tenés permiso para entrar acá.'];
        yield 'organizador' => ['organizador', 403, 'No tenés permiso para entrar acá.'];
        yield 'moderador' => ['moderador', 403, 'No tenés permiso para entrar acá.'];
    }

    #[DataProvider('quienesNoSonAdministradores')]
    public function testQuienNoEsAdministradorNoEntraANingunaRutaDelPanel(?string $rol, int $estado, string $mensaje): void
    {
        foreach (self::rutasDelPanel() as $ruta) {
            $respuesta = $this->pedir($ruta, $rol);

            self::assertSame($estado, $respuesta->estado(), "{$ruta->metodo} {$ruta->patron}");
            self::assertStringContainsString($mensaje, $respuesta->cuerpo(), "{$ruta->metodo} {$ruta->patron}");
        }
    }
}
