<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\DatosDePrueba;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Encabezados;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El orden de los encabezados de cada página de cuentas (E4-10.2): un único h1 y ningún salto de
 * nivel, como los recorre un lector de pantalla. Con los usuarios de los datos de prueba, así Mi
 * cuenta y el panel de usuarios tienen a quién mostrar. Una página nueva entra con una línea en
 * paginas().
 */
final class OrdenDeEncabezadosTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ADMINISTRADOR = '00000000-0000-4000-8000-000000000001';
    private const ESPECTADORA = '00000000-0000-4000-8000-000000000003';

    protected function setUp(): void
    {
        $base = self::baseConTablasNuevas($this->configuracion());
        DatosDePrueba::para($this->configuracion(), new RepositorioDeUsuarios($base))->cargar();
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
        ]);
    }

    /**
     * Cada página con quién la mira (null es un visitante sin sesión), el método, lo que se manda y
     * el estado que tiene que devolver. Ingresar y registro también cuando vuelven con errores.
     *
     * @return iterable<string, array{string, ?string, string, array<string, string>, int}>
     */
    public static function paginas(): iterable
    {
        yield 'ingresar' => ['/ingresar', null, 'GET', [], 200];
        yield 'ingresar con errores' => ['/ingresar', null, 'POST', ['email' => 'nadie@indiecinema.test', 'password' => 'otra'], 422];
        yield 'registro' => ['/registro', null, 'GET', [], 200];
        yield 'registro con errores' => ['/registro', null, 'POST', [], 422];
        yield 'mi cuenta' => ['/', 'espectador', 'GET', [], 200];
        yield 'usuarios del administrador' => ['/administracion/usuarios', 'administrador', 'GET', [], 200];
        yield 'página que no existe' => ['/no-existe', null, 'GET', [], 404];
        yield 'sin permiso' => ['/administracion/usuarios', 'espectador', 'GET', [], 403];
    }

    /**
     * @param array<string, string> $cuerpo
     */
    #[DataProvider('paginas')]
    public function testUnSoloH1YSinSaltosDeNivel(string $url, ?string $rol, string $metodo, array $cuerpo, int $estado): void
    {
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $rol === 'administrador' ? self::ADMINISTRADOR : self::ESPECTADORA,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Eva Espectadora'),
        ];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('cuentas', fopen('php://memory', 'w')));

        $respuesta = $aplicacion->atender(new Peticion($metodo, $url, [], [...$cuerpo, '_csrf' => self::CSRF], $cabeceras, ['csrf' => self::CSRF]));

        self::assertSame($estado, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame([], Encabezados::problemas($respuesta->cuerpo()));
    }
}
