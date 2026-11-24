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
 * Los roles son acumulativos en las rutas (Rol::alcanza()), pero no en los menús: el moderador
 * puede entrar a las pantallas del organizador, pero no le sirven, así que ve sólo lo suyo, la
 * moderación (IC-662). Quien tiene un panel (organizador, moderador o administrador) ve un solo
 * enlace a él, en el menú de la cuenta y en el pie, y no sus secciones sueltas: esas están en la
 * barra del panel (F-02.23). La solicitud de sala la ve el espectador como «Abrir una sala»; el
 * organizador la tiene en su panel. El administrador no usa el sitio público, como el wireframe
 * de su panel: ve sólo el logo y el enlace a su panel, sin la cartelera, las salas ni «Mi cuenta».
 * Se comparan los enlaces completos, así una opción de más (o una que lleva a una pantalla que no
 * existe) rompe la prueba.
 *
 * El menú de usuario es un desplegable de enlaces, no un menú de aplicación (IC-301): sin roles
 * de menú, con el summary sólo con contenido de frase y una lista de enlaces por sección.
 */
final class MenuSegunElRolTest extends TestCase
{
    private const SECRETO = 'secreto-de-nginx';

    /** En el encabezado, con o sin sesión: el logo, la barra y el buscador (que va por action). */
    private const ENCABEZADO_PUBLICO = ['/', '/cartelera', '/obras', '/salas', '/como-funciona', '/acerca-de'];
    /** En el pie, con o sin sesión: «Explorar» y «Comunidad». */
    private const PIE_PUBLICO = ['/cartelera', '/obras', '/salas', '/acerca-de#quienes-somos', '/como-funciona', '/recaudacion/beneficios'];
    private const SIN_SESION = ['/cuenta/ingresar', '/cuenta/registro'];
    private const CON_SESION = ['/cuenta/', '/funcion/reservas'];
    private const DEL_ESPECTADOR = ['/solicitar-sala'];
    /** El enlace al panel de cada rol, el único suyo en el encabezado y en el pie (F-02.23). */
    private const DEL_ORGANIZADOR = ['/organizador/salas'];
    private const DEL_MODERADOR = ['/moderacion/solicitudes'];
    private const DEL_ADMINISTRADOR = ['/cuenta/administracion/usuarios'];

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
        yield 'moderador' => ['moderador', [...self::CON_SESION, ...self::DEL_MODERADOR]];
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
        $cuenta = ['/cuenta/', '/funcion/reservas'];
        yield 'espectador' => ['espectador', ['Mi cuenta' => [...$cuenta, '/solicitar-sala']]];
        // El panel, arriba de todo; sin las secciones sueltas, que están en su barra (F-02.23).
        yield 'organizador' => ['organizador', ['Tu panel' => self::DEL_ORGANIZADOR, 'Mi cuenta' => $cuenta]];
        yield 'moderador' => ['moderador', ['Tu panel' => self::DEL_MODERADOR, 'Mi cuenta' => $cuenta]];
        yield 'administrador' => ['administrador', ['Tu panel' => self::DEL_ADMINISTRADOR]];
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
            // El nombre de la lista: el título que la nombra o, la del panel, que no lo muestra, su aria-label.
            $titulo = $documento->getElementById((string) $lista->getAttribute('aria-labelledby'));
            $nombre = $titulo !== null ? trim((string) $titulo->textContent) : (string) $lista->getAttribute('aria-label');
            self::assertNotSame('', $nombre, 'Cada lista del menú tiene nombre.');
            $enlaces = [];
            foreach ($lista->querySelectorAll('li > a') as $enlace) {
                $enlaces[] = (string) $enlace->getAttribute('href');
            }
            $secciones[$nombre] = $enlaces;
        }
        self::assertSame($esperadas, $secciones);
        // Ningún enlace suelto, fuera de las listas.
        self::assertCount(0, $menu->querySelectorAll('a:not(li > a)'));
        // Lo último que se recorre con Tab es la salida (el texto trae adelante el ícono decorativo).
        $controles = $menu->querySelectorAll('a, button');
        self::assertStringEndsWith('Cerrar sesión', trim((string) $controles->item($controles->length - 1)?->textContent));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paneles(): iterable
    {
        yield 'organizador' => ['organizador', 'Panel del organizador'];
        yield 'moderador' => ['moderador', 'Panel de moderación'];
        yield 'administrador' => ['administrador', 'Panel de administración'];
    }

    /**
     * El enlace al panel dice a cuál lleva y tiene el color de ese panel, el mismo de su barra.
     */
    #[DataProvider('paneles')]
    public function testElMenuNombraElPanelDeCadaRol(string $rol, string $nombre): void
    {
        $documento = HTMLDocument::createFromString($this->pedir($rol)['encabezado'], LIBXML_NOERROR);
        $enlace = $documento->querySelector('#menu-usuario .menu-item-panel');

        self::assertNotNull($enlace);
        self::assertSame($nombre, trim((string) $enlace->textContent));
        self::assertStringContainsString("panel-rol-{$rol}", (string) $enlace->getAttribute('class'));
    }

    /**
     * La barra de navegación es sólo del sitio público: sin el acceso directo «Mis salas» o
     * «Moderación» que tenía, porque el panel está en el menú de la cuenta (F-02.23). El
     * administrador, que no usa el sitio público, no la tiene.
     *
     * @param list<string> $propios
     */
    #[DataProvider('roles')]
    public function testLaBarraDeNavegacionEsSoloDelSitioPublico(?string $rol, array $propios): void
    {
        $encontrada = preg_match('#<nav class="navegacion-principal".*?</nav>#s', $this->pedir($rol)['encabezado'], $barra);

        if ($rol === 'administrador') {
            self::assertSame(0, $encontrada);

            return;
        }
        self::assertSame(['/acerca-de', '/cartelera', '/como-funciona', '/obras', '/salas'], self::enlaces($barra[0] ?? ''));
    }
}
