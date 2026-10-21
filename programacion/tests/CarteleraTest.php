<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
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

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private BaseDeDatos $base;
    private string $carpeta;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        // Antes de saltearla: tearDown() corre igual y la usa.
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
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

        return $aplicacion->atender(new Peticion('GET', (string) parse_url($url, PHP_URL_PATH), $consulta, [], $cabeceras));
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function post(
        string $ruta,
        array $cuerpo = [],
        ?string $usuario = null,
        string $rol = 'espectador',
    ): Respuesta {
        $cabeceras = $usuario === null
            ? []
            : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion(
            'POST',
            (string) parse_url($ruta, PHP_URL_PATH),
            $consulta,
            ['_csrf' => self::CSRF, ...$cuerpo],
            $cabeceras,
            ['csrf' => self::CSRF],
        ));
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

        $respuesta = $this->pedir('/cartelera?orden=precio&tamano=1000&por_pagina=1000');

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
        self::assertSame(['funciones', 'filtros', 'avisos', 'paginado', 'csv'], array_keys($json));
        self::assertCount(12, $json['funciones']);
        // Cada función con lo mismo que muestra la tarjeta, y el link a su ficha.
        $primera = $json['funciones'][0];
        self::assertSame(['id', 'titulo', 'estado', 'precio', 'sala', 'localidad', 'fecha', 'url'], array_keys($primera));
        self::assertSame("/cartelera/{$primera['id']}", $primera['url']);
        self::assertSame(
            ['numero' => 1, 'total_paginas' => 2, 'total_funciones' => 15, 'anterior' => null, 'siguiente' => '/cartelera?pagina=2'],
            $json['paginado'],
        );
        self::assertSame([], $json['avisos']);
        self::assertSame('/cartelera.csv', $json['csv']);
        // Sin filtros, un objeto vacío y no una lista.
        self::assertStringContainsString('"filtros":{}', $respuesta->cuerpo());
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
        self::assertStringContainsString('Fritz Lang', $cuerpo);
        self::assertStringContainsString('153 min', $cuerpo);
        self::assertStringContainsString('Cine Club El Alambique', $cuerpo);
        self::assertStringContainsString('href="/salas/' . $salaId . '"', $cuerpo);
        self::assertStringContainsString('San Martín 420 · Luján', $cuerpo);
        self::assertStringContainsString('$ 2.500', $cuerpo);
        self::assertStringContainsString('<span class="insignia-estado insignia-programada">PROGRAMADA</span>', $cuerpo);
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
        self::assertStringContainsString('Fecha a confirmar', $cuerpo);
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
}
