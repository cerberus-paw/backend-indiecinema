<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El encabezado y el pie del paquete front con cada rol: cada uno ve sólo sus opciones. Se mira en
 * /acerca-de, que es estática y no necesita la base; el encabezado y el pie son los de todas las
 * páginas.
 *
 * Los roles son acumulativos, como las rutas (Rol::alcanza()): lo del organizador lo ven también el
 * moderador y el administrador, que pueden entrar a esas pantallas; lo de administración, sólo el
 * administrador. La solicitud para abrir una sala, al revés, la ve sólo el espectador: los demás
 * ya pueden publicar salas. Se comparan los enlaces completos, así una opción de más (o una que
 * lleva a una pantalla que no existe) rompe la prueba.
 */
final class MenuSegunElRolTest extends TestCase
{
    private const SECRETO = 'secreto-de-nginx';

    /** En el encabezado, con o sin sesión: el logo, la barra y el buscador (que va por action). */
    private const ENCABEZADO_PUBLICO = ['/', '/cartelera', '/salas', '/acerca-de#como-funciona', '/acerca-de'];
    /** En el pie, con o sin sesión: «Explorar» y «Comunidad». */
    private const PIE_PUBLICO = ['/cartelera', '/salas', '/acerca-de#quienes-somos', '/acerca-de#como-funciona'];
    private const SIN_SESION = ['/cuenta/ingresar', '/cuenta/registro'];
    private const CON_SESION = ['/cuenta/'];
    private const DEL_ESPECTADOR = ['/cuenta/solicitud'];
    private const DEL_ORGANIZADOR = ['/organizador/salas', '/organizador/salas/nueva'];
    private const DEL_ADMINISTRADOR = ['/cuenta/administracion/usuarios', '/cuenta/administracion/solicitudes', '/administracion/salas'];

    /**
     * El encabezado y el pie de /acerca-de, por separado.
     *
     * @return array{encabezado: string, pie: string}
     */
    private function pedir(?string $rol): array
    {
        $configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('programacion', fopen('php://memory', 'w')));
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Ana Prueba'),
        ];

        $respuesta = $aplicacion->atender(new Peticion('GET', '/acerca-de', [], [], $cabeceras, [], []));

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        preg_match('#<header class="encabezado.*?</header>#s', $respuesta->cuerpo(), $encabezado);
        preg_match('#<footer.*?</footer>#s', $respuesta->cuerpo(), $pie);

        return ['encabezado' => $encabezado[0] ?? '', 'pie' => $pie[0] ?? ''];
    }

    /**
     * Los enlaces de un pedazo de la página, sin repetir y ordenados.
     *
     * @return list<string>
     */
    private static function enlaces(string $html): array
    {
        preg_match_all('#href="([^"]*)"#', $html, $enlaces);
        $unicos = array_values(array_unique($enlaces[1]));
        sort($unicos);

        return $unicos;
    }

    /**
     * @param list<string> ...$grupos
     *
     * @return list<string>
     */
    private static function juntos(array ...$grupos): array
    {
        $todos = array_values(array_unique(array_merge(...$grupos)));
        sort($todos);

        return $todos;
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function roles(): iterable
    {
        yield 'sin sesión' => [null, self::SIN_SESION];
        yield 'espectador' => ['espectador', [...self::CON_SESION, ...self::DEL_ESPECTADOR]];
        yield 'organizador' => ['organizador', [...self::CON_SESION, ...self::DEL_ORGANIZADOR]];
        yield 'moderador' => ['moderador', [...self::CON_SESION, ...self::DEL_ORGANIZADOR]];
        yield 'administrador' => ['administrador', [...self::CON_SESION, ...self::DEL_ORGANIZADOR, ...self::DEL_ADMINISTRADOR]];
    }

    /**
     * @param list<string> $propios
     */
    #[DataProvider('roles')]
    public function testCadaRolVeSoloSusOpciones(?string $rol, array $propios): void
    {
        $pagina = $this->pedir($rol);

        self::assertSame(self::juntos(self::ENCABEZADO_PUBLICO, $propios), self::enlaces($pagina['encabezado']));
        self::assertSame(self::juntos(self::PIE_PUBLICO, $propios), self::enlaces($pagina['pie']));
    }

    /**
     * @param list<string> $propios
     */
    #[DataProvider('roles')]
    public function testElMenuDeUsuarioSoloConSesion(?string $rol, array $propios): void
    {
        $encabezado = $this->pedir($rol)['encabezado'];

        if ($rol === null) {
            self::assertStringNotContainsString('id="menu-usuario"', $encabezado);
            self::assertStringNotContainsString('action="/cuenta/salir"', $encabezado);

            return;
        }
        // El menú con el nombre y el rol, y la salida por POST.
        self::assertStringContainsString('id="menu-usuario"', $encabezado);
        self::assertStringContainsString('Ana Prueba', $encabezado);
        self::assertStringContainsString("rol-{$rol}", $encabezado);
        self::assertStringContainsString('action="/cuenta/salir"', $encabezado);
    }

    /**
     * Además del menú de usuario, «Mis salas» tiene acceso directo en la barra.
     *
     * @param list<string> $propios
     */
    #[DataProvider('roles')]
    public function testMisSalasEnLaBarraDesdeElOrganizador(?string $rol, array $propios): void
    {
        preg_match('#<nav class="navegacion-principal".*?</nav>#s', $this->pedir($rol)['encabezado'], $barra);

        self::assertSame(
            in_array('/organizador/salas', $propios, true),
            str_contains($barra[0] ?? '', 'href="/organizador/salas"'),
        );
    }
}
