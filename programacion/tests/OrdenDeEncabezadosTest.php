<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
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
 * del sitio, así tienen tarjetas, salas y funciones. Una página nueva entra con una línea en
 * paginas().
 */
final class OrdenDeEncabezadosTest extends TestCase
{
    use ConBaseDePruebas;
    use ConFuncionesDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const FUNCION = '00000003-0000-4000-8000-000000000001';
    private const SALA = '00000001-0000-4000-8000-000000000001';

    private string $carpeta;

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
        $base = self::baseConTablasNuevas($this->configuracion());
        DatosDePrueba::para(
            $this->configuracion(),
            new RepositorioDeGeneros($base),
            new RepositorioDeSalas($base),
            new RepositorioDeObras($base),
            new RepositorioDeFunciones($base),
        )->cargar();
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
            // Las páginas no mandan correos: en el puerto 1 no escucha nadie.
            'correo' => ['servidor' => 'smtp://127.0.0.1:1', 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => 'equipo@indiecinema.test'],
        ]);
    }

    /**
     * Cada página con quién la mira (null es un visitante sin sesión) y el estado que tiene que
     * devolver.
     *
     * @return iterable<string, array{string, ?string, int}>
     */
    public static function paginas(): iterable
    {
        yield 'portada' => ['/', null, 200];
        yield 'acerca de' => ['/acerca-de', null, 200];
        yield 'cartelera' => ['/cartelera', null, 200];
        yield 'cartelera sin resultados' => ['/cartelera?q=zzzz', null, 200];
        yield 'ficha de una función' => ['/cartelera/' . self::FUNCION, null, 200];
        yield 'ficha de una función con sesión' => ['/cartelera/' . self::FUNCION, 'espectador', 200];
        yield 'salas' => ['/salas', null, 200];
        yield 'ficha de una sala' => ['/salas/' . self::SALA, null, 200];
        yield 'solicitar sala' => ['/solicitar-sala', 'espectador', 200];
        yield 'mis salas' => ['/organizador/salas', 'organizador', 200];
        yield 'editar una sala' => ['/organizador/salas/' . self::SALA . '/editar', 'organizador', 200];
        yield 'mis funciones' => ['/organizador/funciones', 'organizador', 200];
        yield 'programar una función' => ['/organizador/funciones/nueva', 'organizador', 200];
        yield 'cancelar una función' => ['/organizador/funciones/' . self::FUNCION . '/cancelar', 'organizador', 200];
        yield 'solicitudes del administrador' => ['/administracion/solicitudes', 'administrador', 200];
        yield 'página que no existe' => ['/no-existe', null, 404];
        yield 'función que no existe' => ['/cartelera/00000003-0000-4000-8000-999999999999', null, 404];
        yield 'sin permiso' => ['/organizador/salas', 'espectador', 403];
    }

    #[DataProvider('paginas')]
    public function testUnSoloH1YSinSaltosDeNivel(string $url, ?string $rol, int $estado): void
    {
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => DatosDePrueba::ORGANIZADOR_DEFECTO_ID,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Ana Prueba'),
        ];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', fopen('php://memory', 'w')));

        $respuesta = $aplicacion->atender(new Peticion('GET', (string) parse_url($url, PHP_URL_PATH), $consulta, [], $cabeceras));

        self::assertSame($estado, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame([], Encabezados::problemas($respuesta->cuerpo()));
    }
}
