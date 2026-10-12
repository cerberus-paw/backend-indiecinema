<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Repositorios\RepositorioDeCartelera;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use PHPUnit\Framework\TestCase;

/**
 * GET / de punta a punta: la aplicación entera con las rutas de programación, la plantilla del
 * paquete front y un MySQL de verdad con los datos de demostración (quince funciones en cuatro
 * salas).
 *
 * Corre en desarrollo, con las variables estrictas de Twig: una que falte en la plantilla rompe
 * la prueba en vez de quedar vacía.
 */
final class InicioTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';

    private BaseDeDatos $base;
    private string $carpeta;

    protected function setUp(): void
    {
        // Antes de saltearla: tearDown() corre igual y la usa.
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->base = self::baseConTablasNuevas($this->configuracion());
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
     * @param array<string, string> $cabeceras
     */
    private function pedir(array $cabeceras = []): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', '/', [], [], $cabeceras, [], []));
    }

    public function testMuestraLasCuatroFuncionesMasProximasDeLaBase(): void
    {
        $this->cargarDatosDePrueba();
        $proximas = array_map(
            static fn (FuncionEnCartelera $funcion): string => $funcion->id,
            (new RepositorioDeCartelera($this->base))->pagina(new FiltrosDeCartelera(), 1, 4)->funciones,
        );

        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        self::assertSame(4, substr_count($cuerpo, '<article class="tarjeta-funcion">'));
        // Las mismas que encabezan la cartelera, en el mismo orden, cada una con su ficha.
        preg_match_all('#<a href="/cartelera/([0-9a-f-]{36})" class="tarjeta-funcion-titulo-enlace">#', $cuerpo, $enlaces);
        self::assertSame($proximas, $enlaces[1]);
        // La primera es Metrópolis, en una semana, como en la cartelera.
        self::assertStringContainsString('Metrópolis', $cuerpo);
        self::assertStringContainsString('15 funciones en cartelera', $cuerpo);
        self::assertStringNotContainsString('Todavía no hay funciones programadas.', $cuerpo);
    }

    public function testTieneLosAccesosALaCarteleraYALasSalas(): void
    {
        $this->cargarDatosDePrueba();

        $cuerpo = $this->pedir()->cuerpo();

        self::assertStringContainsString('<a href="/cartelera" class="portada-seccion-enlace">Ver la cartelera completa</a>', $cuerpo);
        self::assertStringContainsString('<a href="/salas" class="portada-seccion-enlace">Ver todas las salas</a>', $cuerpo);
        // Tres de las cuatro publicadas, las primeras del listado, cada una con su ficha.
        self::assertSame(3, substr_count($cuerpo, '<article class="tarjeta-funcion tarjeta-sala tarjeta-sala-compacta">'));
        self::assertStringContainsString('4 salas publicadas', $cuerpo);
        foreach (array_slice((new RepositorioDeSalas($this->base))->publicadas(), 0, 3) as $sala) {
            self::assertStringContainsString("href=\"/salas/{$sala->id}\"", $cuerpo);
        }
    }

    public function testElBuscadorLlevaALaCarteleraConLasLocalidades(): void
    {
        $this->cargarDatosDePrueba();

        $cuerpo = $this->pedir()->cuerpo();

        self::assertStringContainsString('<form class="portada-buscador" action="/cartelera" method="get" role="search"', $cuerpo);
        self::assertMatchesRegularExpression('#name="q"#', $cuerpo);
        self::assertMatchesRegularExpression('#name="localidad"#', $cuerpo);
        foreach (['Luján', 'Mercedes', 'San Antonio de Areco'] as $localidad) {
            self::assertStringContainsString("<option value=\"{$localidad}\"", $cuerpo);
        }
    }

    public function testLosEncabezadosNoSaltanNiveles(): void
    {
        $this->cargarDatosDePrueba();

        preg_match_all('#<h([1-6])[ >]#', $this->pedir()->cuerpo(), $niveles);

        self::assertSame('1', $niveles[1][0] ?? null, 'La página no empieza por el h1.');
        $anterior = 0;
        foreach (array_map('intval', $niveles[1]) as $nivel) {
            self::assertLessThanOrEqual($anterior + 1, $nivel, 'Un encabezado salta un nivel.');
            $anterior = $nivel;
        }
    }

    public function testSinDatosLoDiceEnCadaSeccion(): void
    {
        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringContainsString('Todavía no hay funciones programadas.', $respuesta->cuerpo());
        self::assertStringContainsString('Todavía no hay salas publicadas.', $respuesta->cuerpo());
        self::assertStringContainsString('0 funciones en cartelera', $respuesta->cuerpo());
    }

    public function testSeVeConSesion(): void
    {
        $this->cargarDatosDePrueba();

        $respuesta = $this->pedir(['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => DatosDePrueba::ORGANIZADOR_DEFECTO_ID, 'X-Rol' => 'organizador']);

        self::assertSame(200, $respuesta->estado());
        self::assertSame(4, substr_count($respuesta->cuerpo(), '<article class="tarjeta-funcion">'));
    }
}
