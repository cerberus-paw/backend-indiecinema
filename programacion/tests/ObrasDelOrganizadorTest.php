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
use IndieCinema\Nucleo\Pruebas\Dobles\Formularios;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Controladores\FormularioDeObra;
use IndieCinema\Programacion\Modelo\DatosDeObra;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\Controladores\FormularioDeObraTest;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\TestCase;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;

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
            'funciones' => [
                'token' => 'token-de-programacion',
                'url' => self::$urlFunciones,
            ],
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
     * @param array<string, string> $cabecerasAdicionales
     */
    private function pedir(
        string $metodo,
        string $ruta,
        array $cuerpo = [],
        array $archivos = [],
        string $usuarioId = self::ORGANIZADORA,
        array $cabecerasAdicionales = [],
    ): Respuesta {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));
        $cabeceras = [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuarioId,
            'X-Rol' => 'organizador',
            ...$cabecerasAdicionales,
        ];

        // La consulta, si viene en la ruta («/organizador/obras?error_baja=1»), va aparte, como la arma PHP.
        [$camino, $consulta] = explode('?', $ruta, 2) + [1 => ''];
        parse_str($consulta, $parametros);

        return PaginasParaValidar::anotar($metodo, $ruta, $aplicacion->atender(new Peticion(
            $metodo,
            $camino,
            $parametros,
            $metodo === 'POST' ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo,
            $cabeceras,
            ['csrf' => self::CSRF],
            $archivos,
        )));
    }

    /** La base de prueba arranca sin géneros: se crea «Drama» la primera vez que se pide. */
    private function primerGeneroId(): string
    {
        $generoId = $this->base->valor("SELECT id FROM genero WHERE nombre = 'Drama'");
        if (is_string($generoId)) {
            return $generoId;
        }
        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);

        return $generoId;
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

    /**
     * Si el envío vuelve vacío, el resumen lleva a cada campo (E4-10.5), también a los géneros, que
     * son un grupo sin un control propio; y no hay un aviso genérico aparte.
     */
    public function testElResumenDeErroresLlevaACadaCampoDeLaObra(): void
    {
        $respuesta = $this->pedir('POST', '/organizador/obras/nueva');

        self::assertSame(422, $respuesta->estado());
        $resumen = Formularios::resumen($respuesta->cuerpo());
        self::assertNotNull($resumen);
        self::assertContains('#titulo', array_keys($resumen['enlaces']));
        self::assertContains('#generos', array_keys($resumen['enlaces']));
        self::assertSame('Revisá ' . count($resumen['enlaces']) . ' campos', $resumen['titulo']);
        self::assertTrue($resumen['anunciado']);
        self::assertStringNotContainsString('Revisá los campos marcados.', $respuesta->cuerpo());
        foreach (array_keys($resumen['enlaces']) as $enlace) {
            self::assertStringContainsString('id="' . substr($enlace, 1) . '"', $respuesta->cuerpo(), "El link {$enlace} no lleva a ningún lado.");
        }
    }

    public function testCrearObraConAficheExitoso(): Obra
    {
        $generoId = $this->primerGeneroId();
        $campos = [
            'titulo' => 'Historias extraordinarias',
            'director' => 'Mariano Llinás',
            'sinopsis' => 'Tres historias que se cruzan en la provincia de Buenos Aires.',
            'anio' => '2008',
            'duracion' => '245',
            'tipo' => 'pelicula',
            'origen_copia' => 'obra_propia',
            'generos' => [$generoId],
        ];

        $respuesta = $this->pedir('POST', '/organizador/obras/nueva', $campos, [
            'afiche' => Imagenes::subida('sala.jpg'),
        ]);

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/organizador/obras?creada=1', $respuesta->cabecera('Location'));

        $obras = (new RepositorioDeObras($this->base))->delDueno(self::ORGANIZADORA);
        self::assertCount(1, $obras);
        $obra = $obras[0];
        self::assertSame('Historias extraordinarias', $obra->titulo);
        self::assertNotNull($obra->afiche);
        self::assertFileExists("{$this->carpeta}/afiches/{$obra->afiche}");

        return $obra;
    }

    /**
     * E4-08.1 pedía que una obra nueva se pudiera elegir al crear una función; desde EF-07.7 queda
     * pendiente hasta que la aprueba la moderación, y recién ahí se elige.
     */
    public function testUnaObraNuevaQuedaPendienteYSeEligeRecienAprobada(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();
        self::assertSame('pendiente', $this->base->valor('SELECT estado FROM obra WHERE id = ?', [$obra->id]));
        self::assertStringContainsString('Pendiente de revisión', $this->pedir('GET', '/organizador/obras')->cuerpo());

        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Tita', 'Descripción', 'Calle 123', 'Luján', 50, 1, 120, 15), '00000000000000000000000000000001.jpg');
        (new RepositorioDeSalas($this->base))->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);
        self::assertStringNotContainsString($obra->id, $this->pedir('GET', '/organizador/funciones/nueva')->cuerpo());

        $this->base->ejecutar("UPDATE obra SET estado = 'aprobada', motivo = 'Bien.' WHERE id = ?", [$obra->id]);
        $respuesta = $this->pedir('GET', '/organizador/funciones/nueva');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString($obra->id, $respuesta->cuerpo());
        self::assertStringNotContainsString('Pendiente de revisión', $this->pedir('GET', '/organizador/obras')->cuerpo());
    }

    /**
     * El afiche de una pendiente lo ven su dueño y la moderación, que la revisa; el de una aprobada
     * es público, como el catálogo.
     */
    public function testElAficheDeUnaPendienteLoVenSuDuenoYLaModeracion(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();
        $url = "/obras/{$obra->id}/afiche";

        self::assertSame(200, $this->pedir('GET', $url)->estado());
        self::assertSame(404, $this->pedir('GET', $url, usuarioId: self::OTRO_ORGANIZADOR)->estado());
        self::assertSame(200, $this->pedir('GET', $url, usuarioId: self::OTRO_ORGANIZADOR, cabecerasAdicionales: ['X-Rol' => 'moderador'])->estado());

        $this->base->ejecutar("UPDATE obra SET estado = 'aprobada', motivo = 'Bien.' WHERE id = ?", [$obra->id]);
        self::assertSame(200, $this->pedir('GET', $url, usuarioId: self::OTRO_ORGANIZADOR)->estado());
    }

    public function testEditarObraPropia(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();
        $generoId = $this->primerGeneroId();

        $formEdicion = $this->pedir('GET', "/organizador/obras/{$obra->id}/editar");
        self::assertSame(200, $formEdicion->estado());
        self::assertStringContainsString('Editar obra', $formEdicion->cuerpo());
        self::assertStringContainsString('Historias extraordinarias', $formEdicion->cuerpo());

        // Actualizar sin nuevo afiche conserva el afiche anterior.
        $respuesta = $this->pedir('POST', "/organizador/obras/{$obra->id}/editar", [
            'titulo' => 'Historias extraordinarias (versión restaurada)',
            'director' => 'Mariano Llinás',
            'sinopsis' => 'Nueva versión extendida.',
            'anio' => '2008',
            'duracion' => '250',
            'tipo' => 'pelicula',
            'origen_copia' => 'obra_propia',
            'generos' => [$generoId],
        ]);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/organizador/obras/{$obra->id}/editar?guardada=1", $respuesta->cabecera('Location'));

        $actualizada = (new RepositorioDeObras($this->base))->buscar($obra->id);
        self::assertNotNull($actualizada);
        self::assertSame('Historias extraordinarias (versión restaurada)', $actualizada->titulo);
        self::assertSame($obra->afiche, $actualizada->afiche);
    }

    /**
     * @return array<string, mixed> los campos del formulario de edición
     */
    private function camposDeEdicion(string $sinopsis): array
    {
        return [
            'titulo' => 'Historias extraordinarias',
            'director' => 'Mariano Llinás',
            'sinopsis' => $sinopsis,
            'anio' => '2008',
            'duracion' => '245',
            'tipo' => 'pelicula',
            'origen_copia' => 'obra_propia',
            'generos' => [$this->primerGeneroId()],
        ];
    }

    /**
     * EF-07.7: «Mis obras» muestra por qué la rechazaron, y corregirla la manda de nuevo a revisión.
     */
    public function testUnaRechazadaMuestraElMotivoYEditarlaLaVuelveAPendiente(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();
        $this->base->ejecutar("UPDATE obra SET estado = 'rechazada', motivo = 'El afiche es de <b>otra</b> película.' WHERE id = ?", [$obra->id]);

        $listado = $this->pedir('GET', '/organizador/obras')->cuerpo();
        self::assertStringContainsString('Rechazada', $listado);
        self::assertStringContainsString('El afiche es de &lt;b&gt;otra&lt;/b&gt; película.', $listado);

        $respuesta = $this->pedir('POST', "/organizador/obras/{$obra->id}/editar", $this->camposDeEdicion('Con el afiche correcto.'));

        self::assertSame(303, $respuesta->estado());
        self::assertSame('pendiente', $this->base->valor('SELECT estado FROM obra WHERE id = ?', [$obra->id]));
        self::assertStringContainsString('La mandaste de nuevo a revisión', $this->pedir('GET', "/organizador/obras/{$obra->id}/editar?guardada=1")->cuerpo());
    }

    /**
     * Q7: corregir un dato de una aprobada no la saca del catálogo; si abusa, se la denuncia.
     */
    public function testEditarUnaAprobadaOUnaPendienteNoCambiaSuEstado(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        $this->pedir('POST', "/organizador/obras/{$obra->id}/editar", $this->camposDeEdicion('Otra sinopsis.'));
        self::assertSame('pendiente', $this->base->valor('SELECT estado FROM obra WHERE id = ?', [$obra->id]));

        $this->base->ejecutar("UPDATE obra SET estado = 'aprobada', motivo = 'Bien.' WHERE id = ?", [$obra->id]);
        $this->pedir('POST', "/organizador/obras/{$obra->id}/editar", $this->camposDeEdicion('Una sinopsis más.'));
        self::assertSame('aprobada', $this->base->valor('SELECT estado FROM obra WHERE id = ?', [$obra->id]));
    }

    public function testRechazaEditarObraDeOtroOrganizador(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        $respuesta = $this->pedir('GET', "/organizador/obras/{$obra->id}/editar", usuarioId: self::OTRO_ORGANIZADOR);
        self::assertSame(404, $respuesta->estado());

        $post = $this->pedir('POST', "/organizador/obras/{$obra->id}/editar", ['titulo' => 'Hack'], usuarioId: self::OTRO_ORGANIZADOR);
        self::assertSame(404, $post->estado());
    }

    /**
     * Criterio de aceptación E4-08.4: «Listo cuando: Con funciones futuras la baja se rechaza.»
     */
    public function testBajaLogicaDeObraSinFuncionesFuturasEsExitosa(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        $respuesta = $this->pedir('POST', "/organizador/obras/{$obra->id}/baja");

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/organizador/obras?dada_de_baja=1', $respuesta->cabecera('Location'));

        // Ya no aparece en las obras del dueño
        $obras = (new RepositorioDeObras($this->base))->delDueno(self::ORGANIZADORA);
        self::assertEmpty($obras);

        // En la base tiene fecha de baja
        $dadaDeBaja = $this->base->valor('SELECT dada_de_baja_en FROM obra WHERE id = ?', [$obra->id]);
        self::assertNotNull($dadaDeBaja);
    }

    public function testBajaLogicaEnJsonDevuelveOk(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        $respuesta = $this->pedir('POST', "/organizador/obras/{$obra->id}/baja", cabecerasAdicionales: ['Accept' => 'application/json']);

        self::assertSame(200, $respuesta->estado());
        $datos = json_decode($respuesta->cuerpo(), true);
        self::assertSame(['ok' => true], $datos);
    }

    public function testBajaLogicaSeRechazaSiLaObraTieneFuncionesFuturas(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        // Sala habilitada
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Tita', 'Descripción', 'Calle 123', 'Luján', 50, 1, 120, 15), '00000000000000000000000000000001.jpg');
        (new RepositorioDeSalas($this->base))->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        // Función programada a futuro
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO funcion (id, sala_id, estado, fecha_hora, precio_base, cupo) VALUES (?, ?, ?, ?, ?, ?)',
            [$funcionId, $sala->id, 'programada', (new DateTimeImmutable('+5 days'))->format('Y-m-d H:i:s'), 1000, 50],
        );
        $this->base->ejecutar(
            'INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, ?)',
            [$funcionId, $obra->id, 'elegida'],
        );

        // Intento de baja en HTML
        $respuesta = $this->pedir('POST', "/organizador/obras/{$obra->id}/baja");
        self::assertSame(303, $respuesta->estado());
        self::assertSame('/organizador/obras?error_baja=1', $respuesta->cabecera('Location'));
        self::assertStringContainsString('funciones futuras', $this->pedir('GET', '/organizador/obras?error_baja=1')->cuerpo());
        self::assertStringNotContainsString('inventado', $this->pedir('GET', '/organizador/obras?error_baja=inventado')->cuerpo());

        // Intento de baja en JSON
        $respuestaJson = $this->pedir('POST', "/organizador/obras/{$obra->id}/baja", cabecerasAdicionales: ['Accept' => 'application/json']);
        self::assertSame(422, $respuestaJson->estado());
        $datosJson = json_decode($respuestaJson->cuerpo(), true);
        self::assertArrayHasKey('error', $datosJson);

        // La obra sigue activa (no fue dada de baja)
        $dadaDeBaja = $this->base->valor('SELECT dada_de_baja_en FROM obra WHERE id = ?', [$obra->id]);
        self::assertNull($dadaDeBaja);
    }

    public function testBajaLogicaSeRechazaSiLaObraEstaEnVotacionDeTitulos(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Tita', 'Descripción', 'Calle 123', 'Luján', 50, 1, 120, 15), '00000000000000000000000000000001.jpg');
        (new RepositorioDeSalas($this->base))->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO funcion (id, sala_id, estado, fecha_hora, precio_base, cupo) VALUES (?, ?, ?, ?, ?, ?)',
            [$funcionId, $sala->id, 'en_votacion_titulos', null, 0, 50],
        );
        $this->base->ejecutar(
            'INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, ?)',
            [$funcionId, $obra->id, 'candidata'],
        );

        $respuestaJson = $this->pedir('POST', "/organizador/obras/{$obra->id}/baja", cabecerasAdicionales: ['Accept' => 'application/json']);
        self::assertSame(422, $respuestaJson->estado());

        $dadaDeBaja = $this->base->valor('SELECT dada_de_baja_en FROM obra WHERE id = ?', [$obra->id]);
        self::assertNull($dadaDeBaja);
    }

    public function testRechazaDarDeBajaObraDeOtroOrganizador(): void
    {
        $obra = $this->testCrearObraConAficheExitoso();

        $respuesta = $this->pedir('POST', "/organizador/obras/{$obra->id}/baja", usuarioId: self::OTRO_ORGANIZADOR);
        self::assertSame(404, $respuesta->estado());
    }
}
