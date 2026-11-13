<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Encabezados;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El orden de los encabezados de cada página de programación (E4-10.2): un único h1 y ningún salto
 * de nivel, como los recorre un lector de pantalla. Las páginas se dibujan con los datos de prueba
 * del sitio, así tienen tarjetas, salas, obras y funciones, más una función en acuerdo de fecha
 * para proponer franjas. Una página nueva entra con una línea en paginas().
 */
final class OrdenDeEncabezadosTest extends TestCase
{
    use ConBaseDePruebas;
    use ConFuncionesDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const FUNCION = '00000003-0000-4000-8000-000000000001';
    private const SALA = '00000001-0000-4000-8000-000000000001';
    private const OBRA = '00000002-0000-4000-8000-000000000001';
    private const EN_ACUERDO = '00000003-0000-4000-8000-0000000000aa';
    private const CSRF = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private string $carpeta;
    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::setUpBeforeClassFunciones();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownAfterClassFunciones();
    }

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->base = self::baseConTablasNuevas($this->configuracion());
        DatosDePrueba::para(
            $this->configuracion(),
            new RepositorioDeGeneros($this->base),
            new RepositorioDeSalas($this->base),
            new RepositorioDeObras($this->base),
            new RepositorioDeFunciones($this->base),
        )->cargar();
        // Los datos del sitio no traen ninguna en acuerdo de fecha, que es la que admite franjas.
        $this->base->ejecutar(
            "INSERT INTO funcion (id, sala_id, estado, precio_base, cupo) VALUES (?, ?, 'en_acuerdo_fecha', 2000, 50)",
            [self::EN_ACUERDO, self::SALA],
        );
        $this->base->ejecutar("INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'elegida')", [self::EN_ACUERDO, self::OBRA]);
    }

    protected function tearDown(): void
    {
        foreach (['salas', 'afiches'] as $subcarpeta) {
            array_map('unlink', glob("{$this->carpeta}/{$subcarpeta}/*") ?: []);
            @rmdir("{$this->carpeta}/{$subcarpeta}");
        }
        @rmdir($this->carpeta);
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => [
                'cuentas' => 'http://127.0.0.1:1',
                'funciones' => self::$urlFunciones,
            ],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
    }

    /**
     * Cada página con quién la mira (null es un visitante sin sesión), el método, lo que se manda y
     * el estado que tiene que devolver. Los formularios también cuando vuelven con errores.
     *
     * @return iterable<string, array{string, ?string, string, array<string, string>, int}>
     */
    public static function paginas(): iterable
    {
        yield 'portada' => ['/', null, 'GET', [], 200];
        yield 'acerca de' => ['/acerca-de', null, 'GET', [], 200];
        yield 'cartelera' => ['/cartelera', null, 'GET', [], 200];
        yield 'cartelera sin resultados' => ['/cartelera?q=zzzz', null, 'GET', [], 200];
        yield 'ficha de una función' => ['/cartelera/' . self::FUNCION, null, 'GET', [], 200];
        yield 'ficha de una función con sesión' => ['/cartelera/' . self::FUNCION, 'espectador', 'GET', [], 200];
        yield 'salas' => ['/salas', null, 'GET', [], 200];
        yield 'ficha de una sala' => ['/salas/' . self::SALA, null, 'GET', [], 200];
        yield 'obras' => ['/obras', null, 'GET', [], 200];
        yield 'obras sin resultados' => ['/obras?q=zzzz', null, 'GET', [], 200];
        yield 'ficha de una obra' => ['/obras/' . self::OBRA, null, 'GET', [], 200];
        yield 'mis salas' => ['/organizador/salas', 'organizador', 'GET', [], 200];
        yield 'editar una sala' => ['/organizador/salas/' . self::SALA . '/editar', 'organizador', 'GET', [], 200];
        yield 'editar una sala con errores' => ['/organizador/salas/' . self::SALA . '/editar', 'organizador', 'POST', [], 422];
        yield 'mis obras' => ['/organizador/obras', 'organizador', 'GET', [], 200];
        yield 'cargar una obra' => ['/organizador/obras/nueva', 'organizador', 'GET', [], 200];
        yield 'cargar una obra con errores' => ['/organizador/obras/nueva', 'organizador', 'POST', [], 422];
        yield 'editar una obra' => ['/organizador/obras/' . self::OBRA . '/editar', 'organizador', 'GET', [], 200];
        yield 'editar una obra con errores' => ['/organizador/obras/' . self::OBRA . '/editar', 'organizador', 'POST', [], 422];
        yield 'mis funciones' => ['/organizador/funciones', 'organizador', 'GET', [], 200];
        yield 'programar una función' => ['/organizador/funciones/nueva', 'organizador', 'GET', [], 200];
        yield 'programar una función con errores' => ['/organizador/funciones/nueva', 'organizador', 'POST', [], 422];
        yield 'editar una función' => ['/organizador/funciones/' . self::FUNCION . '/editar', 'organizador', 'GET', [], 200];
        yield 'editar una función con errores' => ['/organizador/funciones/' . self::FUNCION . '/editar', 'organizador', 'POST', [], 422];
        yield 'proponer franjas' => ['/organizador/funciones/' . self::EN_ACUERDO . '/proponer-franjas', 'organizador', 'GET', [], 200];
        yield 'proponer franjas con errores' => ['/organizador/funciones/' . self::EN_ACUERDO . '/proponer-franjas', 'organizador', 'POST', [], 422];
        yield 'cancelar una función' => ['/organizador/funciones/' . self::FUNCION . '/cancelar', 'organizador', 'GET', [], 200];
        yield 'página que no existe' => ['/no-existe', null, 'GET', [], 404];
        yield 'función que no existe' => ['/cartelera/00000003-0000-4000-8000-999999999999', null, 'GET', [], 404];
        yield 'obra que no existe' => ['/obras/00000002-0000-4000-8000-999999999999', null, 'GET', [], 404];
        yield 'sin permiso' => ['/organizador/salas', 'espectador', 'GET', [], 403];
    }

    /**
     * @param array<string, string> $cuerpo
     */
    #[DataProvider('paginas')]
    public function testUnSoloH1YSinSaltosDeNivel(string $url, ?string $rol, string $metodo, array $cuerpo, int $estado): void
    {
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => DatosDePrueba::ORGANIZADOR_DEFECTO_ID,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Ana Prueba'),
        ];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', fopen('php://memory', 'w')));

        $respuesta = $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($url, PHP_URL_PATH),
            $consulta,
            $metodo === 'POST' ? [...$cuerpo, '_csrf' => self::CSRF] : [],
            $cabeceras,
            ['csrf' => self::CSRF],
        ));

        self::assertSame($estado, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame([], Encabezados::problemas($respuesta->cuerpo()));
    }
}
