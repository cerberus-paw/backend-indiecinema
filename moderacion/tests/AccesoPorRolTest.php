<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

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
 * El rol mínimo de cada ruta de moderacion (EF-18.2), en dos partes. Primero, que rutas.php diga lo
 * mismo que la matriz de docs/permisos.md: una ruta nueva o un rol cambiado hace fallar la prueba
 * hasta que se anota allá, así un permiso de menos no pasa sin que nadie lo vea. Después, que se
 * cumpla: sin sesión responde 401, con cada rol que no alcanza responde 403, y con el rol justo
 * pasa el control (lo que conteste después ya es del controlador).
 *
 * Quedan afuera las públicas (rol visitante, que son todas GET), las de la API interna, que
 * prueban sus llamadores en su propia prueba, y el aviso de pago, que se controla por la firma.
 * Que un usuario no llegue a lo de otro (404) lo prueba cada pantalla, porque depende de quién
 * es el dueño.
 *
 * No necesita la base: el rol se controla antes de construir el controlador. Los POST llevan el
 * token CSRF, para que el 403 sea por el rol y no por el token. Esta prueba es la misma en los
 * cinco subsistemas.
 */
final class AccesoPorRolTest extends TestCase
{
    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    /**
     * @return list<Ruta>
     */
    private static function rutasConRol(): array
    {
        $router = new Router();
        (require dirname(__DIR__) . '/rutas.php')($router);

        return array_values(array_filter(
            $router->rutas(),
            static fn (Ruta $ruta): bool => $ruta->rolMinimo !== Rol::Visitante && !$ruta->esInterna() && !$ruta->webhook,
        ));
    }

    /**
     * Cada ruta con rol, con cada rol que la pide: nadie, los que no alcanzan y el justo.
     *
     * @return iterable<string, array{Ruta, ?Rol}>
     */
    public static function rutaYQuienLaPide(): iterable
    {
        foreach (self::rutasConRol() as $ruta) {
            yield "{$ruta->metodo} {$ruta->patron} sin sesión" => [$ruta, null];
            foreach (Rol::cases() as $rol) {
                if ($rol !== Rol::Visitante) {
                    yield "{$ruta->metodo} {$ruta->patron} como {$rol->value}" => [$ruta, $rol];
                }
            }
        }
    }

    /**
     * La ruta con un id cualquiera en cada parámetro, como llega de nginx, con el correo
     * verificado: lo que se prueba es el rol.
     */
    private function pedir(Ruta $ruta, ?Rol $rol): Respuesta
    {
        $configuracion = new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'X-Rol' => $rol->value,
            'X-Correo-Verificado' => '1',
        ];
        $url = (string) preg_replace_callback('/\{\w+\}/', static fn (): string => Uuid::nuevo(), $ruta->patron);
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('moderacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion($ruta->metodo, $url, [], ['_csrf' => self::CSRF], $cabeceras, ['csrf' => self::CSRF], []));
    }

    public function testHayRutasConRol(): void
    {
        self::assertNotEmpty(self::rutasConRol());
    }

    /**
     * Las rutas de rutas.php (sin las internas ni el aviso de pago), como «MÉTODO ruta rol».
     *
     * @return list<string>
     */
    private static function rutasDeRutasPhp(): array
    {
        $router = new Router();
        (require dirname(__DIR__) . '/rutas.php')($router);
        $rutas = [];
        foreach ($router->rutas() as $ruta) {
            if (!$ruta->esInterna() && !$ruta->webhook) {
                $rutas[] = "{$ruta->metodo} {$ruta->patron} {$ruta->rolMinimo->value}";
            }
        }
        sort($rutas);

        return $rutas;
    }

    /**
     * Las filas de la sección de este subsistema en docs/permisos.md, como «MÉTODO ruta rol».
     *
     * @return list<string>
     */
    private static function rutasDeLaMatriz(): array
    {
        $matriz = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/permisos.md');
        self::assertSame(1, preg_match('/^## moderacion\n(.*?)(?=^## |\z)/ms', $matriz, $seccion), 'Falta la sección «moderacion» en docs/permisos.md.');
        preg_match_all('/^\| (GET|POST) \| `([^`]+)` \| (\w+) \|$/m', $seccion[1], $filas, PREG_SET_ORDER);
        $rutas = array_map(static fn (array $fila): string => "{$fila[1]} {$fila[2]} {$fila[3]}", $filas);
        sort($rutas);

        return $rutas;
    }

    public function testLaMatrizDePermisosEsLaDeRutasPhp(): void
    {
        self::assertSame(self::rutasDeLaMatriz(), self::rutasDeRutasPhp(), 'rutas.php y docs/permisos.md no dicen lo mismo: anotá el cambio en la matriz.');
    }

    #[DataProvider('rutaYQuienLaPide')]
    public function testSoloEntraQuienTieneElRolDeLaRuta(Ruta $ruta, ?Rol $rol): void
    {
        $estado = $this->pedir($ruta, $rol)->estado();

        if ($rol === null) {
            self::assertSame(401, $estado, 'Sin sesión no se entra.');
        } elseif (!$rol->alcanza($ruta->rolMinimo)) {
            self::assertSame(403, $estado, "Pide {$ruta->rolMinimo->value}.");
        } else {
            self::assertNotContains($estado, [401, 403], "Con {$rol->value} alcanza: pide {$ruta->rolMinimo->value}.");
        }
    }
}
