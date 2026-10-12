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
            'nginx' => ['secreto' => 'secreto-de-nginx'],
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
     * @param array<string, mixed> $cartelera
     */
    private function pedir(string $url, array $cartelera = []): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion($cartelera), new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion('GET', (string) parse_url($url, PHP_URL_PATH), $consulta));
    }

    private function generoId(string $nombre): string
    {
        foreach ((new RepositorioDeGeneros($this->base))->todos() as $genero) {
            if ($genero->nombre === $nombre) {
                return $genero->id;
            }
        }
        self::fail("No está el género {$nombre}.");
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
        self::assertStringContainsString('value="' . $this->generoId('Comedia') . '"', $cuerpo);
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
            '{Comedia}' => $this->generoId('Comedia'),
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
        $comedia = $this->generoId('Comedia');

        $cuerpo = $this->pedir("/cartelera?q=+keaton+&localidad=Luj%C3%A1n&fecha=2030-01-02&genero={$comedia}")->cuerpo();

        self::assertStringContainsString('<form class="filtros-cartelera" action="/cartelera" method="get"', $cuerpo);
        self::assertMatchesRegularExpression('#name="q"\s+class="campo-control control-input"\s+value="keaton"#', $cuerpo);
        self::assertMatchesRegularExpression('#<option value="Luján" selected>#', $cuerpo);
        self::assertMatchesRegularExpression('#<option value="' . $comedia . '" selected>#', $cuerpo);
        self::assertMatchesRegularExpression('#name="fecha"\s+class="campo-control control-input"\s+value="2030-01-02"#', $cuerpo);
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
        yield 'un género que no es un id' => ['genero=drama'];
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
            '{Comedia}' => $this->generoId('Comedia'),
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
}
