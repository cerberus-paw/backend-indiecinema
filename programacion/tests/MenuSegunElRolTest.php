<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use Dom\HTMLDocument;
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
 * Los roles son acumulativos, como las rutas (Rol::alcanza()): lo del organizador lo ve también el
 * moderador, que puede entrar a esas pantallas. La solicitud de sala la ven los dos: el
 * espectador como «Abrir una sala» y el organizador como «Publicar una sala», que es el mismo
 * trámite. El administrador no usa el sitio público,
 * como el wireframe de su panel: ve sólo el logo y sus secciones, sin la cartelera, las salas ni
 * «Mi cuenta». Se comparan los enlaces completos, así una opción de más (o una que lleva a una
 * pantalla que no existe) rompe la prueba.
 *
 * El menú de usuario es un desplegable de enlaces, no un menú de aplicación (IC-301): sin roles
 * de menú, con el summary sólo con contenido de frase y una lista de enlaces por sección.
 */
final class MenuSegunElRolTest extends TestCase
{
    private const SECRETO = 'secreto-de-nginx';

    /** En el encabezado, con o sin sesión: el logo, la barra y el buscador (que va por action). */
    private const ENCABEZADO_PUBLICO = ['/', '/cartelera', '/obras', '/salas', '/acerca-de#como-funciona', '/acerca-de'];
    /** En el pie, con o sin sesión: «Explorar» y «Comunidad». */
    private const PIE_PUBLICO = ['/cartelera', '/obras', '/salas', '/acerca-de#quienes-somos', '/acerca-de#como-funciona'];
    private const SIN_SESION = ['/cuenta/ingresar', '/cuenta/registro'];
    private const CON_SESION = ['/cuenta/', '/funcion/reservas'];
    private const DEL_ESPECTADOR = ['/solicitar-sala'];
    private const DEL_ORGANIZADOR = ['/organizador/salas', '/organizador/obras', '/organizador/obras/nueva', '/organizador/funciones', '/organizador/funciones/nueva', '/solicitar-sala'];
    private const DEL_ADMINISTRADOR = ['/cuenta/administracion/usuarios', '/moderacion/solicitudes'];

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
        yield 'administrador' => ['administrador', self::DEL_ADMINISTRADOR];
    }

    /**
     * Los roles que tienen el menú de usuario.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function conSesion(): iterable
    {
        foreach (self::roles() as $caso => [$rol, $propios]) {
            if ($rol !== null) {
                yield $caso => [$rol, $propios];
            }
        }
    }

    /**
     * @param list<string> $propios
     */
    #[DataProvider('roles')]
    public function testCadaRolVeSoloSusOpciones(?string $rol, array $propios): void
    {
        $pagina = $this->pedir($rol);
        // Del sitio público, el administrador conserva sólo el logo.
        $administrador = $rol === 'administrador';

        self::assertSame(self::juntos($administrador ? ['/'] : self::ENCABEZADO_PUBLICO, $propios), self::enlaces($pagina['encabezado']));
        self::assertSame(self::juntos($administrador ? [] : self::PIE_PUBLICO, $propios), self::enlaces($pagina['pie']));
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
     * El menú de usuario es un desplegable de enlaces que se recorre con Tab, no un menú de
     * aplicación: un lector de pantalla que oye «menú» espera moverse con las flechas.
     *
     * @param list<string> $propios
     */
    #[DataProvider('roles')]
    public function testElMenuDeUsuarioNoSeAnunciaComoMenuDeAplicacion(?string $rol, array $propios): void
    {
        $encabezado = $this->pedir($rol)['encabezado'];

        foreach (['role="menu"', 'role="menuitem"', 'role="separator"', 'aria-haspopup'] as $atributo) {
            self::assertStringNotContainsString($atributo, $encabezado);
        }
    }

    /**
     * El botón del menú es el summary, que sólo admite contenido de frase (el validador del W3C
     * marca un div adentro): el nombre y el rol van en span.
     *
     * @param list<string> $propios
     */
    #[DataProvider('conSesion')]
    public function testElBotonDelMenuSoloTieneContenidoDeFrase(string $rol, array $propios): void
    {
        preg_match('#<summary.*?</summary>#s', $this->pedir($rol)['encabezado'], $boton);

        self::assertNotEmpty($boton, 'El menú de usuario sigue siendo un details con su summary.');
        self::assertStringContainsString('Ana Prueba', $boton[0]);
        self::assertDoesNotMatchRegularExpression('#<(div|p|ul|ol|li|h[1-6]|section|form|table)[\s>]#', $boton[0]);
    }

    /**
     * Las opciones del menú, como las oye un lector de pantalla: una lista de enlaces por sección,
     * con el nombre de la sección, y «Cerrar sesión» al final de todo.
     *
     * @return iterable<string, array{string, array<string, list<string>>}>
     */
    public static function secciones(): iterable
    {
        $organizador = [
            'Mi cuenta' => ['/cuenta/', '/funcion/reservas'],
            'Panel del Organizador' => ['/organizador/salas', '/organizador/obras', '/organizador/obras/nueva', '/organizador/funciones', '/organizador/funciones/nueva', '/solicitar-sala'],
        ];
        yield 'espectador' => ['espectador', ['Mi cuenta' => ['/cuenta/', '/funcion/reservas', '/solicitar-sala']]];
        yield 'organizador' => ['organizador', $organizador];
        yield 'moderador' => ['moderador', $organizador];
        yield 'administrador' => ['administrador', ['Administración' => ['/cuenta/administracion/usuarios', '/moderacion/solicitudes']]];
    }

    /**
     * @param array<string, list<string>> $esperadas
     */
    #[DataProvider('secciones')]
    public function testLasOpcionesVanEnListasDeEnlacesPorSeccion(string $rol, array $esperadas): void
    {
        $documento = HTMLDocument::createFromString($this->pedir($rol)['encabezado'], LIBXML_NOERROR);
        $menu = $documento->getElementById('menu-usuario');
        self::assertNotNull($menu);

        $secciones = [];
        foreach ($menu->querySelectorAll('ul') as $lista) {
            $titulo = $documento->getElementById((string) $lista->getAttribute('aria-labelledby'));
            $enlaces = [];
            foreach ($lista->querySelectorAll('li > a') as $enlace) {
                $enlaces[] = (string) $enlace->getAttribute('href');
            }
            $secciones[trim((string) $titulo?->textContent)] = $enlaces;
        }
        self::assertSame($esperadas, $secciones);
        // Ningún enlace suelto, fuera de las listas.
        self::assertCount(0, $menu->querySelectorAll('a:not(li > a)'));
        // Lo último que se recorre con Tab es la salida (el texto trae adelante el ícono decorativo).
        $controles = $menu->querySelectorAll('a, button');
        self::assertStringEndsWith('Cerrar sesión', trim((string) $controles->item($controles->length - 1)?->textContent));
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
