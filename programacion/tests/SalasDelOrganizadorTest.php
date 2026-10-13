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
use IndieCinema\Programacion\Controladores\FormularioDeSala;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Pruebas\Controladores\FormularioDeSalaTest;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\ArchivosDeSalas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * «Mis salas», la edición, la foto, la ficha y el listado público de punta a punta: la aplicación
 * entera con las rutas de programación, las plantillas del paquete front y un MySQL de verdad con
 * las tablas de las migraciones. Las salas nuevas entran por la solicitud (SolicitudDeSalaTest);
 * acá se cargan directo, con su foto en el volumen. Sin las variables PRUEBAS_MYSQL_* se saltea;
 * el README dice cómo levantar uno con Docker.
 */
final class SalasDelOrganizadorTest extends TestCase
{
    use ConBaseDePruebas;

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
        // Antes de saltearla: tearDown() corre igual y la usa.
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
        array_map('unlink', glob("{$this->carpeta}/salas/*") ?: []);
        @rmdir("{$this->carpeta}/salas");
        @rmdir($this->carpeta);
    }

    /**
     * @return list<string> las imágenes guardadas en el volumen
     */
    private function imagenesGuardadas(): array
    {
        return array_map('basename', glob("{$this->carpeta}/salas/*") ?: []);
    }

    /**
     * @param array<string, mixed>                $cuerpo
     * @param array<string, array<string, mixed>> $archivos
     * @param array<string, string>               $cabeceras
     */
    private function pedir(
        string $metodo,
        string $url,
        ?string $usuario,
        string $rol = 'organizador',
        array $cuerpo = [],
        array $archivos = [],
        array $cabeceras = [],
    ): Respuesta {
        if ($usuario !== null) {
            $cabeceras += ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($url, PHP_URL_PATH),
            $consulta,
            $cuerpo,
            $cabeceras,
            ['csrf' => self::CSRF],
            $archivos,
        ));
    }

    /**
     * @param array<string, string> $cambios
     * @param ?string               $imagen  de tests/Dobles/imagenes, o null para no mandar ninguna
     */
    private function enviar(string $url, ?string $usuario, array $cambios = [], string $rol = 'organizador', ?string $imagen = 'sala.png'): Respuesta
    {
        $archivos = $imagen === null ? [] : ['imagen' => Imagenes::subida($imagen)];

        return $this->pedir('POST', $url, $usuario, $rol, $cambios + ['_csrf' => self::CSRF] + FormularioDeSalaTest::camposValidos(), $archivos);
    }

    /**
     * Una sala sin habilitar, como la deja la solicitud, con la foto de tests/Dobles/imagenes.
     *
     * @param array<string, string> $cambios
     */
    private function crearSala(string $organizador, array $cambios = []): string
    {
        $datos = FormularioDeSala::desdeCampos($cambios + FormularioDeSalaTest::camposValidos())->datos() ?? self::fail('Los datos de la sala no son válidos.');
        $imagen = (new ArchivosDeSalas($this->configuracion))->guardar(new ImagenSubida(Imagenes::subida('sala.png')['tmp_name'], 'png'));
        $sala = Sala::nueva($organizador, $datos, $imagen);
        (new RepositorioDeSalas($this->base))->agregar($sala);

        return $sala->id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fila(string $id): ?array
    {
        return $this->base->fila('SELECT organizador_id, nombre, capacidad, imagen, habilitada FROM sala WHERE id = ?', [$id]);
    }

    public function testElDuenoEditaSuSala(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);

        $respuesta = $this->enviar("/organizador/salas/{$id}/editar", self::ORGANIZADORA, ['nombre' => 'El Galpón Nuevo', 'capacidad' => '80']);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/organizador/salas/{$id}/editar?guardada=1", $respuesta->cabecera('Location'));
        self::assertSame('El Galpón Nuevo', $this->fila($id)['nombre'] ?? null);
        self::assertSame(80, $this->fila($id)['capacidad'] ?? null);
    }

    public function testAlEditarUnaFotoNuevaReemplazaALaAnteriorYSinFotoQuedaLaQueEstaba(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);
        $primera = $this->fila($id)['imagen'] ?? null;

        $this->enviar("/organizador/salas/{$id}/editar", self::ORGANIZADORA, imagen: 'sala.webp');
        $segunda = $this->fila($id)['imagen'] ?? null;
        self::assertNotSame($primera, $segunda);
        self::assertSame([$segunda], $this->imagenesGuardadas());

        $this->enviar("/organizador/salas/{$id}/editar", self::ORGANIZADORA, ['nombre' => 'Otro nombre'], imagen: null);
        self::assertSame($segunda, $this->fila($id)['imagen'] ?? null);
        self::assertSame([$segunda], $this->imagenesGuardadas());
    }

    public function testLaFotoDeUnaSalaSinHabilitarSoloLaVenSuOrganizadorYElAdministrador(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);

        self::assertSame(404, $this->pedir('GET', "/salas/{$id}/imagen", null)->estado());
        self::assertSame(404, $this->pedir('GET', "/salas/{$id}/imagen", self::OTRO_ORGANIZADOR)->estado());
        // El administrador la mira en su bandeja antes de habilitarla.
        self::assertSame(200, $this->pedir('GET', "/salas/{$id}/imagen", self::OTRO_ORGANIZADOR, 'administrador')->estado());

        $propia = $this->pedir('GET', "/salas/{$id}/imagen", self::ORGANIZADORA);
        self::assertSame(200, $propia->estado());
        self::assertSame('image/png', $propia->cabecera('Content-Type'));
        self::assertSame(file_get_contents(Imagenes::ruta('sala.png')), $propia->cuerpo());
    }

    public function testLaFotoDeUnaSalaHabilitadaEsPublicaYSeCachea(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$id]);

        $respuesta = $this->pedir('GET', "/salas/{$id}/imagen", null);
        self::assertSame(200, $respuesta->estado());

        $otraVez = $this->pedir('GET', "/salas/{$id}/imagen", null, cabeceras: ['If-None-Match' => (string) $respuesta->cabecera('ETag')]);
        self::assertSame(304, $otraVez->estado());
        self::assertSame('', $otraVez->cuerpo());
    }

    public function testLaFotoDeUnaSalaDadaDeBajaNoSeVe(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE, dada_de_baja_en = NOW() WHERE id = ?', [$id]);

        self::assertSame(404, $this->pedir('GET', "/salas/{$id}/imagen", self::ORGANIZADORA)->estado());
    }

    public function testOtroOrganizadorNoVeNiGuardaLaSalaAjena(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);
        $antes = $this->fila($id);

        $ver = $this->pedir('GET', "/organizador/salas/{$id}/editar", self::OTRO_ORGANIZADOR);
        $guardar = $this->enviar("/organizador/salas/{$id}/editar", self::OTRO_ORGANIZADOR, ['nombre' => 'Ahora es mía']);

        // La misma respuesta que para una sala que no existe: no se entera de que existe.
        self::assertSame(404, $ver->estado());
        self::assertSame(404, $guardar->estado());
        self::assertSame($antes, $this->fila($id));
        rewind($this->salidaDelLog);
        self::assertStringContainsString(
            'WARNING ' . self::OTRO_ORGANIZADOR . " quiso editar la sala {$id}, que no es suya",
            (string) stream_get_contents($this->salidaDelLog),
        );
    }

    public function testMisSalasMuestraSoloLasDelOrganizador(): void
    {
        $propias = [$this->crearSala(self::ORGANIZADORA, ['nombre' => 'El Galpón']), $this->crearSala(self::ORGANIZADORA, ['nombre' => 'La Terraza'])];
        $ajena = $this->crearSala(self::OTRO_ORGANIZADOR, ['nombre' => 'El Sótano']);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$propias[0]]);

        $respuesta = $this->pedir('GET', '/organizador/salas', self::ORGANIZADORA);

        self::assertSame(200, $respuesta->estado());
        foreach ($propias as $id) {
            self::assertStringContainsString("/organizador/salas/{$id}/editar", $respuesta->cuerpo());
            self::assertStringContainsString("/salas/{$id}/imagen", $respuesta->cuerpo());
        }
        self::assertStringContainsString('La Terraza', $respuesta->cuerpo());
        self::assertStringContainsString('PUBLICADA', $respuesta->cuerpo());
        self::assertStringContainsString('ESPERA HABILITACIÓN', $respuesta->cuerpo());
        self::assertStringNotContainsString('El Sótano', $respuesta->cuerpo());
        self::assertStringNotContainsString($ajena, $respuesta->cuerpo());
    }

    public function testMisSalasSinNingunaInvitaACargarLaPrimera(): void
    {
        $this->crearSala(self::OTRO_ORGANIZADOR);

        $respuesta = $this->pedir('GET', '/organizador/salas', self::ORGANIZADORA);

        self::assertStringContainsString('Todavía no cargaste ninguna sala.', $respuesta->cuerpo());
        self::assertSame(403, $this->pedir('GET', '/organizador/salas', self::ORGANIZADORA, 'espectador')->estado());
    }

    public function testElListadoPublicoMuestraSoloLasHabilitadasYSinSesion(): void
    {
        $habilitada = $this->crearSala(self::ORGANIZADORA, ['nombre' => 'El Galpón', 'localidad' => 'Mercedes']);
        $this->crearSala(self::ORGANIZADORA, ['nombre' => 'La Terraza']);
        $deBaja = $this->crearSala(self::OTRO_ORGANIZADOR, ['nombre' => 'El Sótano']);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id IN (?, ?)', [$habilitada, $deBaja]);
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$deBaja]);

        $respuesta = $this->pedir('GET', '/salas', null);

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('El Galpón', $respuesta->cuerpo());
        self::assertStringContainsString('Mercedes', $respuesta->cuerpo());
        self::assertStringContainsString('60 butacas', $respuesta->cuerpo());
        self::assertStringContainsString("/salas/{$habilitada}/imagen", $respuesta->cuerpo());
        self::assertStringNotContainsString('La Terraza', $respuesta->cuerpo());
        self::assertStringNotContainsString('El Sótano', $respuesta->cuerpo());
        // Es público: no muestra ni el estado ni el botón de editar.
        self::assertStringNotContainsString('/editar', $respuesta->cuerpo());

        // Y la foto que lista se ve sin sesión.
        self::assertSame(200, $this->pedir('GET', "/salas/{$habilitada}/imagen", null)->estado());
    }

    /**
     * Tres salas publicadas y una sin habilitar, para el buscador del listado.
     */
    private function salasParaBuscar(): void
    {
        $publicadas = [
            $this->crearSala(self::ORGANIZADORA, ['nombre' => 'Cine Club El Galpón', 'localidad' => 'Luján']),
            $this->crearSala(self::ORGANIZADORA, ['nombre' => 'Cine Teatro Italiano', 'localidad' => 'Mercedes']),
            $this->crearSala(self::OTRO_ORGANIZADOR, ['nombre' => 'Espacio 100% Indie', 'localidad' => 'San Andrés de Giles']),
        ];
        $this->crearSala(self::ORGANIZADORA, ['nombre' => 'La Terraza', 'localidad' => 'Luján']);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id IN (?, ?, ?)', $publicadas);
    }

    /**
     * @return list<string> los nombres de las salas del listado, en su orden
     */
    private static function nombresDelListado(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        preg_match_all('#<h3 class="tarjeta-funcion-titulo">(.*?)</h3>#', $respuesta->cuerpo(), $nombres);

        return array_map(static fn (string $nombre): string => html_entity_decode($nombre), $nombres[1]);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function busquedas(): iterable
    {
        yield 'una parte del nombre' => ['galp', ['Cine Club El Galpón']];
        yield 'varias por el nombre' => ['cine', ['Cine Club El Galpón', 'Cine Teatro Italiano']];
        yield 'la localidad' => ['mercedes', ['Cine Teatro Italiano']];
        yield 'sin mayúsculas ni tildes, y no la sin habilitar' => ['LUJAN', ['Cine Club El Galpón']];
        yield 'con espacios alrededor' => ['  italiano  ', ['Cine Teatro Italiano']];
        yield 'el % tal cual' => ['100%', ['Espacio 100% Indie']];
        yield 'un % solo no es «cualquier cosa»' => ['%', ['Espacio 100% Indie']];
        yield 'un _ no es «cualquier letra»' => ['_', []];
        yield 'una sala sin habilitar' => ['terraza', []];
        yield 'una inyección' => ["' OR '1'='1", []];
    }

    /**
     * @param list<string> $esperadas
     */
    #[DataProvider('busquedas')]
    public function testElListadoPublicoBuscaPorNombreYLocalidad(string $texto, array $esperadas): void
    {
        $this->salasParaBuscar();

        $respuesta = $this->pedir('GET', '/salas?q=' . rawurlencode($texto), null);

        self::assertSame($esperadas, self::nombresDelListado($respuesta));
        self::assertStringNotContainsString('La búsqueda no se aplicó', $respuesta->cuerpo());
        self::assertSame(4, $this->base->valor('SELECT COUNT(*) FROM sala'));
    }

    public function testElBuscadorEsUnFormularioGetConLoBuscadoYParaLimpiar(): void
    {
        $this->salasParaBuscar();

        $cuerpo = $this->pedir('GET', '/salas?q=+%3Cb%3Egalp%C3%B3n%3C%2Fb%3E+', null)->cuerpo();

        self::assertStringContainsString('<form class="filtros-cartelera buscador-salas" action="/salas" method="get" role="search"', $cuerpo);
        // Lo buscado vuelve al campo escapado y sin los espacios.
        self::assertMatchesRegularExpression('#name="q"\s+class="campo-control control-input"\s+value="&lt;b&gt;galpón&lt;/b&gt;"#', $cuerpo);
        self::assertStringNotContainsString('<b>galpón</b>', $cuerpo);
        self::assertStringContainsString('href="/salas"', $cuerpo);
        self::assertStringContainsString('Limpiar', $cuerpo);
    }

    public function testSinBusquedaNoHayNadaQueLimpiar(): void
    {
        $this->salasParaBuscar();

        $respuesta = $this->pedir('GET', '/salas?q=', null);

        self::assertCount(3, self::nombresDelListado($respuesta));
        self::assertStringNotContainsString('Limpiar', $respuesta->cuerpo());
    }

    public function testUnaBusquedaSinResultadosTieneSuMensaje(): void
    {
        $this->salasParaBuscar();

        $respuesta = $this->pedir('GET', '/salas?q=Nueve+reinas', null);

        self::assertSame([], self::nombresDelListado($respuesta));
        self::assertStringContainsString('No hay salas que coincidan con la búsqueda.', $respuesta->cuerpo());
        self::assertStringNotContainsString('Todavía no hay salas publicadas.', $respuesta->cuerpo());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function busquedasInvalidas(): iterable
    {
        yield 'como arreglo' => ['q[]=galp'];
        yield 'que no es UTF-8' => ['q=%C3%28'];
        yield 'enorme' => ['q=' . str_repeat('a', 5000)];
    }

    #[DataProvider('busquedasInvalidas')]
    public function testUnaBusquedaInvalidaMuestraTodasConUnAviso(string $consulta): void
    {
        $this->salasParaBuscar();

        $respuesta = $this->pedir('GET', "/salas?{$consulta}", null);

        self::assertCount(3, self::nombresDelListado($respuesta));
        self::assertStringContainsString('La búsqueda no se aplicó', $respuesta->cuerpo());
        self::assertStringNotContainsString('Limpiar', $respuesta->cuerpo());
    }

    public function testElListadoPublicoSinSalas(): void
    {
        self::assertStringContainsString('Todavía no hay salas publicadas.', $this->pedir('GET', '/salas', null)->cuerpo());
    }

    public function testUnaSalaQueNoExisteDa404(): void
    {
        self::assertSame(404, $this->pedir('GET', '/organizador/salas/' . Uuid::nuevo() . '/editar', self::ORGANIZADORA)->estado());
        self::assertSame(404, $this->pedir('GET', '/organizador/salas/1/editar', self::ORGANIZADORA)->estado());
    }

    /**
     * La ficha de una sala que no se ve es la página de error, igual que la de una que no existe.
     */
    private function assertFichaNoEncontrada(string $id, ?string $usuario, string $rol = 'organizador'): void
    {
        $respuesta = $this->pedir('GET', "/salas/{$id}", $usuario, $rol);

        self::assertSame(404, $respuesta->estado());
        self::assertStringContainsString('No encontramos lo que buscabas.', $respuesta->cuerpo());
    }

    public function testLaFichaDeUnaSalaQueNoExisteDa404(): void
    {
        $this->assertFichaNoEncontrada(Uuid::nuevo(), null);
        $this->assertFichaNoEncontrada('1', null);
        $this->assertFichaNoEncontrada('%27%20OR%20%271%27%3D%271', null);
    }

    public function testLaFichaDeUnaSalaSinHabilitarNoSeVePublicamente(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);

        $this->assertFichaNoEncontrada($id, null);
        $this->assertFichaNoEncontrada($id, self::OTRO_ORGANIZADOR, 'espectador');
        $this->assertFichaNoEncontrada($id, self::OTRO_ORGANIZADOR);
    }

    public function testLaFichaDeUnaSalaDadaDeBajaNoSeVe(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE, dada_de_baja_en = NOW() WHERE id = ?', [$id]);

        $this->assertFichaNoEncontrada($id, null);
        $this->assertFichaNoEncontrada($id, self::ORGANIZADORA);
    }
}
