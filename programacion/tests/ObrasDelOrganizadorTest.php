<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Controladores\FormularioDeObra;
use IndieCinema\Programacion\Modelo\DatosDeObra;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\Controladores\FormularioDeObraTest;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\TestCase;

final class ObrasDelOrganizadorTest extends TestCase
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
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO_ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private string $carpeta;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'produccion'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->carpeta}/afiches/*") ?: []);
        @rmdir("{$this->carpeta}/afiches");
        @rmdir($this->carpeta);
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @param array<string, mixed> $archivos
     */
    private function pedir(
        string $metodo,
        string $ruta,
        array $cuerpo = [],
        array $archivos = [],
        string $usuarioId = self::ORGANIZADORA,
    ): Respuesta {
        $aplicacion = new Aplicacion($this->configuracion, new Log('programacion', $this->salidaDelLog));
        $cabeceras = [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuarioId,
            'X-Rol' => 'organizador',
        ];

        return $aplicacion->atender(new Peticion(
            $metodo,
            $ruta,
            [],
            $metodo === 'POST' ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo,
            $cabeceras,
            ['csrf' => self::CSRF],
            $archivos,
        ));
    }

    private function primerGeneroId(): string
    {
        return (string) $this->base->valor('SELECT id FROM genero ORDER BY nombre LIMIT 1');
    }

    public function testOrganizadorVeListadoDeSusObras(): void
    {
        $respuesta = $this->pedir('GET', '/organizador/obras');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Mis obras', $respuesta->cuerpo());
    }

    public function testMostrarFormularioDeAlta(): void
    {
        $respuesta = $this->pedir('GET', '/organizador/obras/nueva');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Cargar obra', $respuesta->cuerpo());
    }

    public function testCrearObraConAficheExitoso(): Obra
    {
        $generoId = $this->primerGeneroId();
        $campos = [
            'titulo' => 'La flor',
            'director' => 'Mariano Llinás',
            'sinopsis' => 'Seis episodios independientes con las mismas cuatro actrices.',
            'anio' => '2018',
            'duracion' => '840',
            'tipo' => 'pelicula',
            'origen_copia' => 'obra_propia',
            'generos' => [$generoId],
        ];

        $respuesta = $this->pedir('POST', '/organizador/obras/nueva', $campos, [
            'afiche' => Imagenes::subida('sala.jpg'),
        ]);

        self::assertSame(302, $respuesta->estado());
        self::assertSame('/organizador/obras?creada=1', $respuesta->cabecera('Location'));

        $obras = (new RepositorioDeObras($this->base))->delDueno(self::ORGANIZADORA);
        self::assertCount(1, $obras);
        $obra = $obras[0];
        self::assertSame('La flor', $obra->titulo);
        self::assertNotNull($obra->afiche);
        self::assertFileExists("{$this->carpeta}/afiches/{$obra->afiche}");

        return $obra;
    }

    /**
     * Criterio de aceptación E4-08.1: «Listo cuando: Una obra nueva se puede elegir al crear una función.»
     */
    public function testObraNuevaSePuedeElegirAlCrearUnaFuncion(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        // Creamos una sala para que el organizador pueda abrir el formulario de nueva función.
        $sala = Sala::nueva(self::ORGANIZADORA, 'Cine Tita', 'Descripción', 'Calle 123', 'Luján', 50, 1, 120, 15, '00000000000000000000000000000001.jpg');
        (new RepositorioDeSalas($this->base))->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        $respuesta = $this->pedir('GET', '/organizador/funciones/nueva');

        self::assertSame(200, $respuesta->estado());
        // La obra recién creada figura en el catálogo seleccionable para la función.
        self::assertStringContainsString($obra->titulo, $respuesta->cuerpo());
        self::assertStringContainsString($obra->id, $respuesta->cuerpo());
    }

    public function testEditarObraPropia(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();
        $generoId = $this->primerGeneroId();

        $formEdicion = $this->pedir('GET', "/organizador/obras/{$obra->id}/editar");
        self::assertSame(200, $formEdicion->estado());
        self::assertStringContainsString('Editar obra', $formEdicion->cuerpo());
        self::assertStringContainsString('La flor', $formEdicion->cuerpo());

        // Actualizar sin nuevo afiche conserva el afiche anterior.
        $respuesta = $this->pedir('POST', "/organizador/obras/{$obra->id}/editar", [
            'titulo' => 'La flor (Edición Especial)',
            'director' => 'Mariano Llinás',
            'sinopsis' => 'Nueva versión extendida.',
            'anio' => '2018',
            'duracion' => '860',
            'tipo' => 'pelicula',
            'origen_copia' => 'obra_propia',
            'generos' => [$generoId],
        ]);

        self::assertSame(302, $respuesta->estado());
        self::assertSame("/organizador/obras/{$obra->id}/editar?guardada=1", $respuesta->cabecera('Location'));

        $actualizada = (new RepositorioDeObras($this->base))->buscar($obra->id);
        self::assertNotNull($actualizada);
        self::assertSame('La flor (Edición Especial)', $actualizada->titulo);
        self::assertSame($obra->afiche, $actualizada->afiche);
    }

    public function testRechazaEditarObraDeOtroOrganizador(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        $respuesta = $this->pedir('GET', "/organizador/obras/{$obra->id}/editar", usuarioId: self::OTRO_ORGANIZADOR);
        self::assertSame(404, $respuesta->estado());

        $post = $this->pedir('POST', "/organizador/obras/{$obra->id}/editar", ['titulo' => 'Hack'], usuarioId: self::OTRO_ORGANIZADOR);
        self::assertSame(404, $post->estado());
    }
}
