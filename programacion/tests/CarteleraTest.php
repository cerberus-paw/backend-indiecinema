<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use Dom\HTMLDocument;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Controladores\Cartelera;
use IndieCinema\Programacion\Controladores\TarjetaDeFuncion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /cartelera de punta a punta: la aplicación entera con las rutas de programación, la
 * plantilla del paquete front y un MySQL de verdad con los datos de demostración (quince
 * funciones en cuatro salas, de una semana a un mes desde hoy).
 *
 * Corre en desarrollo, con las variables estrictas de Twig: una que falte en la plantilla rompe
 * la prueba en vez de quedar vacía.
 */
final class CarteleraTest extends TestCase
{
    use ConBaseDePruebas;
    use ConFuncionesDePrueba;

    public static function setUpBeforeClass(): void
    {
        self::setUpBeforeClassFunciones();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownAfterClassFunciones();
    }

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private BaseDeDatos $base;
    private string $carpeta;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        @unlink(sys_get_temp_dir() . '/acuerdo_estado_global.txt');
        // Antes de saltearla: tearDown() corre igual y la usa.
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir() . '/acuerdo_estado_global.txt');
        foreach (['salas', 'afiches'] as $subcarpeta) {
            array_map('unlink', glob("{$this->carpeta}/{$subcarpeta}/*") ?: []);
            @rmdir("{$this->carpeta}/{$subcarpeta}");
        }
        @rmdir($this->carpeta);
    }

    /**
     * @param array<string, mixed> $cartelera la sección [cartelera] de config.ini
     */
    private function configuracion(array $cartelera = []): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'cartelera' => $cartelera,
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => [
                'cuentas' => 'http://127.0.0.1:1',
                'funciones' => self::$urlFunciones,
            ],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
    }

    private function cargarDatosDePrueba(): void
    {
        DatosDePrueba::para(
            $this->configuracion(),
            new RepositorioDeGeneros($this->base),
            new RepositorioDeSalas($this->base),
            new RepositorioDeObras($this->base),
            new RepositorioDeFunciones($this->base),
        )->cargar();
    }

    /**
     * @param array<string, mixed>  $cartelera
     * @param array<string, string> $cabeceras
     */
    private function pedir(string $url, array $cartelera = [], array $cabeceras = []): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion($cartelera), new Log('programacion', $this->salidaDelLog));

        return PaginasParaValidar::anotar('GET', $url, $aplicacion->atender(new Peticion('GET', (string) parse_url($url, PHP_URL_PATH), $consulta, [], $cabeceras)));
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function post(
        string $ruta,
        array $cuerpo = [],
        ?string $usuario = null,
        string $rol = 'espectador',
        array $cabecerasExtra = [],
    ): Respuesta {
        $cabeceras = ($usuario === null
            ? []
            : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol]) + $cabecerasExtra;
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', $this->salidaDelLog));

        return PaginasParaValidar::anotar('POST', $ruta, $aplicacion->atender(new Peticion(
            'POST',
            (string) parse_url($ruta, PHP_URL_PATH),
            $consulta,
            ['_csrf' => self::CSRF, ...$cuerpo],
            $cabeceras,
            ['csrf' => self::CSRF],
        )));
    }

    /**
     * El mismo pedido, como lo hace el JavaScript de la cartelera.
     *
     * @param array<string, mixed> $cartelera
     */
    private function pedirJson(string $url, array $cartelera = []): Respuesta
    {
        return $this->pedir($url, $cartelera, ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Respuesta $respuesta): array
    {
        self::assertSame('application/json; charset=utf-8', $respuesta->cabecera('Content-Type'), $respuesta->cuerpo());

        return json_decode($respuesta->cuerpo(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Cuántas tarjetas de función tiene la página.
     */
    private static function tarjetas(Respuesta $respuesta): int
    {
        return substr_count($respuesta->cuerpo(), '<article class="tarjeta-funcion');
    }

    private static function assertPagina(Respuesta $respuesta, int $tarjetas, ?string $resultados = null): void
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame($tarjetas, self::tarjetas($respuesta));
        if ($resultados !== null) {
            self::assertMatchesRegularExpression('#<p class="cartelera-resultados"[^>]*>\s*' . preg_quote($resultados, '#') . '\s*</p>#', $respuesta->cuerpo());
        }
    }

    public function testSinFiltrosMuestraLaPrimeraPaginaDeLasProgramadas(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera');

        self::assertPagina($respuesta, 12, '15 funciones');
        $cuerpo = $respuesta->cuerpo();
        PaginasParaValidar::guardar('cartelera', $cuerpo);
        // La primera es la más próxima: Metrópolis, en una semana, a las 20.
        preg_match('#<article class="tarjeta-funcion.*?</article>#s', $cuerpo, $primera);
        self::assertStringContainsString('Metrópolis', $primera[0] ?? '');
        self::assertStringContainsString('Cine Club El Alambique · Luján', $primera[0] ?? '');
        self::assertStringContainsString('· 20:00', $primera[0] ?? '');
        // Enlaza a su ficha, en programación, y no a /funcion/, que es del subsistema funciones.
        self::assertMatchesRegularExpression('#<a href="/cartelera/([0-9a-f-]{36})" class="tarjeta-funcion-titulo-enlace">#', $primera[0] ?? '');
        preg_match('#href="/cartelera/([0-9a-f-]{36})"#', $primera[0] ?? '', $ficha);
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM funcion WHERE id = ?', [$ficha[1] ?? '']));
        preg_match_all('#<article class="tarjeta-funcion.*?</article>#s', $cuerpo, $tarjetas);
        self::assertStringNotContainsString('href="/funcion/', implode('', $tarjetas[0]));
        self::assertStringContainsString('$ 2.500', $cuerpo);
        self::assertStringContainsString('A la gorra', $cuerpo);
        self::assertStringContainsString('Página 1 de 2', $cuerpo);
        self::assertStringContainsString('href="/cartelera?pagina=2"', $cuerpo);
        self::assertStringNotContainsString('rel="prev"', $cuerpo);
        // Las opciones de los filtros.
        self::assertStringContainsString('<option value="San Antonio de Areco"', $cuerpo);
        // El género, por su nombre: así viaja en la URL.
        self::assertStringContainsString('<option value="Comedia"', $cuerpo);
    }

    public function testLaUltimaPaginaTraeLasQueQuedan(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera?pagina=2');

        self::assertPagina($respuesta, 3, '15 funciones');
        self::assertStringContainsString('Página 2 de 2', $respuesta->cuerpo());
        self::assertStringContainsString('href="/cartelera" class="boton boton-secundario boton-chico" rel="prev"', $respuesta->cuerpo());
        self::assertStringNotContainsString('rel="next"', $respuesta->cuerpo());
    }

    public function testElTamanoDePaginaSaleDeLaConfiguracion(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera', ['por_pagina' => 4]);

        self::assertPagina($respuesta, 4, '15 funciones');
        self::assertStringContainsString('Página 1 de 4', $respuesta->cuerpo());
    }

    public function testElPaginadoNumeraLaPrimeraLaUltimaYLasDeAlrededor(): void
    {
        $this->cargarDatosDePrueba();

        $primera = self::json($this->pedirJson('/cartelera?orden=precio_asc', ['por_pagina' => 1]));
        self::assertSame(
            [
                ['numero' => 1, 'url' => '/cartelera?orden=precio_asc', 'actual' => true],
                ['numero' => 2, 'url' => '/cartelera?orden=precio_asc&pagina=2', 'actual' => false],
                ['numero' => 3, 'url' => '/cartelera?orden=precio_asc&pagina=3', 'actual' => false],
                ['numero' => 4, 'url' => '/cartelera?orden=precio_asc&pagina=4', 'actual' => false],
                ['numero' => 5, 'url' => '/cartelera?orden=precio_asc&pagina=5', 'actual' => false],
                null,
                ['numero' => 15, 'url' => '/cartelera?orden=precio_asc&pagina=15', 'actual' => false],
            ],
            $primera['paginado']['paginas'],
        );

        $delMedio = self::json($this->pedirJson('/cartelera?orden=precio_asc&pagina=8', ['por_pagina' => 1]));
        self::assertSame(
            [
                ['numero' => 1, 'url' => '/cartelera?orden=precio_asc', 'actual' => false],
                null,
                ['numero' => 6, 'url' => '/cartelera?orden=precio_asc&pagina=6', 'actual' => false],
                ['numero' => 7, 'url' => '/cartelera?orden=precio_asc&pagina=7', 'actual' => false],
                ['numero' => 8, 'url' => '/cartelera?orden=precio_asc&pagina=8', 'actual' => true],
                ['numero' => 9, 'url' => '/cartelera?orden=precio_asc&pagina=9', 'actual' => false],
                ['numero' => 10, 'url' => '/cartelera?orden=precio_asc&pagina=10', 'actual' => false],
                null,
                ['numero' => 15, 'url' => '/cartelera?orden=precio_asc&pagina=15', 'actual' => false],
            ],
            $delMedio['paginado']['paginas'],
        );
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function filtros(): iterable
    {
        yield 'por texto, en el director' => ['q=keaton', 4, '4 funciones'];
        yield 'por texto, en el nombre de la sala' => ['q=trocha', 4, '4 funciones'];
        yield 'por localidad' => ['localidad=San+Antonio+de+Areco', 3, '3 funciones'];
        yield 'por fecha' => ['fecha={dentro de 7 días}', 1, '1 función'];
        yield 'por género' => ['genero={Comedia}', 5, '5 funciones'];
        yield 'todos juntos' => ['q=general&localidad=Luj%C3%A1n&fecha={dentro de 28 días}&genero={Comedia}', 1, '1 función'];
    }

    #[DataProvider('filtros')]
    public function testFiltraPorLaUrl(string $consulta, int $tarjetas, string $resultados): void
    {
        $this->cargarDatosDePrueba();
        $consulta = strtr($consulta, [
            '{dentro de 7 días}' => (new DateTimeImmutable('today +7 days'))->format('Y-m-d'),
            '{dentro de 28 días}' => (new DateTimeImmutable('today +28 days'))->format('Y-m-d'),
            '{Comedia}' => 'Comedia',
        ]);

        $respuesta = $this->pedir("/cartelera?{$consulta}");

        self::assertPagina($respuesta, $tarjetas, $resultados);
        self::assertStringNotContainsString('Algunos filtros no se aplicaron', $respuesta->cuerpo());
        // Con un filtro, se puede volver a la cartelera entera.
        self::assertStringContainsString('href="/cartelera"', $respuesta->cuerpo());
    }

    public function testElFormularioMuestraLosFiltrosAplicados(): void
    {
        $this->cargarDatosDePrueba();
        $cuerpo = $this->pedir('/cartelera?q=+keaton+&localidad=Luj%C3%A1n&fecha=2030-01-02&genero=Comedia')->cuerpo();

        self::assertStringContainsString('<form class="filtros-cartelera" action="/cartelera" method="get"', $cuerpo);
        self::assertMatchesRegularExpression('#name="q"\s+class="campo-control control-input"\s+value="keaton"#', $cuerpo);
        self::assertMatchesRegularExpression('#<option value="Luján" selected>#', $cuerpo);
        self::assertMatchesRegularExpression('#<option value="Comedia" selected>#', $cuerpo);
        self::assertMatchesRegularExpression('#name="fecha"\s+class="campo-control control-input"\s+value="2030-01-02"#', $cuerpo);
    }

    public function testElDesplegableMuestraElOrdenElegidoYSinJavaScriptSeAplicaConUnBoton(): void
    {
        $this->cargarDatosDePrueba();
        $cuerpo = $this->pedir('/cartelera?orden=precio_desc')->cuerpo();

        self::assertMatchesRegularExpression('#<select id="cartelera-orden" name="orden" form="filtros-cartelera"#', $cuerpo);
        self::assertMatchesRegularExpression('#<option value="precio_desc" selected>Precio: mayor a menor</option>#', $cuerpo);
        self::assertMatchesRegularExpression('#<noscript>\s*<button type="submit" form="filtros-cartelera"#', $cuerpo);
        // El orden por defecto va con valor vacío, así no viaja en la URL.
        self::assertMatchesRegularExpression('#<option value="" selected>Fecha: más próxima</option>#', $this->pedir('/cartelera')->cuerpo());
    }

    public function testElOrdenSoloNoEsUnFiltroQueHayaQueLimpiar(): void
    {
        $this->cargarDatosDePrueba();

        self::assertStringNotContainsString('Limpiar', $this->pedir('/cartelera?orden=titulo_asc')->cuerpo());
        self::assertStringContainsString('Limpiar', $this->pedir('/cartelera?orden=titulo_asc&localidad=Luj%C3%A1n')->cuerpo());
    }

    public function testElJsonTraeLaBusquedaParaLasUltimasBusquedas(): void
    {
        $this->cargarDatosDePrueba();

        $filtrada = self::json($this->pedirJson('/cartelera?localidad=Luj%C3%A1n&orden=precio_desc&pagina=1'));
        self::assertSame(
            ['url' => '/cartelera?localidad=Luj%C3%A1n&orden=precio_desc', 'etiqueta' => 'Luján · Precio: mayor a menor', 'filtros' => ['localidad' => 'Luján', 'orden' => 'precio_desc']],
            $filtrada['busqueda'],
        );
        self::assertNull(self::json($this->pedirJson('/cartelera?orden=precio_desc'))['busqueda']);
        self::assertNull(self::json($this->pedirJson('/cartelera'))['busqueda']);
    }

    /**
     * Las etiquetas de los filtros activos (E4-10.9): una por filtro, con su nombre, y el link a
     * la cartelera sin ese filtro, que conserva los demás y el orden y vuelve a la primera página.
     */
    public function testElJsonTraeUnaEtiquetaPorFiltroConElLinkQueLoQuita(): void
    {
        $this->cargarDatosDePrueba();

        $json = self::json($this->pedirJson('/cartelera?q=keaton&localidad=Luj%C3%A1n&fecha=2030-01-02&genero=Comedia&precio_min=500&precio_max=2000&orden=precio_asc'));

        $resto = 'orden=precio_asc';
        self::assertSame(
            [
                ['texto' => 'Buscar: keaton', 'url' => "/cartelera?localidad=Luj%C3%A1n&fecha=2030-01-02&genero=Comedia&precio_min=500&precio_max=2000&{$resto}"],
                ['texto' => 'Localidad: Luján', 'url' => "/cartelera?q=keaton&fecha=2030-01-02&genero=Comedia&precio_min=500&precio_max=2000&{$resto}"],
                ['texto' => 'Fecha: 02/01/2030', 'url' => "/cartelera?q=keaton&localidad=Luj%C3%A1n&genero=Comedia&precio_min=500&precio_max=2000&{$resto}"],
                ['texto' => 'Género: Comedia', 'url' => "/cartelera?q=keaton&localidad=Luj%C3%A1n&fecha=2030-01-02&precio_min=500&precio_max=2000&{$resto}"],
                ['texto' => 'Desde $ 500', 'url' => "/cartelera?q=keaton&localidad=Luj%C3%A1n&fecha=2030-01-02&genero=Comedia&precio_max=2000&{$resto}"],
                ['texto' => 'Hasta $ 2.000', 'url' => "/cartelera?q=keaton&localidad=Luj%C3%A1n&fecha=2030-01-02&genero=Comedia&precio_min=500&{$resto}"],
            ],
            $json['filtros_activos'],
        );
        // «Quitar todos» deja sólo el orden elegido.
        self::assertSame('/cartelera?orden=precio_asc', $json['quitar_filtros']);
    }

    public function testElPrecioCeroEsALaGorraYUnFiltroInvalidoNoTieneEtiqueta(): void
    {
        $this->cargarDatosDePrueba();

        $json = self::json($this->pedirJson('/cartelera?precio_max=0&precio_min=abc'));

        self::assertSame([['texto' => 'A la gorra', 'url' => '/cartelera']], $json['filtros_activos']);
        // El inválido sigue explicado en el aviso.
        self::assertCount(1, $json['avisos']);
    }

    public function testSinFiltrosNoHayEtiquetasYQuitarTodosEsLaCartelera(): void
    {
        $this->cargarDatosDePrueba();

        $json = self::json($this->pedirJson('/cartelera?orden=titulo_asc'));

        // El orden solo no es un filtro.
        self::assertSame([], $json['filtros_activos']);
        self::assertSame('/cartelera?orden=titulo_asc', $json['quitar_filtros']);
        self::assertSame('/cartelera', self::json($this->pedirJson('/cartelera'))['quitar_filtros']);
    }

    /**
     * Las etiquetas en la página (E4-10.9): debajo del título, afuera de lo que redibuja el
     * buscador, cada una un link que dice qué filtro quita; y «Filtros» dice cuántos hay.
     */
    public function testLaPaginaMuestraLasEtiquetasQueQuitanCadaFiltro(): void
    {
        $this->cargarDatosDePrueba();

        $documento = HTMLDocument::createFromString($this->pedir('/cartelera?localidad=Luj%C3%A1n&precio_max=2000&orden=precio_asc')->cuerpo(), LIBXML_NOERROR);

        $lista = $documento->querySelector('[data-filtros-activos]');
        self::assertNotNull($lista);
        self::assertNull($lista->closest('#resultados-cartelera'));
        $etiquetas = array_map(
            static fn ($enlace): array => [$enlace->getAttribute('aria-label'), $enlace->getAttribute('href')],
            iterator_to_array($lista->querySelectorAll('a[data-quitar-filtro]')),
        );
        self::assertSame(
            [
                ['Quitar el filtro Localidad: Luján', '/cartelera?precio_max=2000&orden=precio_asc'],
                ['Quitar el filtro Hasta $ 2.000', '/cartelera?localidad=Luj%C3%A1n&orden=precio_asc'],
            ],
            $etiquetas,
        );
        self::assertSame('/cartelera?orden=precio_asc', $lista->querySelector('a[data-quitar-todos]')?->getAttribute('href'));
        self::assertSame('Filtros (2)', trim((string) $documento->querySelector('details[data-filtros-cartelera] summary')?->textContent));
    }

    public function testSinFiltrosLaPaginaNoMuestraEtiquetas(): void
    {
        $this->cargarDatosDePrueba();

        $documento = HTMLDocument::createFromString($this->pedir('/cartelera?orden=precio_asc')->cuerpo(), LIBXML_NOERROR);

        self::assertNull($documento->querySelector('a[data-quitar-filtro]'));
        self::assertNull($documento->querySelector('a[data-quitar-todos]'));
        self::assertSame('Filtros', trim((string) $documento->querySelector('details[data-filtros-cartelera] summary')?->textContent));
    }

    public function testSinFuncionesProgramadasElOrdenNoLasHaceParecerUnaBusqueda(): void
    {
        $respuesta = $this->pedir('/cartelera?orden=precio_asc');

        self::assertPagina($respuesta, 0);
        self::assertStringContainsString('Todavía no hay funciones programadas.', $respuesta->cuerpo());
        self::assertStringNotContainsString('Limpiar', $respuesta->cuerpo());
    }

    /**
     * El género viaja por su nombre, que se puede escribir a mano sin mayúsculas ni tildes: filtra
     * igual, y el formulario, los links y el JSON lo devuelven como está guardado.
     */
    public function testElGeneroPorNombreSinMayusculasNiTildes(): void
    {
        $this->cargarDatosDePrueba();
        $esperado = $this->pedirJson('/cartelera?genero=Ciencia%20ficci%C3%B3n');

        $pagina = $this->pedir('/cartelera?genero=ciencia+ficcion', ['por_pagina' => 1]);
        $json = self::json($this->pedirJson('/cartelera?genero=CIENCIA+FICCION', ['por_pagina' => 1]));

        self::assertSame(200, $pagina->estado());
        self::assertGreaterThan(1, self::json($esperado)['paginado']['total_funciones']);
        self::assertSame(self::json($esperado)['paginado']['total_funciones'], $json['paginado']['total_funciones']);
        self::assertStringNotContainsString('Algunos filtros no se aplicaron', $pagina->cuerpo());
        self::assertMatchesRegularExpression('#<option value="Ciencia ficción" selected>#', $pagina->cuerpo());
        self::assertStringContainsString('href="/cartelera?genero=Ciencia%20ficci%C3%B3n&amp;pagina=2"', $pagina->cuerpo());
        self::assertSame(['genero' => 'Ciencia ficción'], $json['filtros']);
        self::assertSame('/cartelera?genero=Ciencia%20ficci%C3%B3n&pagina=2', $json['paginado']['siguiente']);
        self::assertSame('/cartelera.csv?genero=Ciencia%20ficci%C3%B3n', $json['csv']);
        // El CSV, con el nombre en la URL, trae las mismas funciones.
        self::assertCount($json['paginado']['total_funciones'], self::filasDelCsv($this->pedir('/cartelera.csv?genero=ciencia+ficcion')));
    }

    public function testLosLinksDePaginaConservanLosFiltros(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera?localidad=Mercedes&pagina=2', ['por_pagina' => 1]);

        self::assertPagina($respuesta, 1, '4 funciones');
        self::assertStringContainsString('href="/cartelera?localidad=Mercedes" class="boton boton-secundario boton-chico" rel="prev"', $respuesta->cuerpo());
        self::assertStringContainsString('href="/cartelera?localidad=Mercedes&amp;pagina=3"', $respuesta->cuerpo());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function ordenes(): iterable
    {
        yield 'fecha ascendente' => ['fecha_asc', 'Metrópolis', 'Nosferatu'];
        yield 'fecha descendente' => ['fecha_desc', 'Nosferatu', 'Metrópolis'];
        yield 'precio ascendente' => ['precio_asc', 'Metrópolis + Viaje a la Luna', 'Sherlock Jr.'];
        yield 'precio descendente' => ['precio_desc', 'El gabinete del Dr. Caligari + Viaje a la Luna', 'Sherlock Jr. + Viaje a través de lo imposible'];
        yield 'título ascendente' => ['titulo_asc', 'El acorazado Potemkin', 'Sherlock Jr. + Viaje a través de lo imposible'];
        yield 'título descendente' => ['titulo_desc', 'Sherlock Jr. + Viaje a través de lo imposible', 'El acorazado Potemkin'];
    }

    #[DataProvider('ordenes')]
    public function testOrdenaPorFechaPrecioYTituloAscendenteYDescendenteYLoConservaAlPaginar(
        string $orden,
        string $primero,
        string $ultimo,
    ): void {
        $this->cargarDatosDePrueba();

        $primera = $this->pedir("/cartelera?orden={$orden}");
        $segunda = $this->pedir("/cartelera?orden={$orden}&pagina=2");
        $json = self::json($this->pedirJson("/cartelera?orden={$orden}"));
        $filasCsv = self::filasDelCsv($this->pedir("/cartelera.csv?orden={$orden}"));

        self::assertPagina($primera, 12, '15 funciones');
        self::assertPagina($segunda, 3, '15 funciones');
        preg_match_all('#<article class="tarjeta-funcion.*?</article>#s', $primera->cuerpo(), $tarjetasPrimera);
        preg_match_all('#<article class="tarjeta-funcion.*?</article>#s', $segunda->cuerpo(), $tarjetasSegunda);
        self::assertStringContainsString(htmlspecialchars($primero), $tarjetasPrimera[0][0] ?? '');
        self::assertStringContainsString(htmlspecialchars($ultimo), $tarjetasSegunda[0][2] ?? '');
        self::assertSame($primero, $json['funciones'][0]['titulo']);
        self::assertSame($primero, $filasCsv[0][2]);
        self::assertSame($ultimo, $filasCsv[14][2]);

        if ($orden === 'fecha_asc') {
            self::assertSame([], $json['filtros']);
            self::assertSame('/cartelera?pagina=2', $json['paginado']['siguiente']);
            self::assertStringContainsString('href="/cartelera?pagina=2"', $primera->cuerpo());
            self::assertStringContainsString('href="/cartelera" class="boton boton-secundario boton-chico" rel="prev"', $segunda->cuerpo());
            self::assertSame('/cartelera.csv', $json['csv']);
        } else {
            self::assertSame(['orden' => $orden], $json['filtros']);
            self::assertSame("/cartelera?orden={$orden}&pagina=2", $json['paginado']['siguiente']);
            self::assertStringContainsString("href=\"/cartelera?orden={$orden}&amp;pagina=2\"", $primera->cuerpo());
            self::assertStringContainsString("href=\"/cartelera?orden={$orden}\" class=\"boton boton-secundario boton-chico\" rel=\"prev\"", $segunda->cuerpo());
            self::assertSame("/cartelera.csv?orden={$orden}", $json['csv']);
        }
    }

    public function testFiltraPorRangoDePrecioIncluyendoLasGratuitasYLoConservaAlPaginar(): void
    {
        $this->cargarDatosDePrueba();

        $gratuitas = self::json($this->pedirJson('/cartelera?precio_min=0&precio_max=0'));
        self::assertSame(2, $gratuitas['paginado']['total_funciones']);
        self::assertSame(['A la gorra', 'A la gorra'], array_column($gratuitas['funciones'], 'precio'));
        self::assertSame(['precio_min' => '0', 'precio_max' => '0'], $gratuitas['filtros']);
        self::assertSame('/cartelera.csv?precio_min=0&precio_max=0', $gratuitas['csv']);

        $primera = $this->pedir('/cartelera?precio_min=0&precio_max=2000', ['por_pagina' => 2]);
        $segunda = $this->pedir('/cartelera?precio_min=0&precio_max=2000&pagina=2', ['por_pagina' => 2]);
        $json = self::json($this->pedirJson('/cartelera?precio_min=0&precio_max=2000', ['por_pagina' => 2]));

        self::assertPagina($primera, 2, '4 funciones');
        self::assertPagina($segunda, 2, '4 funciones');
        // Las dos a la gorra (precio 0) entran con las de hasta $ 2.000, y el paginado las reparte.
        self::assertSame(
            ['Metrópolis + Viaje a la Luna', 'El acorazado Potemkin', 'El maquinista de la General', 'Sherlock Jr. + Viaje a través de lo imposible'],
            array_column(self::filasDelCsv($this->pedir('/cartelera.csv?precio_min=0&precio_max=2000')), 2),
        );
        self::assertSame(
            ['Metrópolis + Viaje a la Luna', 'El acorazado Potemkin'],
            array_column($json['funciones'], 'titulo'),
        );
        self::assertSame(['precio_min' => '0', 'precio_max' => '2000'], $json['filtros']);
        self::assertSame('/cartelera?precio_min=0&precio_max=2000&pagina=2', $json['paginado']['siguiente']);
        self::assertStringContainsString('href="/cartelera?precio_min=0&amp;precio_max=2000&amp;pagina=2"', $primera->cuerpo());
        self::assertStringContainsString('href="/cartelera?precio_min=0&amp;precio_max=2000" class="boton boton-secundario boton-chico" rel="prev"', $segunda->cuerpo());
        self::assertSame('/cartelera.csv?precio_min=0&precio_max=2000', $json['csv']);
    }

    /**
     * Lo que podría escribir alguien a mano en la URL: con cada uno, la página responde 200 y
     * muestra la cartelera entera con el aviso.
     *
     * @return iterable<string, array{string}>
     */
    public static function parametrosInvalidos(): iterable
    {
        yield 'una fecha que no es fecha' => ['fecha=ma%C3%B1ana'];
        yield 'un día que no existe' => ['fecha=2026-02-31'];
        yield 'una fecha como arreglo' => ['fecha[]=2026-10-24'];
        yield 'un género que no existe' => ['genero=western'];
        yield 'el id de un género, como en las URL de antes' => ['genero=00000004-0000-4000-8000-000000000005'];
        yield 'un género con una inyección' => ['genero=%27+OR+1%3D1+--+'];
        yield 'un género como arreglo' => ['genero[a]=b'];
        yield 'una búsqueda como arreglo' => ['q[]=zama'];
        yield 'una búsqueda que no es UTF-8' => ['q=%C3%28'];
        yield 'una búsqueda enorme' => ['q=' . str_repeat('a', 5000)];
        yield 'una localidad como arreglo' => ['localidad[]=Luj%C3%A1n'];
        yield 'un orden que no existe' => ['orden=popularidad'];
        yield 'un orden incompleto' => ['orden=precio'];
        yield 'un orden como arreglo' => ['orden[]=precio_asc'];
        yield 'un precio mínimo negativo' => ['precio_min=-100'];
        yield 'un precio máximo que no es un número' => ['precio_max=gratis'];
        yield 'un precio mínimo mayor que el máximo' => ['precio_min=3000&precio_max=2000'];
        yield 'un precio como arreglo' => ['precio_min[]=0'];
    }

    #[DataProvider('parametrosInvalidos')]
    public function testUnParametroInvalidoNoRompeLaPagina(string $consulta): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir("/cartelera?{$consulta}");

        self::assertPagina($respuesta, 12, '15 funciones');
        self::assertStringContainsString('Algunos filtros no se aplicaron', $respuesta->cuerpo());
    }

    public function testLosParametrosQueNoConoceSeIgnoran(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera?tamano=1000&por_pagina=1000');

        self::assertPagina($respuesta, 12, '15 funciones');
        self::assertStringNotContainsString('Algunos filtros no se aplicaron', $respuesta->cuerpo());
    }

    public function testLoQueSeBuscaSeMuestraEscapado(): void
    {
        $this->cargarDatosDePrueba();

        $cuerpo = $this->pedir('/cartelera?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E%22')->cuerpo();

        self::assertStringNotContainsString('<script>alert(1)</script>', $cuerpo);
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;&quot;"', $cuerpo);
    }

    public function testUnaInyeccionEnLaBusquedaNoTraeNada(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera?q=%27+OR+%271%27%3D%271');

        self::assertPagina($respuesta, 0);
        self::assertStringContainsString('No hay funciones que coincidan con la búsqueda.', $respuesta->cuerpo());
        self::assertSame(15, $this->base->valor('SELECT COUNT(*) FROM funcion'));
    }

    public function testSinResultadosHayUnMensajePropio(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera?q=Nueve+reinas');

        self::assertPagina($respuesta, 0);
        self::assertStringContainsString('No hay funciones que coincidan con la búsqueda.', $respuesta->cuerpo());
        self::assertStringNotContainsString('class="paginado"', $respuesta->cuerpo());
    }

    public function testSinFuncionesProgramadasLoDice(): void
    {
        $respuesta = $this->pedir('/cartelera');

        self::assertPagina($respuesta, 0);
        self::assertStringContainsString('Todavía no hay funciones programadas.', $respuesta->cuerpo());
        // Sin filtros, no hay nada que limpiar.
        self::assertStringNotContainsString('Limpiar', $respuesta->cuerpo());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paginasQueNoExisten(): iterable
    {
        yield 'después de la última' => ['pagina=3'];
        yield 'muy lejos' => ['pagina=999999999'];
        yield 'la 0' => ['pagina=0'];
        yield 'negativa' => ['pagina=-1'];
        yield 'que no es un número' => ['pagina=abc'];
        yield 'con decimales' => ['pagina=1.5'];
        yield 'como arreglo' => ['pagina[]=1'];
        yield 'la segunda, sin resultados' => ['q=Nueve+reinas&pagina=2'];
    }

    #[DataProvider('paginasQueNoExisten')]
    public function testUnaPaginaQueNoExisteDa404(string $consulta): void
    {
        $this->cargarDatosDePrueba();

        self::assertSame(404, $this->pedir("/cartelera?{$consulta}")->estado());
    }

    public function testUnTamanoDePaginaInvalidoEnLaConfiguracionEsUnError(): void
    {
        foreach ([0, 101, 'doce'] as $porPagina) {
            self::assertSame(500, $this->pedir('/cartelera', ['por_pagina' => $porPagina])->estado());
        }
    }

    /**
     * Las filas del CSV, sin los encabezados, como las leería una planilla.
     *
     * @return list<list<string|null>>
     */
    private static function filasDelCsv(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringStartsWith("\u{FEFF}Fecha;Hora;Obras;Sala;Localidad;Precio\r\n", $respuesta->cuerpo());
        $archivo = fopen('php://memory', 'w+');
        fwrite($archivo, $respuesta->cuerpo());
        rewind($archivo);
        fgetcsv($archivo, null, ';', '"', '');
        $filas = [];
        while (($fila = fgetcsv($archivo, null, ';', '"', '')) !== false) {
            $filas[] = $fila;
        }
        fclose($archivo);

        return $filas;
    }

    public function testElCsvTraeTodasLasFuncionesSinPaginar(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera.csv', ['por_pagina' => 4]);

        self::assertSame('text/csv; charset=utf-8', $respuesta->cabecera('Content-Type'));
        self::assertSame('attachment; filename="cartelera.csv"', $respuesta->cabecera('Content-Disposition'));
        $filas = self::filasDelCsv($respuesta);
        self::assertCount(15, $filas);
        // Como en la página, la primera es la más próxima: Metrópolis, en una semana, a las 20.
        self::assertSame([(new DateTimeImmutable('today +7 days'))->format('Y-m-d'), '20:00', 'Metrópolis', 'Cine Club El Alambique', 'Luján'], array_slice($filas[0], 0, 5));
        self::assertContains('A la gorra', array_column($filas, 5));
        self::assertContains('2500', array_column($filas, 5));
    }

    #[DataProvider('filtros')]
    public function testElCsvRespetaLosFiltrosDeLaUrl(string $consulta, int $funciones, string $resultados): void
    {
        $this->cargarDatosDePrueba();
        $consulta = strtr($consulta, [
            '{dentro de 7 días}' => (new DateTimeImmutable('today +7 days'))->format('Y-m-d'),
            '{dentro de 28 días}' => (new DateTimeImmutable('today +28 days'))->format('Y-m-d'),
            '{Comedia}' => 'Comedia',
        ]);

        // Con páginas de una, para ver que el archivo no se corta en la primera.
        self::assertCount($funciones, self::filasDelCsv($this->pedir("/cartelera.csv?{$consulta}", ['por_pagina' => 1])));
    }

    public function testElCsvTraeLasMismasFuncionesQueLaPaginaFiltrada(): void
    {
        $this->cargarDatosDePrueba();

        $filas = self::filasDelCsv($this->pedir('/cartelera.csv?localidad=San+Antonio+de+Areco'));
        $pagina = $this->pedir('/cartelera?localidad=San+Antonio+de+Areco')->cuerpo();

        self::assertCount(3, $filas);
        foreach ($filas as $fila) {
            self::assertSame('San Antonio de Areco', $fila[4]);
            self::assertStringContainsString(htmlspecialchars((string) $fila[2]), $pagina);
        }
    }

    public function testElCsvIgnoraLaPagina(): void
    {
        $this->cargarDatosDePrueba();

        self::assertCount(15, self::filasDelCsv($this->pedir('/cartelera.csv?pagina=999')));
    }

    #[DataProvider('parametrosInvalidos')]
    public function testElCsvDejaAfueraUnFiltroInvalido(string $consulta): void
    {
        $this->cargarDatosDePrueba();

        self::assertCount(15, self::filasDelCsv($this->pedir("/cartelera.csv?{$consulta}")));
    }

    public function testElCsvSinResultadosTraeSoloLosEncabezados(): void
    {
        $this->cargarDatosDePrueba();

        self::assertSame([], self::filasDelCsv($this->pedir('/cartelera.csv?q=Nueve+reinas')));
        self::assertSame([], self::filasDelCsv($this->pedir('/cartelera.csv?q=%27+OR+%271%27%3D%271')));
    }

    public function testLaPaginaTieneElLinkAlCsvConLosFiltros(): void
    {
        $this->cargarDatosDePrueba();

        $cuerpo = $this->pedir('/cartelera?localidad=Mercedes&pagina=2&fecha=ayer', ['por_pagina' => 1])->cuerpo();

        // Sin la página ni el filtro inválido.
        self::assertStringContainsString('<a href="/cartelera.csv?localidad=Mercedes" class="boton boton-secundario boton-chico" download>Descargar CSV</a>', $cuerpo);
        self::assertStringContainsString('href="/cartelera.csv"', $this->pedir('/cartelera')->cuerpo());
    }

    public function testSinResultadosNoHayLinkAlCsv(): void
    {
        $this->cargarDatosDePrueba();

        self::assertStringNotContainsString('/cartelera.csv', $this->pedir('/cartelera?q=Nueve+reinas')->cuerpo());
    }

    public function testSeVeSinSesion(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('href="/cartelera" class="nav-enlace activo"', $respuesta->cuerpo());
    }

    public function testConAcceptJsonDevuelveLaMismaConsultaEnJson(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedirJson('/cartelera');

        self::assertSame(200, $respuesta->estado());
        self::assertSame('Accept', $respuesta->cabecera('Vary'));
        $json = self::json($respuesta);
        self::assertSame(['funciones', 'filtros', 'avisos', 'paginado', 'csv', 'busqueda', 'orden', 'filtros_activos', 'quitar_filtros'], array_keys($json));
        self::assertCount(12, $json['funciones']);
        // Cada función con lo mismo que muestra la tarjeta, y el link a su ficha.
        $primera = $json['funciones'][0];
        self::assertSame(['id', 'titulo', 'afiche', 'estado', 'precio', 'sala', 'localidad', 'fecha', 'lugares_libres', 'agotada', 'url'], array_keys($primera));
        self::assertSame("/cartelera/{$primera['id']}", $primera['url']);
        self::assertSame(
            [
                'numero' => 1,
                'total_paginas' => 2,
                'total_funciones' => 15,
                'anterior' => null,
                'siguiente' => '/cartelera?pagina=2',
                'paginas' => [
                    ['numero' => 1, 'url' => '/cartelera', 'actual' => true],
                    ['numero' => 2, 'url' => '/cartelera?pagina=2', 'actual' => false],
                ],
            ],
            $json['paginado'],
        );
        self::assertSame([], $json['avisos']);
        self::assertSame('/cartelera.csv', $json['csv']);
        // Sin filtros, un objeto vacío y no una lista.
        self::assertStringContainsString('"filtros":{}', $respuesta->cuerpo());
    }

    /**
     * El resumen de arriba de la cartelera dice cómo están ordenadas (E4-10.9): el JSON trae el
     * orden aplicado, así el buscador lo actualiza sin recargar.
     */
    public function testElJsonTraeElOrdenAplicadoConSuEtiqueta(): void
    {
        $this->cargarDatosDePrueba();

        $deSiempre = self::json($this->pedirJson('/cartelera'));
        $porPrecio = self::json($this->pedirJson('/cartelera?orden=precio_asc'));

        self::assertSame(['valor' => '', 'etiqueta' => 'Fecha: más próxima', 'leida' => 'fecha: más próxima'], $deSiempre['orden']);
        self::assertSame(['valor' => 'precio_asc', 'etiqueta' => 'Precio: menor a mayor', 'leida' => 'precio: menor a mayor'], $porPrecio['orden']);
    }

    /**
     * La cartelera como el wireframe (E4-10.9): las migas, y debajo del título cuántas funciones
     * hay y cómo están ordenadas.
     */
    public function testArribaVanLasMigasYElResumenConLaCantidadYElOrden(): void
    {
        $this->cargarDatosDePrueba();

        $documento = HTMLDocument::createFromString($this->pedir('/cartelera?orden=precio_asc')->cuerpo(), LIBXML_NOERROR);

        $migas = $documento->querySelector('nav[aria-label="Migas de pan"]');
        self::assertNotNull($migas);
        self::assertSame('/', $migas->querySelector('a')?->getAttribute('href'));
        self::assertSame('Cartelera', trim((string) $migas->querySelector('[aria-current="page"]')?->textContent));
        $resumen = $documento->querySelector('[data-cartelera-resumen]');
        self::assertNotNull($resumen);
        self::assertSame(
            '15 funciones · ordenadas por precio: menor a mayor',
            trim((string) preg_replace('/\s+/', ' ', (string) $resumen->textContent)),
        );
        // Arriba de la columna y de los resultados, no adentro de lo que redibuja el buscador.
        self::assertNull($resumen->closest('#resultados-cartelera'));
    }

    /**
     * Los filtros van en una columna plegable (E4-10.9), con los grupos en el orden del wireframe;
     * «Filtrar» sólo sin JavaScript, porque con él filtran solos.
     */
    public function testLosFiltrosVanEnUnaColumnaPlegableConLosGruposEnOrden(): void
    {
        $this->cargarDatosDePrueba();

        $documento = HTMLDocument::createFromString($this->pedir('/cartelera')->cuerpo(), LIBXML_NOERROR);

        $columna = $documento->querySelector('details[data-filtros-cartelera]');
        self::assertNotNull($columna);
        // Llega abierta: sin JavaScript se usa así; con JavaScript se cierra en el celular.
        self::assertTrue($columna->hasAttribute('open'));
        self::assertStringStartsWith('Filtros', trim((string) $columna->querySelector('summary')?->textContent));
        $formulario = $columna->querySelector('form#filtros-cartelera');
        self::assertNotNull($formulario);
        $grupos = array_map(
            static fn ($grupo): string => trim((string) ($grupo->querySelector('legend') ?? $grupo->querySelector('label'))?->textContent),
            iterator_to_array($formulario->querySelectorAll('.filtros-grupo')),
        );
        self::assertSame(['Buscar', 'Localidad', 'Fecha', 'Género', 'Precio'], $grupos);
        $filtrar = $formulario->querySelector('noscript');
        self::assertNotNull($filtrar);
        self::assertStringContainsString('Filtrar', (string) $filtrar->textContent);
        self::assertSame(1, substr_count((string) $formulario->textContent, 'Filtrar'));
    }

    public function testSinAcceptJsonSigueSiendoLaPagina(): void
    {
        $this->cargarDatosDePrueba();

        // Lo que manda un navegador al abrir la URL o al enviar el formulario.
        $respuesta = $this->pedir('/cartelera', [], ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);

        self::assertPagina($respuesta, 12, '15 funciones');
        self::assertSame('text/html; charset=utf-8', $respuesta->cabecera('Content-Type'));
        self::assertSame('Accept', $respuesta->cabecera('Vary'));
    }

    #[DataProvider('filtros')]
    public function testElJsonTraeLasMismasFuncionesQueLaPagina(string $consulta, int $tarjetas, string $resultados): void
    {
        $this->cargarDatosDePrueba();
        $consulta = strtr($consulta, [
            '{dentro de 7 días}' => (new DateTimeImmutable('today +7 days'))->format('Y-m-d'),
            '{dentro de 28 días}' => (new DateTimeImmutable('today +28 days'))->format('Y-m-d'),
            '{Comedia}' => 'Comedia',
        ]);

        $json = self::json($this->pedirJson("/cartelera?{$consulta}"));
        $pagina = $this->pedir("/cartelera?{$consulta}");
        preg_match_all('#<article class="tarjeta-funcion.*?href="(/cartelera/[^"]+)"#s', $pagina->cuerpo(), $enLaPagina);

        self::assertPagina($pagina, $tarjetas, $resultados);
        self::assertSame($tarjetas, $json['paginado']['total_funciones']);
        self::assertSame($enLaPagina[1], array_column($json['funciones'], 'url'));
        self::assertSame([], $json['avisos']);
    }

    public function testElJsonConservaLosFiltrosEnLosLinks(): void
    {
        $this->cargarDatosDePrueba();

        $json = self::json($this->pedirJson('/cartelera?localidad=Mercedes&pagina=2', ['por_pagina' => 1]));

        self::assertSame(['localidad' => 'Mercedes'], $json['filtros']);
        self::assertSame(2, $json['paginado']['numero']);
        self::assertSame('/cartelera?localidad=Mercedes', $json['paginado']['anterior']);
        self::assertSame('/cartelera?localidad=Mercedes&pagina=3', $json['paginado']['siguiente']);
        self::assertSame('/cartelera.csv?localidad=Mercedes', $json['csv']);
    }

    #[DataProvider('parametrosInvalidos')]
    public function testElJsonAvisaUnFiltroInvalido(string $consulta): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedirJson("/cartelera?{$consulta}");

        self::assertSame(200, $respuesta->estado());
        $json = self::json($respuesta);
        self::assertSame(15, $json['paginado']['total_funciones']);
        self::assertNotEmpty($json['avisos']);
    }

    public function testSinResultadosElJsonTraeLaListaVacia(): void
    {
        $this->cargarDatosDePrueba();

        $json = self::json($this->pedirJson('/cartelera?q=Nueve+reinas'));

        self::assertSame([], $json['funciones']);
        self::assertSame(0, $json['paginado']['total_funciones']);
        self::assertNull($json['paginado']['siguiente']);
    }

    #[DataProvider('paginasQueNoExisten')]
    public function testEnJsonUnaPaginaQueNoExisteDa404EnJson(string $consulta): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedirJson("/cartelera?{$consulta}");

        self::assertSame(404, $respuesta->estado());
        self::assertSame(['error' => 'No encontramos lo que buscabas.'], self::json($respuesta));
    }

    public function testDetalleDeFuncionProgramada(): void
    {
        $this->cargarDatosDePrueba();

        $id = (string) $this->base->valor("SELECT id FROM funcion WHERE estado = 'programada' ORDER BY fecha_hora LIMIT 1");
        $salaId = (string) $this->base->valor('SELECT sala_id FROM funcion WHERE id = ?', [$id]);
        $respuesta = $this->pedir("/cartelera/{$id}");

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('Metrópolis', $cuerpo);
        // La obra en su fila (E4-10.10): año · género · duración, y debajo el director.
        self::assertStringContainsString('Fritz Lang', $cuerpo);
        self::assertStringContainsString('153 min', $cuerpo);
        self::assertStringContainsString('Cine Club El Alambique', $cuerpo);
        self::assertStringContainsString('href="/salas/' . $salaId . '"', $cuerpo);
        self::assertStringContainsString('San Martín 420, Luján', $cuerpo);
        // Debajo de la obra, su sinopsis; en la tarjeta de la sala, la capacidad y la descripción.
        $sinopsis = (string) $this->base->valor("SELECT sinopsis FROM obra WHERE titulo = 'Metrópolis'");
        $descripcion = (string) $this->base->valor('SELECT descripcion FROM sala WHERE id = ?', [$salaId]);
        self::assertStringContainsString(htmlspecialchars($sinopsis), $cuerpo);
        self::assertStringContainsString(htmlspecialchars($descripcion), $cuerpo);
        self::assertStringContainsString('80 butacas', $cuerpo);
        self::assertStringContainsString('$ 2.500', $cuerpo);
        // La línea de estados del wireframe marca «PROGRAMADA» como el estado actual (E3-13.F10).
        self::assertMatchesRegularExpression('/aria-current="step">\s*<span>PROGRAMADA<\/span>/', $cuerpo);
        self::assertStringNotContainsString('Esta función fue cancelada.', $cuerpo);
    }

    public function testDetalleDeFuncionEnVotacionDeTitulos(): void
    {
        $this->cargarDatosDePrueba();

        $id = (string) $this->base->valor("SELECT id FROM funcion WHERE estado = 'programada' ORDER BY fecha_hora LIMIT 1");
        $this->base->ejecutar("UPDATE funcion SET estado = 'en_votacion_titulos', fecha_hora = NULL WHERE id = ?", [$id]);
        $this->base->ejecutar("UPDATE funcion_obra SET estado = 'candidata' WHERE funcion_id = ?", [$id]);

        $respuesta = $this->pedir("/cartelera/{$id}");

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('EN VOTACIÓN DE TÍTULOS', $cuerpo);
        self::assertStringContainsString('Obras candidatas en votación', $cuerpo);
        self::assertTrue(
            str_contains($cuerpo, 'Fecha a confirmar') || str_contains($cuerpo, 'Fecha a coordinar con la sala tras el cierre de la votación'),
            'La ficha debe indicar que la fecha no está confirmada o está a coordinar tras la votación.',
        );
    }

    public function testDetalleDeFuncionCanceladaMuestraAvisoDeCancelacion(): void
    {
        $this->cargarDatosDePrueba();

        $id = (string) $this->base->valor("SELECT id FROM funcion WHERE estado = 'programada' ORDER BY fecha_hora LIMIT 1");
        $this->base->ejecutar("UPDATE funcion SET estado = 'cancelada' WHERE id = ?", [$id]);

        $respuesta = $this->pedir("/cartelera/{$id}");

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('Función cancelada', $cuerpo);
        self::assertStringContainsString('Esta función fue cancelada.', $cuerpo);
        // Sigue mostrando qué era: las obras, la sala y la fecha.
        self::assertStringContainsString('Metrópolis', $cuerpo);
        self::assertStringContainsString('Cine Club El Alambique', $cuerpo);
        self::assertStringNotContainsString('Fecha a confirmar', $cuerpo);
        self::assertStringNotContainsString('>PROGRAMADA<', $cuerpo);
    }

    public function testLaFichaOfreceReservarYSinSesionPideIngresar(): void
    {
        $this->cargarDatosDePrueba();
        $id = (string) $this->base->valor("SELECT id FROM funcion WHERE estado = 'programada' ORDER BY fecha_hora LIMIT 1");

        $cuerpo = $this->pedir("/cartelera/{$id}")->cuerpo();

        self::assertStringContainsString('Reservá tus lugares', $cuerpo);
        self::assertStringContainsString('href="/cuenta/ingresar"', $cuerpo);
        self::assertStringNotContainsString('action="/funcion/reservar/', $cuerpo);
    }

    public function testConSesionLaFichaMandaLaReservaAFuncionesConElTokenCsrf(): void
    {
        $this->cargarDatosDePrueba();
        $id = (string) $this->base->valor("SELECT id FROM funcion WHERE estado = 'programada' ORDER BY fecha_hora LIMIT 1");
        $cupo = (int) $this->base->valor('SELECT cupo FROM funcion WHERE id = ?', [$id]);

        $cuerpo = $this->pedir("/cartelera/{$id}", [], [
            'X-Nginx-Secreto' => 'secreto-de-nginx',
            'X-Usuario-Id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'X-Rol' => 'espectador',
        ])->cuerpo();

        // Al subsistema funciones, que sí lleva el prefijo: la ficha es de programación.
        self::assertStringContainsString('action="/funcion/reservar/' . $id . '" method="POST"', $cuerpo);
        self::assertMatchesRegularExpression('/name="_csrf" value="[0-9a-f]{64}"/', $cuerpo);
        // Sin funciones que responda, no hay tope por persona que mostrar (lo sabe sólo funciones,
        // IC-463): el campo llega hasta el cupo y la reserva la controla funciones igual.
        self::assertMatchesRegularExpression('/name="cantidad"[^>]*min="1"[^>]*max="' . $cupo . '"/s', $cuerpo);
    }

    public function testUnaFuncionCanceladaOQueYaEmpezoNoOfreceReservar(): void
    {
        $this->cargarDatosDePrueba();
        $ids = $this->base->filas("SELECT id FROM funcion WHERE estado = 'programada' ORDER BY fecha_hora LIMIT 2");
        $this->base->ejecutar("UPDATE funcion SET estado = 'cancelada' WHERE id = ?", [$ids[0]['id']]);
        // Ya empezó: programada, con la hora pasada. Sin el CHECK de fecha futura, que lo pone la aplicación.
        $this->base->ejecutar('UPDATE funcion SET fecha_hora = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$ids[1]['id']]);

        foreach ($ids as $fila) {
            self::assertStringNotContainsString('Reservá tus lugares', $this->pedir("/cartelera/{$fila['id']}")->cuerpo());
        }
    }

    public function testDetalleDeFuncionInexistenteResponde404(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera/' . Uuid::nuevo());

        self::assertSame(404, $respuesta->estado());
    }

    public function testDetalleConIdInvalidoResponde404(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir('/cartelera/no-es-un-uuid');

        self::assertSame(404, $respuesta->estado());
    }

    public function testDetalleDeFuncionConSalaDeshabilitadaResponde404(): void
    {
        $this->cargarDatosDePrueba();

        $id = (string) $this->base->valor("SELECT id FROM funcion WHERE estado = 'programada' ORDER BY fecha_hora LIMIT 1");
        $salaId = (string) $this->base->valor('SELECT sala_id FROM funcion WHERE id = ?', [$id]);
        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$salaId]);

        $respuesta = $this->pedir("/cartelera/{$id}");

        self::assertSame(404, $respuesta->estado());
    }

    public function testDetalleDeFuncionConSalaDadaDeBajaResponde404(): void
    {
        $this->cargarDatosDePrueba();

        $id = (string) $this->base->valor("SELECT id FROM funcion WHERE estado = 'programada' ORDER BY fecha_hora LIMIT 1");
        $salaId = (string) $this->base->valor('SELECT sala_id FROM funcion WHERE id = ?', [$id]);
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$salaId]);

        $respuesta = $this->pedir("/cartelera/{$id}");

        self::assertSame(404, $respuesta->estado());
    }

    // =========================================================================
    // Votación de títulos (E3-12.3)
    // =========================================================================

    /**
     * Criterio E3-12.3: «La ficha muestra las candidatas y el voto del usuario».
     */
    /**
     * F-01.5: los afiches de las candidatas apuntan a la ruta que los sirve, /obras/{id}/afiche, y
     * no al nombre del archivo, que el navegador no tiene cómo pedir. Pasaba en producción: lo
     * arregló IC-292 en dev, sin una prueba que lo sostuviera.
     */
    public function testLosAfichesDeLaVotacionApuntanALaRutaQueLosSirve(): void
    {
        $this->cargarDatosDePrueba();
        $salaId = (string) $this->base->valor('SELECT id FROM sala WHERE habilitada = TRUE AND dada_de_baja_en IS NULL LIMIT 1');
        $obras = $this->base->filas('SELECT id, afiche FROM obra WHERE afiche IS NOT NULL ORDER BY id LIMIT 2');
        self::assertCount(2, $obras, 'Los datos de prueba traen obras con afiche.');
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar("INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_votacion_titulos', 2000, 50)", [$funcionId, $salaId]);
        foreach ($obras as $obra) {
            $this->base->ejecutar("INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'candidata')", [$funcionId, $obra['id']]);
        }

        foreach ([[], ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => Uuid::nuevo(), 'X-Rol' => 'espectador']] as $cabeceras) {
            $ficha = $this->pedir("/cartelera/{$funcionId}", cabeceras: $cabeceras)->cuerpo();
            foreach ($obras as $obra) {
                self::assertStringContainsString("src=\"/obras/{$obra['id']}/afiche\"", $ficha);
                self::assertStringNotContainsString((string) $obra['afiche'], $ficha);
                self::assertSame(200, $this->pedir("/obras/{$obra['id']}/afiche")->estado());
            }
        }
    }

    public function testFichaDeFuncionEnVotacionMuestraCandidatasYVotoDelUsuario(): void
    {
        $this->cargarDatosDePrueba();

        $salaId = (string) $this->base->valor("SELECT id FROM sala WHERE habilitada = TRUE AND dada_de_baja_en IS NULL LIMIT 1");
        $obras = $this->base->filas("SELECT id, titulo FROM obra ORDER BY id LIMIT 2");
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_votacion_titulos', 2000, 50)",
            [$funcionId, $salaId],
        );
        foreach ($obras as $obra) {
            $this->base->ejecutar(
                "INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'candidata')",
                [$funcionId, $obra['id']],
            );
        }

        // 1. Visitante sin sesión: ve las obras candidatas y la invitación a iniciar sesión para votar
        $sinSesion = $this->pedir("/cartelera/{$funcionId}");
        self::assertSame(200, $sinSesion->estado());
        self::assertStringContainsString('VOTACIÓN ABIERTA', $sinSesion->cuerpo());
        self::assertStringContainsString($obras[0]['titulo'], $sinSesion->cuerpo());
        self::assertStringContainsString($obras[1]['titulo'], $sinSesion->cuerpo());
        self::assertStringContainsString('Iniciá sesión para votar', $sinSesion->cuerpo());
        self::assertStringNotContainsString('Tu voto actual', $sinSesion->cuerpo());

        // 2. Espectador autenticado: ve el formulario para votar
        $espectadorId = Uuid::nuevo();
        $conSesion = $this->pedir("/cartelera/{$funcionId}", cabeceras: [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $espectadorId,
            'X-Rol' => 'espectador',
        ]);
        self::assertSame(200, $conSesion->estado());
        self::assertStringContainsString('Votar por este título', $conSesion->cuerpo());

        // 3. Emite su voto por la primera obra
        $votar = $this->post(
            "/cartelera/{$funcionId}/votar",
            ['obra_id' => $obras[0]['id']],
            $espectadorId,
        );
        self::assertSame(303, $votar->estado());
        self::assertSame("/cartelera/{$funcionId}?votado=1", $votar->cabecera('Location'));

        // 4. Al volver a la ficha, muestra «Tu voto actual» y opción de cambiar el voto
        $despuesDeVotar = $this->pedir("/cartelera/{$funcionId}", cabeceras: [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $espectadorId,
            'X-Rol' => 'espectador',
        ]);
        self::assertSame(200, $despuesDeVotar->estado());
        self::assertStringContainsString('Tu voto actual', $despuesDeVotar->cuerpo());
        self::assertStringContainsString('Cambiar mi voto', $despuesDeVotar->cuerpo());
        self::assertSame($obras[0]['id'], (string) $this->base->valor("SELECT obra_id FROM voto WHERE funcion_id = ? AND usuario_id = ?", [$funcionId, $espectadorId]));
    }

    public function testCambiarVotoMientrasLaVotacionEstaAbierta(): void
    {
        $this->cargarDatosDePrueba();

        $salaId = (string) $this->base->valor("SELECT id FROM sala WHERE habilitada = TRUE AND dada_de_baja_en IS NULL LIMIT 1");
        $obras = $this->base->filas("SELECT id, titulo FROM obra ORDER BY id LIMIT 2");
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_votacion_titulos', 2000, 50)",
            [$funcionId, $salaId],
        );
        foreach ($obras as $obra) {
            $this->base->ejecutar(
                "INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'candidata')",
                [$funcionId, $obra['id']],
            );
        }

        $espectadorId = Uuid::nuevo();

        // Vota la primera obra
        $this->post("/cartelera/{$funcionId}/votar", ['obra_id' => $obras[0]['id']], $espectadorId);
        self::assertSame(1, (int) $this->base->valor("SELECT COUNT(*) FROM voto WHERE funcion_id = ?", [$funcionId]));

        // Cambia el voto por la segunda obra
        $cambio = $this->post("/cartelera/{$funcionId}/votar", ['obra_id' => $obras[1]['id']], $espectadorId);
        self::assertSame(303, $cambio->estado());

        // Comprobamos que sigue habiendo 1 solo voto y ahora apunta a la segunda obra
        self::assertSame(1, (int) $this->base->valor("SELECT COUNT(*) FROM voto WHERE funcion_id = ?", [$funcionId]));
        self::assertSame($obras[1]['id'], (string) $this->base->valor("SELECT obra_id FROM voto WHERE funcion_id = ? AND usuario_id = ?", [$funcionId, $espectadorId]));
    }

    public function testNoSePuedeVotarEnFuncionQueNoEstaEnVotacion(): void
    {
        $this->cargarDatosDePrueba();

        // Tomamos una función programada
        $funcionId = (string) $this->base->valor("SELECT id FROM funcion WHERE estado = 'programada' LIMIT 1");
        $obraId = (string) $this->base->valor("SELECT obra_id FROM funcion_obra WHERE funcion_id = ? LIMIT 1", [$funcionId]);
        $espectadorId = Uuid::nuevo();

        $respuesta = $this->post("/cartelera/{$funcionId}/votar", ['obra_id' => $obraId], $espectadorId);
        self::assertSame(303, $respuesta->estado());
        self::assertStringContainsString('error_voto', $respuesta->cabecera('Location'));
        self::assertSame(0, (int) $this->base->valor("SELECT COUNT(*) FROM voto WHERE funcion_id = ?", [$funcionId]));
    }

    public function testNoSePuedeVotarPorObraQueNoEsCandidata(): void
    {
        $this->cargarDatosDePrueba();

        $salaId = (string) $this->base->valor("SELECT id FROM sala WHERE habilitada = TRUE AND dada_de_baja_en IS NULL LIMIT 1");
        $obras = $this->base->filas("SELECT id, titulo FROM obra ORDER BY id LIMIT 3");
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_votacion_titulos', 2000, 50)",
            [$funcionId, $salaId],
        );
        $this->base->ejecutar("INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'candidata')", [$funcionId, $obras[0]['id']]);
        $this->base->ejecutar("INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'candidata')", [$funcionId, $obras[1]['id']]);

        $espectadorId = Uuid::nuevo();
        $respuesta = $this->post("/cartelera/{$funcionId}/votar", ['obra_id' => $obras[2]['id']], $espectadorId);
        self::assertSame(303, $respuesta->estado());
        self::assertStringContainsString('error_voto', $respuesta->cabecera('Location'));
        self::assertSame(0, (int) $this->base->valor("SELECT COUNT(*) FROM voto WHERE funcion_id = ?", [$funcionId]));
    }

    public function testUsuarioSinSesionNoPuedeVotar(): void
    {
        $this->cargarDatosDePrueba();

        $funcionId = Uuid::nuevo();
        $respuesta = $this->post("/cartelera/{$funcionId}/votar", ['obra_id' => Uuid::nuevo()], usuario: null);
        self::assertSame(401, $respuesta->estado());
    }

    /**
     * Criterio E3-12.4: La ficha de la función con votación cerrada muestra únicamente el título ganador.
     */
    public function testFichaDeFuncionCerradaMuestraTituloGanadorYNoPermiteVotar(): void
    {
        $this->cargarDatosDePrueba();

        $salaId = (string) $this->base->valor("SELECT id FROM sala WHERE habilitada = TRUE AND dada_de_baja_en IS NULL LIMIT 1");
        $obras = $this->base->filas("SELECT id, titulo FROM obra ORDER BY id LIMIT 2");
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_acuerdo_fecha', 2000, 50)",
            [$funcionId, $salaId],
        );
        $this->base->ejecutar("INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'elegida')", [$funcionId, $obras[0]['id']]);
        $this->base->ejecutar("INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'candidata')", [$funcionId, $obras[1]['id']]);

        $ficha = $this->pedir("/cartelera/{$funcionId}");
        self::assertSame(200, $ficha->estado());
        self::assertStringContainsString($obras[0]['titulo'], $ficha->cuerpo());
        self::assertStringNotContainsString($obras[1]['titulo'], $ficha->cuerpo());
        self::assertStringNotContainsString('VOTACIÓN ABIERTA', $ficha->cuerpo());

        // Intentar votar en la función cerrada es rechazado
        $espectadorId = Uuid::nuevo();
        $voto = $this->post("/cartelera/{$funcionId}/votar", ['obra_id' => $obras[0]['id']], $espectadorId);
        self::assertSame(303, $voto->estado());
        self::assertStringContainsString('error_voto', $voto->cabecera('Location'));
    }

    /**
     * Criterio E3-12.5: La cartelera y la ficha muestran las funciones en votación.
     * Una función en votación se distingue de una programada.
     */
    public function testCarteleraMuestraFuncionesEnVotacionDistinguendolasDeLasProgramadas(): void
    {
        $this->cargarDatosDePrueba();

        $salaId = (string) $this->base->valor("SELECT id FROM sala WHERE habilitada = TRUE AND dada_de_baja_en IS NULL LIMIT 1");
        $obra = $this->base->fila("SELECT id, titulo FROM obra ORDER BY id LIMIT 1");
        $funcionVotacionId = Uuid::nuevo();

        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_votacion_titulos', 1500, 45)",
            [$funcionVotacionId, $salaId],
        );
        $this->base->ejecutar(
            "INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'candidata')",
            [$funcionVotacionId, $obra['id']],
        );

        // 1. En HTML de /cartelera (página 2, donde se ubica la función en votación tras las programadas):
        $respuesta = $this->pedir('/cartelera?pagina=2');
        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();

        // Se distingue la función en votación
        self::assertStringContainsString('EN VOTACIÓN DE TÍTULOS', $cuerpo);
        self::assertStringContainsString('insignia-votacion', $cuerpo);
        self::assertStringContainsString('Votar títulos', $cuerpo);
        self::assertStringContainsString("/cartelera/{$funcionVotacionId}", $cuerpo);

        // Y convive con las programadas
        self::assertStringContainsString('PROGRAMADA', $cuerpo);
        self::assertStringContainsString('Ver función', $cuerpo);

        // 2. En JSON de /cartelera (Accept: application/json)
        $respuestaJson = $this->pedirJson('/cartelera?pagina=2');
        self::assertSame(200, $respuestaJson->estado());
        $datos = self::json($respuestaJson);

        $funciones = $datos['funciones'];
        $enVotacionEnJson = array_values(array_filter($funciones, static fn (array $f): bool => $f['id'] === $funcionVotacionId));
        self::assertCount(1, $enVotacionEnJson);
        self::assertSame('EN_VOTACION_TITULOS', $enVotacionEnJson[0]['estado']);
        self::assertNull($enVotacionEnJson[0]['fecha']);
        self::assertSame("/cartelera/{$funcionVotacionId}", $enVotacionEnJson[0]['url']);
    }

    public function testVotarFranjaAcuerdoEnCarteleraYCambiarElVoto(): void
    {
        $this->cargarDatosDePrueba();
        $obra = $this->base->fila('SELECT id FROM obra LIMIT 1') ?? self::fail();
        $sala = $this->base->fila('SELECT id FROM sala LIMIT 1') ?? self::fail();
        $funcionAcuerdoId = Uuid::nuevo();

        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_acuerdo_fecha', 2000, 50)",
            [$funcionAcuerdoId, $sala['id']],
        );
        $this->base->ejecutar(
            "INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'elegida')",
            [$funcionAcuerdoId, $obra['id']],
        );

        $usuarioId = Uuid::nuevo();

        // 1. Emitir voto a la franja
        $respuesta = $this->post(
            "/cartelera/{$funcionAcuerdoId}/votar",
            ['franja_id' => 'franja-mock-0'],
            $usuarioId,
            'espectador',
        );

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/cartelera/{$funcionAcuerdoId}?votado=1", $respuesta->cabecera('Location'));

        // 2. Cambiar voto a otra franja
        $respuestaCambio = $this->post(
            "/cartelera/{$funcionAcuerdoId}/votar",
            ['franja_id' => 'franja-mock-1'],
            $usuarioId,
            'espectador',
        );

        self::assertSame(303, $respuestaCambio->estado());
        self::assertSame("/cartelera/{$funcionAcuerdoId}?votado=1", $respuestaCambio->cabecera('Location'));

        // 3. Vía JSON
        $respuestaJson = $this->post(
            "/cartelera/{$funcionAcuerdoId}/acuerdo/votar",
            ['franja_id' => 'franja-mock-0'],
            $usuarioId,
            'espectador',
            ['Accept' => 'application/json'],
        );

        self::assertSame(200, $respuestaJson->estado());
    }

    public function testVotarFranjaEnFuncionQueNoEstaEnAcuerdoRechaza(): void
    {
        $this->cargarDatosDePrueba();
        $funcion = $this->base->fila("SELECT id FROM funcion WHERE estado = 'programada' LIMIT 1") ?? self::fail();

        $respuesta = $this->post(
            "/cartelera/{$funcion['id']}/votar",
            ['franja_id' => 'franja-mock-0'],
            Uuid::nuevo(),
            'espectador',
        );

        self::assertSame(303, $respuesta->estado());
        self::assertStringContainsString('error_voto=', $respuesta->cabecera('Location') ?? '');
    }

    /**
     * E3-13.F7: «✓ Me sirve» en una franja ya marcada la quita, y vuelve a la ficha avisándolo.
     */
    public function testQuitarElMeSirveDeUnaFranja(): void
    {
        [$funcionId] = $this->funcionEnAcuerdo();

        $respuesta = $this->post("/cartelera/{$funcionId}/votar", ['franja_id' => 'franja-mock-0', 'quitar' => '1'], Uuid::nuevo(), 'espectador');

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/cartelera/{$funcionId}?quitado=1", $respuesta->cabecera('Location'));
    }

    /**
     * E3-13.F7: el organizador no marca las franjas de su propia función (se acercaría solo al
     * mínimo), ni la ficha le ofrece hacerlo. El voto vuelve con el motivo, no con una página 500.
     */
    public function testElOrganizadorNoMarcaLasFranjasDeSuFuncion(): void
    {
        [$funcionId, $organizadorId] = $this->funcionEnAcuerdo();

        $respuesta = $this->post("/cartelera/{$funcionId}/votar", ['franja_id' => 'franja-mock-0'], $organizadorId, 'organizador');

        self::assertSame(303, $respuesta->estado());
        self::assertStringContainsString('error_voto=', $respuesta->cabecera('Location') ?? '');
        self::assertStringContainsString(rawurlencode('Sos el organizador de esta función'), $respuesta->cabecera('Location') ?? '');

        $ficha = $this->pedir("/cartelera/{$funcionId}", [], ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $organizadorId, 'X-Rol' => 'organizador']);
        self::assertStringNotContainsString('data-formulario-votar-franja', $ficha->cuerpo());
    }

    /**
     * F-01.4: el aviso del voto vuelve a la ficha firmado, y la ficha lo muestra. La misma dirección
     * con otro texto, sin la firma o en otra función no muestra nada: si no, cualquiera armaría un
     * link del sitio con el mensaje que quisiera.
     */
    public function testElAvisoDeLaFichaSoloSeMuestraConSuFirma(): void
    {
        [$funcionId, $organizadorId] = $this->funcionEnAcuerdo();
        $organizador = ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $organizadorId, 'X-Rol' => 'organizador'];
        $voto = $this->post("/cartelera/{$funcionId}/votar", ['franja_id' => 'franja-mock-0'], $organizadorId, 'organizador');
        $direccion = $voto->cabecera('Location') ?? self::fail('El voto rechazado vuelve a la ficha.');
        self::assertStringContainsString('firma=', $direccion);

        self::assertStringContainsString('Sos el organizador de esta función', $this->pedir($direccion, [], $organizador)->cuerpo());

        $inventado = "/cartelera/{$funcionId}?error_voto=" . rawurlencode('Tu cuenta fue suspendida: escribí a soporte@ejemplo.com');
        self::assertStringNotContainsString('soporte@ejemplo.com', $this->pedir($inventado)->cuerpo());
        $conFirmaAjena = $inventado . '&' . (string) parse_url($direccion, PHP_URL_QUERY);
        self::assertStringNotContainsString('soporte@ejemplo.com', $this->pedir($conFirmaAjena)->cuerpo());

        $otraFuncion = $this->base->fila('SELECT id FROM funcion WHERE id <> ? LIMIT 1', [$funcionId]) ?? self::fail();
        $mismoAvisoEnOtra = str_replace($funcionId, (string) $otraFuncion['id'], $direccion);
        self::assertStringNotContainsString('Sos el organizador de esta función', $this->pedir($mismoAvisoEnOtra, [], $organizador)->cuerpo());
    }

    /**
     * E3-13.F7: una franja que no es del acuerdo se rechaza en programación, con el motivo: el 422
     * de funciones habría llegado como una página 500.
     */
    public function testVotarUnaFranjaQueNoEsDelAcuerdoRechazaConElMotivo(): void
    {
        [$funcionId] = $this->funcionEnAcuerdo();

        $respuesta = $this->post("/cartelera/{$funcionId}/votar", ['franja_id' => 'franja-de-otro-acuerdo'], Uuid::nuevo(), 'espectador');

        self::assertSame(303, $respuesta->estado());
        self::assertStringContainsString(rawurlencode('no es de este acuerdo'), $respuesta->cabecera('Location') ?? '');
    }

    /**
     * E3-13.F8: la ficha dice hasta cuándo se marca «Me sirve»: las 23:59 del día anterior a la
     * primera franja, que en el doble de funciones es a diez días.
     */
    public function testLaFichaDiceLaFechaLimiteDelAcuerdo(): void
    {
        [$funcionId] = $this->funcionEnAcuerdo();

        $cuerpo = $this->pedir("/cartelera/{$funcionId}")->cuerpo();

        self::assertStringContainsString(TarjetaDeFuncion::diaMesYHora(new DateTimeImmutable('+9 days 23:59')), $cuerpo);
    }

    /**
     * E3-13.F8: pasada la fecha límite no se marca más «Me sirve», aunque el acuerdo siga abierto
     * porque una franja llegó al mínimo. El voto vuelve con el motivo y la ficha no ofrece marcar.
     */
    public function testPasadaLaFechaLimiteNoSeMarcaMeSirve(): void
    {
        [$funcionId] = $this->funcionEnAcuerdo();
        file_put_contents(sys_get_temp_dir() . '/acuerdo_estado_global.txt', 'vencido');
        $espectador = Uuid::nuevo();

        $respuesta = $this->post("/cartelera/{$funcionId}/votar", ['franja_id' => 'franja-mock-1'], $espectador, 'espectador');

        self::assertSame(303, $respuesta->estado());
        self::assertStringContainsString(rawurlencode('Ya pasó la fecha límite'), $respuesta->cabecera('Location') ?? '');
        $ficha = $this->pedir("/cartelera/{$funcionId}", [], ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $espectador, 'X-Rol' => 'espectador']);
        self::assertStringNotContainsString('data-formulario-votar-franja', $ficha->cuerpo());
    }

    /**
     * Una función en acuerdo de fecha, con su obra elegida, en una sala de los datos de prueba.
     *
     * @return array{0: string, 1: string} el id de la función y el de su organizador
     */
    private function funcionEnAcuerdo(): array
    {
        $this->cargarDatosDePrueba();
        $obra = $this->base->fila('SELECT id FROM obra ORDER BY id LIMIT 1') ?? self::fail();
        $sala = $this->base->fila('SELECT id, organizador_id FROM sala WHERE habilitada = TRUE AND dada_de_baja_en IS NULL LIMIT 1') ?? self::fail();
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_acuerdo_fecha', 2000, 50)",
            [$funcionId, $sala['id']],
        );
        $this->base->ejecutar("INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'elegida')", [$funcionId, $obra['id']]);

        return [$funcionId, (string) $sala['organizador_id']];
    }

    /**
     * E3-13.F1: la función en acuerdo de fecha sale en la cartelera, para que el público la
     * encuentre y vote, como las que están en votación de títulos.
     */
    public function testCarteleraMuestraLasFuncionesEnAcuerdoDeFecha(): void
    {
        [$funcionId] = $this->funcionEnAcuerdo();

        // Sin fecha, va después de las programadas: en la última página.
        $datos = self::json($this->pedirJson('/cartelera?pagina=2'));
        $enAcuerdo = array_values(array_filter($datos['funciones'], static fn (array $f): bool => $f['id'] === $funcionId));
        self::assertCount(1, $enAcuerdo);
        self::assertSame('EN_ACUERDO_FECHA', $enAcuerdo[0]['estado']);
        self::assertNull($enAcuerdo[0]['fecha']);

        $cuerpo = $this->pedir('/cartelera?pagina=2')->cuerpo();
        self::assertStringContainsString('EN ACUERDO DE FECHA', $cuerpo);
        self::assertStringContainsString("/cartelera/{$funcionId}", $cuerpo);
        // E3-13.F9: con el acuerdo abierto, la tarjeta dice cuántas franjas hay, como el wireframe.
        self::assertSame('2 franjas propuestas', $enAcuerdo[0]['franjas_propuestas']);
        self::assertStringContainsString('2 franjas propuestas', $cuerpo);
    }

    /**
     * E3-13.F9: si el acuerdo cerró sin quórum, la tarjeta lo dice y no ofrece «Votar fecha». El
     * estado del acuerdo vive en funciones: antes la cartelera no le preguntaba.
     */
    public function testLaTarjetaDeUnaFuncionCuyoAcuerdoCerroSinQuorumLoDice(): void
    {
        [$funcionId] = $this->funcionEnAcuerdo();
        file_put_contents(sys_get_temp_dir() . '/acuerdo_estado_global.txt', 'sin_minimo');

        $datos = self::json($this->pedirJson('/cartelera?pagina=2'));
        $tarjeta = array_values(array_filter($datos['funciones'], static fn (array $f): bool => $f['id'] === $funcionId));
        self::assertSame('ACUERDO_SIN_MINIMO', $tarjeta[0]['estado']);

        $cuerpo = $this->pedir('/cartelera?pagina=2')->cuerpo();
        self::assertStringContainsString('ACUERDO SIN MÍNIMO', $cuerpo);
        self::assertStringNotContainsString('Votar fecha', $cuerpo);
    }

    /**
     * E3-13.F9: si funciones no responde, la cartelera sale igual, con la tarjeta como antes.
     */
    public function testSinFuncionesLaCarteleraSaleIgual(): void
    {
        [$funcionId] = $this->funcionEnAcuerdo();
        file_put_contents(sys_get_temp_dir() . '/acuerdo_estado_global.txt', 'caido');

        $respuesta = $this->pedir('/cartelera?pagina=2');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString("/cartelera/{$funcionId}", $respuesta->cuerpo());
    }

    /**
     * E3-13.F1: recién cerrada la votación, sin franjas propuestas, la ficha lo dice (sin franjas
     * de ejemplo) y le ofrece al organizador proponerlas.
     */
    public function testFichaSinFranjasPropuestasLoDiceYLeOfreceAlOrganizadorProponerlas(): void
    {
        [$funcionId, $organizadorId] = $this->funcionEnAcuerdo();
        file_put_contents(sys_get_temp_dir() . '/acuerdo_estado_global.txt', 'ninguno');

        $publica = $this->pedir("/cartelera/{$funcionId}")->cuerpo();
        self::assertStringContainsString('todavía no propuso las franjas', $publica);
        self::assertStringNotContainsString('septiembre', $publica);
        self::assertStringNotContainsString('name="franja_id"', $publica);
        self::assertStringNotContainsString('proponer-franjas', $publica);

        $delOrganizador = $this->pedir("/cartelera/{$funcionId}", [], [
            'X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $organizadorId, 'X-Rol' => 'organizador',
        ])->cuerpo();
        self::assertStringContainsString("/organizador/funciones/{$funcionId}/proponer-franjas", $delOrganizador);
        self::assertStringContainsString('Proponer franjas', $delOrganizador);
    }

    /**
     * E3-13.F3: la obra ganadora muestra los votos que recibió en la votación de títulos, no un
     * número fijo de la plantilla.
     */
    public function testFichaEnAcuerdoMuestraLosVotosRealesDeLaObraGanadora(): void
    {
        [$funcionId] = $this->funcionEnAcuerdo();
        $obraId = (string) $this->base->valor("SELECT obra_id FROM funcion_obra WHERE funcion_id = ?", [$funcionId]);
        foreach ([Uuid::nuevo(), Uuid::nuevo()] as $votante) {
            $this->base->ejecutar('INSERT INTO voto (funcion_id, usuario_id, obra_id) VALUES (?, ?, ?)', [$funcionId, $votante, $obraId]);
        }

        $cuerpo = $this->pedir("/cartelera/{$funcionId}")->cuerpo();

        // Como el wireframe (E3-13.F10): «Ganó la votación con 2 votos sobre 2 emitidos».
        self::assertStringContainsString('Ganó la votación con 2 votos sobre 2 emitidos.', $cuerpo);
        self::assertStringNotContainsString('34 votos', $cuerpo);
    }

    /**
     * Criterio E3-13.6: La ficha muestra las franjas, los votos y el estado del acuerdo.
     */
    public function testFichaDeFuncionEnAcuerdoMuestraFranjasVotosYEstado(): void
    {
        $this->cargarDatosDePrueba();
        $obra = $this->base->fila('SELECT id, titulo FROM obra LIMIT 1') ?? self::fail();
        $sala = $this->base->fila('SELECT id FROM sala LIMIT 1') ?? self::fail();
        $funcionId = Uuid::nuevo();

        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_acuerdo_fecha', 2000, 50)",
            [$funcionId, $sala['id']],
        );
        $this->base->ejecutar(
            "INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'elegida')",
            [$funcionId, $obra['id']],
        );

        // 1. Petición HTML sin usuario
        $respuestaHtml = $this->pedir("/cartelera/{$funcionId}");
        self::assertSame(200, $respuestaHtml->estado());
        $cuerpo = $respuestaHtml->cuerpo();
        self::assertStringContainsStringIgnoringCase('acuerdo de fecha', $cuerpo);
        self::assertStringContainsString($obra['titulo'], $cuerpo);
        // La primera franja del doble de funciones, a diez días de hoy.
        self::assertStringContainsString(Cartelera::formatearFechaLarga(new DateTimeImmutable('+10 days')), $cuerpo);

        // 2. Petición HTML con espectador logueado que tiene voto emitido
        $espectadorId = Uuid::nuevo();
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', $this->salidaDelLog));
        $respuestaUsuario = $aplicacion->atender(new Peticion(
            'GET',
            "/cartelera/{$funcionId}",
            [],
            [],
            ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $espectadorId, 'X-Rol' => 'espectador'],
        ));
        self::assertSame(200, $respuestaUsuario->estado());
        if (str_contains($respuestaUsuario->cuerpo(), 'Marcaste «Me sirve»')) {
            self::assertStringContainsString('Marcaste «Me sirve»', $respuestaUsuario->cuerpo());
        }

        // 3. Petición JSON
        $respuestaJson = $this->pedir("/cartelera/{$funcionId}", [], ['Accept' => 'application/json']);
        self::assertSame(200, $respuestaJson->estado());
        $datos = self::json($respuestaJson);
        self::assertSame('en_acuerdo_fecha', $datos['funcion']['estado']);
        self::assertSame(2, $datos['funcion']['minimo_asistentes']);
        self::assertCount(2, $datos['funcion']['franjas']);
        self::assertSame('franja-mock-0', $datos['funcion']['franjas'][0]['id']);
        self::assertSame(2, $datos['funcion']['franjas'][0]['votos']);
        self::assertSame('franja-mock-1', $datos['funcion']['franjas'][1]['id']);
        self::assertSame(0, $datos['funcion']['franjas'][1]['votos']);
    }
}

