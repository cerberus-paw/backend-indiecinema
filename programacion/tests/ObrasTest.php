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
use IndieCinema\Programacion\Modelo\DatosDeObra;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\TestCase;

final class ObrasTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeObras $repositorio;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'produccion'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->repositorio = new RepositorioDeObras($this->base);
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    /**
     * @param array<string, mixed> $consulta
     * @param array<string, string> $cabecerasAdicionales
     */
    private function pedir(
        string $ruta = '/obras',
        array $consulta = [],
        array $cabecerasAdicionales = [],
    ): Respuesta {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));
        $cabeceras = [
            'X-Nginx-Secreto' => self::SECRETO,
            ...$cabecerasAdicionales,
        ];

        return $aplicacion->atender(new Peticion(
            'GET',
            $ruta,
            $consulta,
            [],
            $cabeceras,
        ));
    }

    /**
     * @return array{string, string} [id, nombre]
     */
    private function genero(string $nombre): array
    {
        // La base de prueba arranca sin géneros: se crea la primera vez que se pide.
        $id = $this->base->valor('SELECT id FROM genero WHERE nombre = ?', [$nombre]);
        if (!is_string($id)) {
            $id = Uuid::nuevo();
            $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$id, $nombre, "Obras de {$nombre}."]);
        }

        return [$id, $nombre];
    }

    private function cargarObrasDeEjemplo(): void
    {
        [$genero1Id] = $this->genero('Drama');
        [$genero2Id] = $this->genero('Comedia');

        $obra1 = Obra::crear(
            self::ORGANIZADORA,
            new DatosDeObra('La flor', 'Seis episodios independientes', 2018, 'Mariano Llinás', 840, TipoObra::Pelicula, OrigenCopia::ObraPropia, [$genero1Id]),
        );
        $this->repositorio->agregar($obra1);

        $obra2 = Obra::crear(
            self::ORGANIZADORA,
            new DatosDeObra('Historias extraordinarias', 'Tres historias en la provincia', 2008, 'Mariano Llinás', 245, TipoObra::Pelicula, OrigenCopia::ObraPropia, [$genero2Id]),
        );
        $this->repositorio->agregar($obra2);

        $obraBaja = Obra::crear(
            self::ORGANIZADORA,
            new DatosDeObra('Balnearios', 'Documental sobre balnearios argentinos', 2002, 'Mariano Llinás', 80, TipoObra::Pelicula, OrigenCopia::ObraPropia, [$genero1Id]),
        );
        $this->repositorio->agregar($obraBaja);
        $this->repositorio->darDeBaja($obraBaja->id);
    }

    public function testListarObrasSinFiltroTraeLasActivasYNoLasDadasDeBaja(): void
    {
        $this->cargarObrasDeEjemplo();

        $respuesta = $this->pedir('/obras');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('La flor', $respuesta->cuerpo());
        self::assertStringContainsString('Historias extraordinarias', $respuesta->cuerpo());
        self::assertStringNotContainsString('Balnearios', $respuesta->cuerpo());
    }

    public function testBuscarObrasPorTituloYDirector(): void
    {
        $this->cargarObrasDeEjemplo();

        // Buscar por parte del título
        $porTitulo = $this->pedir('/obras', ['q' => 'extraordinarias']);
        self::assertSame(200, $porTitulo->estado());
        self::assertStringContainsString('Historias extraordinarias', $porTitulo->cuerpo());
        self::assertStringNotContainsString('La flor', $porTitulo->cuerpo());

        // Buscar por director
        $porDirector = $this->pedir('/obras', ['q' => 'Llinás']);
        self::assertSame(200, $porDirector->estado());
        self::assertStringContainsString('La flor', $porDirector->cuerpo());
        self::assertStringContainsString('Historias extraordinarias', $porDirector->cuerpo());

        // Búsqueda sin coincidencias
        $sinResultados = $this->pedir('/obras', ['q' => 'Tarantino']);
        self::assertSame(200, $sinResultados->estado());
        self::assertStringNotContainsString('La flor', $sinResultados->cuerpo());
        self::assertStringNotContainsString('Historias extraordinarias', $sinResultados->cuerpo());
    }

    public function testFiltrarObrasPorGenero(): void
    {
        $this->cargarObrasDeEjemplo();
        [, $nombreComedia] = $this->genero('Comedia');

        $respuesta = $this->pedir('/obras', ['genero' => $nombreComedia]);

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Historias extraordinarias', $respuesta->cuerpo());
        self::assertStringNotContainsString('La flor', $respuesta->cuerpo());
    }

    public function testParametrosInvalidosMuestranAvisos(): void
    {
        $this->cargarObrasDeEjemplo();

        // Búsqueda con texto demasiado largo
        $respuestaLargo = $this->pedir('/obras', ['q' => str_repeat('x', 151)]);
        self::assertSame(200, $respuestaLargo->estado());
        self::assertStringContainsString('puede tener hasta 150 caracteres', $respuestaLargo->cuerpo());
        self::assertStringContainsString('La flor', $respuestaLargo->cuerpo());

        // Género inexistente
        $respuestaGeneroInvalido = $this->pedir('/obras', ['genero' => 'GeneroInexistente999']);
        self::assertSame(200, $respuestaGeneroInvalido->estado());
        self::assertStringContainsString('El género no es válido', $respuestaGeneroInvalido->cuerpo());
        self::assertStringContainsString('La flor', $respuestaGeneroInvalido->cuerpo());
    }

    public function testListarObrasEnFormatoJson(): void
    {
        $this->cargarObrasDeEjemplo();

        $respuesta = $this->pedir('/obras', [], ['Accept' => 'application/json']);

        self::assertSame(200, $respuesta->estado());
        self::assertSame('application/json; charset=utf-8', $respuesta->cabecera('Content-Type'));

        $datos = json_decode($respuesta->cuerpo(), true);
        self::assertIsArray($datos);
        self::assertArrayHasKey('obras', $datos);
        self::assertArrayHasKey('filtros', $datos);
        self::assertArrayHasKey('avisos', $datos);
        self::assertCount(2, $datos['obras']);
    }

    /**
     * Criterio de aceptación E4-08.3: «Listo cuando: El enlace del encabezado deja de dar 404.»
     */
    public function testMostrarFichaPublicaDeObraConProximasFunciones(): void
    {
        [$generoId] = $this->genero('Drama');
        $obra = Obra::crear(
            self::ORGANIZADORA,
            new DatosDeObra('La flor', 'Seis episodios independientes', 2018, 'Mariano Llinás', 840, TipoObra::Pelicula, OrigenCopia::ObraPropia, [$generoId]),
        );
        $this->repositorio->agregar($obra);

        // Agregamos una sala y una función programada para la obra
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Tita', 'Descripción', 'Calle 123', 'Luján', 50, 1, 120, 15), '00000000000000000000000000000001.jpg');
        (new RepositorioDeSalas($this->base))->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO funcion (id, sala_id, estado, fecha_hora, precio_base, cupo) VALUES (?, ?, ?, ?, ?, ?)',
            [$funcionId, $sala->id, 'programada', (new DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'), 1500, 50],
        );
        $this->base->ejecutar(
            'INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, ?)',
            [$funcionId, $obra->id, 'elegida'],
        );

        $respuesta = $this->pedir("/obras/{$obra->id}");
        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('La flor', $respuesta->cuerpo());
        self::assertStringContainsString('Mariano Llinás', $respuesta->cuerpo());
        self::assertStringContainsString('Cine Tita', $respuesta->cuerpo());

        // En JSON
        $respuestaJson = $this->pedir("/obras/{$obra->id}", [], ['Accept' => 'application/json']);
        self::assertSame(200, $respuestaJson->estado());
        $datos = json_decode($respuestaJson->cuerpo(), true);
        self::assertIsArray($datos);
        self::assertSame('La flor', $datos['obra']['titulo']);
        self::assertCount(1, $datos['funciones']);
        self::assertSame($funcionId, $datos['funciones'][0]['id']);
    }

    public function testMostrarObraInexistenteDevuelve404(): void
    {
        $noUuid = $this->pedir('/obras/no-es-un-uuid');
        self::assertSame(404, $noUuid->estado());

        $uuidInexistente = $this->pedir('/obras/' . Uuid::nuevo());
        self::assertSame(404, $uuidInexistente->estado());
    }

    public function testMostrarObraDadaDeBajaDevuelve404(): void
    {
        [$generoId] = $this->genero('Drama');
        $obra = Obra::crear(
            self::ORGANIZADORA,
            new DatosDeObra('Obra Dada de Baja', 'Sinopsis', 2020, 'Director X', 100, TipoObra::Pelicula, OrigenCopia::ObraPropia, [$generoId]),
        );
        $this->repositorio->agregar($obra);
        $this->repositorio->darDeBaja($obra->id);

        $respuesta = $this->pedir("/obras/{$obra->id}");
        self::assertSame(404, $respuesta->estado());
    }

    public function testFichaDeObraEsEditableParaSuOrganizador(): void
    {
        [$generoId] = $this->genero('Drama');
        $obra = Obra::crear(
            self::ORGANIZADORA,
            new DatosDeObra('Obra Propia', 'Sinopsis', 2021, 'Director Y', 90, TipoObra::Pelicula, OrigenCopia::ObraPropia, [$generoId]),
        );
        $this->repositorio->agregar($obra);

        // Sin sesión
        $publica = $this->pedir("/obras/{$obra->id}", [], ['Accept' => 'application/json']);
        $datosPublica = json_decode($publica->cuerpo(), true);
        self::assertFalse($datosPublica['obra']['editable']);

        // Con sesión del dueño
        $dueno = $this->pedir("/obras/{$obra->id}", [], [
            'Accept' => 'application/json',
            'X-Usuario-Id' => self::ORGANIZADORA,
            'X-Rol' => 'organizador',
        ]);
        $datosDueno = json_decode($dueno->cuerpo(), true);
        self::assertTrue($datosDueno['obra']['editable']);
        self::assertSame("/organizador/obras/{$obra->id}/editar", $datosDueno['obra']['url_editar']);
    }
}
