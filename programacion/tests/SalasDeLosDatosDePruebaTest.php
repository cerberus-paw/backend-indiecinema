<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

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
use PHPUnit\Framework\TestCase;

/**
 * Las salas de los datos de demostración, de punta a punta: después de la carga, el organizador
 * de prueba las ve en «Mis salas» y el listado público deja de estar vacío, con su foto. Es la
 * aplicación entera con las rutas de programación, las plantillas del paquete front y un MySQL de
 * verdad; sin las variables PRUEBAS_MYSQL_* se saltea.
 */
final class SalasDeLosDatosDePruebaTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
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
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);

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

    private function cargarLosDatos(): void
    {
        DatosDePrueba::para(
            $this->configuracion,
            new RepositorioDeGeneros($this->base),
            new RepositorioDeSalas($this->base),
            new RepositorioDeObras($this->base),
            new RepositorioDeFunciones($this->base),
        )->cargar();
    }

    /**
     * Un pedido como lo manda nginx: con sesión, el id y el rol del usuario y el secreto.
     */
    private function pedir(string $url, ?string $usuario = null, string $rol = 'organizador'): Respuesta
    {
        $cabeceras = $usuario === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion('GET', $url, [], [], $cabeceras, [], []));
    }

    public function testElListadoPublicoDejaDeEstarVacioYMuestraLasCuatroSalasConSuFoto(): void
    {
        self::assertStringContainsString('Todavía no hay salas publicadas.', $this->pedir('/salas')->cuerpo());

        $this->cargarLosDatos();
        $respuesta = $this->pedir('/salas');

        self::assertSame(200, $respuesta->estado());
        self::assertStringNotContainsString('Todavía no hay salas publicadas.', $respuesta->cuerpo());
        foreach (DatosDePrueba::SALAS as $sala) {
            self::assertStringContainsString(htmlspecialchars($sala['nombre']), $respuesta->cuerpo());
            self::assertStringContainsString(htmlspecialchars($sala['localidad']), $respuesta->cuerpo());
            self::assertStringContainsString("/salas/{$sala['id']}/imagen", $respuesta->cuerpo());

            // La foto que lista se ve sin sesión, desde el volumen adonde la copió la carga.
            $foto = $this->pedir("/salas/{$sala['id']}/imagen");
            self::assertSame(200, $foto->estado());
            self::assertSame('image/jpeg', $foto->cabecera('Content-Type'));
            self::assertStringEqualsFile(DatosDePrueba::rutaSala($sala['imagen']), $foto->cuerpo());
        }
    }

    public function testElOrganizadorDePruebaVeSusSalasPublicadas(): void
    {
        $this->cargarLosDatos();

        $respuesta = $this->pedir('/organizador/salas', DatosDePrueba::ORGANIZADOR_DEFECTO_ID);

        self::assertSame(200, $respuesta->estado());
        foreach (DatosDePrueba::SALAS as $sala) {
            self::assertStringContainsString(htmlspecialchars($sala['nombre']), $respuesta->cuerpo());
            self::assertStringContainsString("/organizador/salas/{$sala['id']}/editar", $respuesta->cuerpo());
        }
        self::assertSame(4, substr_count($respuesta->cuerpo(), 'PUBLICADA'));
        self::assertStringNotContainsString('ESPERA HABILITACIÓN', $respuesta->cuerpo());
    }

    public function testElOrganizadorDePruebaPuedeEditarlas(): void
    {
        $this->cargarLosDatos();
        $sala = DatosDePrueba::SALAS[0];

        $respuesta = $this->pedir("/organizador/salas/{$sala['id']}/editar", DatosDePrueba::ORGANIZADOR_DEFECTO_ID);

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('value="' . htmlspecialchars($sala['nombre']) . '"', $respuesta->cuerpo());
    }

    public function testOtroOrganizadorNoLasVeComoPropias(): void
    {
        $this->cargarLosDatos();

        self::assertStringContainsString('Todavía no cargaste ninguna sala.', $this->pedir('/organizador/salas', self::OTRO_ORGANIZADOR)->cuerpo());
        self::assertSame(404, $this->pedir('/organizador/salas/' . DatosDePrueba::SALAS[0]['id'] . '/editar', self::OTRO_ORGANIZADOR)->estado());
    }
}
