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
use IndieCinema\Programacion\Pruebas\Controladores\FormularioDeSalaTest;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
use PHPUnit\Framework\TestCase;

/**
 * El alta y la edición de punta a punta: la aplicación entera con las rutas de programación, las
 * plantillas del paquete front y un MySQL de verdad con la tabla de la migración. Sin las
 * variables PRUEBAS_MYSQL_* se saltea; el README dice cómo levantar uno con Docker.
 */
final class SalasDelOrganizadorTest extends TestCase
{
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
        if (getenv('PRUEBAS_MYSQL_HOST') === false) {
            self::markTestSkipped('Sin PRUEBAS_MYSQL_HOST no hay MySQL para probar.');
        }

        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'produccion'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => [
                'host' => getenv('PRUEBAS_MYSQL_HOST'),
                'puerto' => (int) (getenv('PRUEBAS_MYSQL_PUERTO') ?: 3306),
                'esquema' => getenv('PRUEBAS_MYSQL_ESQUEMA') ?: 'pruebas',
                'usuario' => getenv('PRUEBAS_MYSQL_USUARIO') ?: 'root',
                'clave' => (string) getenv('PRUEBAS_MYSQL_CLAVE'),
            ],
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $this->base = BaseDeDatos::conectar($this->configuracion);
        // Cada aplicación abre su conexión, así que no sirve una tabla temporal: se rehace la real.
        $this->base->ejecutar('DROP TABLE IF EXISTS sala');
        $this->base->ejecutar((string) file_get_contents(dirname(__DIR__) . '/migraciones/001_tabla_sala.sql'));

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
     * @param array<string, string> $cambios
     */
    private function crearSala(string $organizador, array $cambios = []): string
    {
        $respuesta = $this->enviar('/organizador/salas/nueva', $organizador, $cambios);
        self::assertSame(303, $respuesta->estado());
        preg_match('#^/organizador/salas/([^/]+)/editar\?guardada=1$#', (string) $respuesta->cabecera('Location'), $coincidencia);

        return $coincidencia[1] ?? self::fail('No llevó a la edición: ' . $respuesta->cabecera('Location'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fila(string $id): ?array
    {
        return $this->base->fila('SELECT organizador_id, nombre, capacidad, imagen, habilitada FROM sala WHERE id = ?', [$id]);
    }

    public function testUnOrganizadorVeElFormularioDeAlta(): void
    {
        $respuesta = $this->pedir('GET', '/organizador/salas/nueva', self::ORGANIZADORA);

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Publicar una sala indie', $respuesta->cuerpo());
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $respuesta->cuerpo());
    }

    public function testUnEspectadorNoEntraYSinSesionHayQueIngresar(): void
    {
        self::assertSame(403, $this->pedir('GET', '/organizador/salas/nueva', self::ORGANIZADORA, 'espectador')->estado());
        self::assertSame(403, $this->enviar('/organizador/salas/nueva', self::ORGANIZADORA, rol: 'espectador')->estado());
        self::assertSame(401, $this->pedir('GET', '/organizador/salas/nueva', null)->estado());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
    }

    public function testSinElTokenCsrfNoSeCrea(): void
    {
        $respuesta = $this->enviar('/organizador/salas/nueva', self::ORGANIZADORA, ['_csrf' => str_repeat('d', 64)]);

        self::assertSame(403, $respuesta->estado());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
    }

    public function testCreaLaSalaDelOrganizadorSinHabilitarYLlevaASuEdicion(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);

        self::assertTrue(Uuid::esValido($id));
        $fila = $this->fila($id);
        self::assertSame(
            ['organizador_id' => self::ORGANIZADORA, 'nombre' => 'Cine Club El Galpón', 'capacidad' => 60, 'habilitada' => 0],
            array_diff_key($fila ?? [], ['imagen' => true]),
        );
        // La imagen quedó en el volumen con un nombre al azar, no con el que mandó el navegador.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', (string) $fila['imagen']);
        self::assertSame([$fila['imagen']], $this->imagenesGuardadas());

        $edicion = $this->pedir('GET', "/organizador/salas/{$id}/editar?guardada=1", self::ORGANIZADORA);
        self::assertSame(200, $edicion->estado());
        self::assertStringContainsString('¡Sala guardada!', $edicion->cuerpo());
        self::assertStringContainsString('value="Cine Club El Galpón"', $edicion->cuerpo());
        self::assertStringContainsString('action="/organizador/salas/' . $id . '/editar"', $edicion->cuerpo());
    }

    public function testConErroresVuelveAlFormularioConLoQueEscribio(): void
    {
        $respuesta = $this->enviar('/organizador/salas/nueva', self::ORGANIZADORA, ['capacidad' => '0', 'nombre' => '<b>El Galpón</b>']);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La capacidad tiene que ser un número de butacas entre 1 y 2000.', $respuesta->cuerpo());
        // Lo que escribió vuelve escapado: Twig no deja pasar HTML.
        self::assertStringContainsString('value="&lt;b&gt;El Galpón&lt;/b&gt;"', $respuesta->cuerpo());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
    }

    public function testNoSePuedeHabilitarNiCambiarDeDuenoDesdeElFormulario(): void
    {
        $this->enviar('/organizador/salas/nueva', self::ORGANIZADORA, ['habilitada' => '1', 'organizador_id' => self::OTRO_ORGANIZADOR]);

        self::assertSame(
            ['organizador_id' => self::ORGANIZADORA, 'habilitada' => 0],
            $this->base->fila('SELECT organizador_id, habilitada FROM sala'),
        );
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

    public function testUnPdfConNombreDeJpgNoSeGuarda(): void
    {
        $respuesta = $this->enviar('/organizador/salas/nueva', self::ORGANIZADORA, imagen: 'documento.jpg');

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La foto tiene que ser JPG, PNG o WebP.', $respuesta->cuerpo());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
        self::assertSame([], $this->imagenesGuardadas());
    }

    public function testElAltaSinFotoNoSeGuarda(): void
    {
        $respuesta = $this->enviar('/organizador/salas/nueva', self::ORGANIZADORA, imagen: null);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Falta la foto de la sala.', $respuesta->cuerpo());
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

    public function testLaFotoDeUnaSalaSinHabilitarSoloLaVeSuOrganizador(): void
    {
        $id = $this->crearSala(self::ORGANIZADORA);

        self::assertSame(404, $this->pedir('GET', "/salas/{$id}/imagen", null)->estado());
        self::assertSame(404, $this->pedir('GET', "/salas/{$id}/imagen", self::OTRO_ORGANIZADOR)->estado());

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

    public function testUnaSalaQueNoExisteDa404(): void
    {
        self::assertSame(404, $this->pedir('GET', '/organizador/salas/' . Uuid::nuevo() . '/editar', self::ORGANIZADORA)->estado());
        self::assertSame(404, $this->pedir('GET', '/organizador/salas/1/editar', self::ORGANIZADORA)->estado());
    }
}
