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
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\TestCase;

/**
 * GET /organizador/funciones de punta a punta: la aplicación entera con las rutas de programación,
 * la plantilla del paquete front y un MySQL de verdad con las tablas de las migraciones. Sin las
 * variables PRUEBAS_MYSQL_* se saltea; el README dice cómo levantar uno con Docker.
 *
 * Corre en desarrollo, con las variables estrictas de Twig: una que falte en la plantilla rompe la
 * prueba en vez de quedar vacía.
 */
final class FuncionesDelOrganizadorTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO_ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeSalas $salas;
    private RepositorioDeFunciones $funciones;

    /** @var array<string, string> los títulos del catálogo y su id */
    private array $obras = [];

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->salas = new RepositorioDeSalas($this->base);
        $this->funciones = new RepositorioDeFunciones($this->base);
        $this->salidaDelLog = fopen('php://memory', 'w+');

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);
        $obras = new RepositorioDeObras($this->base);
        foreach (['Zama', 'La ciénaga'] as $titulo) {
            $obra = Obra::nueva(self::ORGANIZADORA, $titulo, 'Sinopsis.', 2001, 'Lucrecia Martel', 103, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
            $obras->agregar($obra);
            $this->obras[$titulo] = $obra->id;
        }
    }

    private function pedir(?string $usuario, string $rol = 'organizador'): Respuesta
    {
        $cabeceras = $usuario === null
            ? []
            : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion('GET', '/organizador/funciones', [], [], $cabeceras, [], []));
    }

    /**
     * Una sala guardada, como la lee el repositorio. Queda habilitada, porque sólo en una
     * habilitada se crean funciones: la prueba que la necesita sin habilitar la deshabilita
     * después.
     */
    private function sala(string $organizador, string $nombre): Sala
    {
        $sala = Sala::nueva($organizador, new DatosDeSala($nombre, 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15), 'sala.jpg');
        $this->salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        return $this->salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');
    }

    /**
     * Una función en votación de títulos, con las dos obras como candidatas.
     */
    private function funcion(Sala $sala): Funcion
    {
        $funcion = $sala->crearFuncion(array_values($this->obras), 40, 3500.0);
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    /**
     * Una ya programada para esa fecha. Cada cambio de estado se guarda antes del siguiente, como
     * pide el repositorio.
     */
    private function programada(Sala $sala, string $fecha, string $titulo = 'Zama'): Funcion
    {
        $funcion = $this->funcion($sala);
        $funcion->cerrarVotacionTitulos([$this->obras[$titulo]]);
        $this->funciones->guardar($funcion);
        $funcion->fijarFechaHora(new DateTimeImmutable($fecha));
        $this->funciones->guardar($funcion);

        return $funcion;
    }

    public function testMuestraSoloLasFuncionesDeSusSalas(): void
    {
        $propia = $this->funcion($this->sala(self::ORGANIZADORA, 'El Galpón'));
        $ajena = $this->funcion($this->sala(self::OTRO_ORGANIZADOR, 'El Sótano'));

        $respuesta = $this->pedir(self::ORGANIZADORA);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringContainsString($propia->id, $respuesta->cuerpo());
        self::assertStringContainsString('El Galpón', $respuesta->cuerpo());
        self::assertStringNotContainsString($ajena->id, $respuesta->cuerpo());
        self::assertStringNotContainsString('El Sótano', $respuesta->cuerpo());
    }

    public function testMuestraElEstadoYLaFechaDeCadaFuncion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $this->programada($sala, '2030-09-19 20:30:00');
        $this->funcion($sala);

        $cuerpo = $this->pedir(self::ORGANIZADORA)->cuerpo();

        // La programada, con la fecha escrita como en la cartelera y su título ya elegido.
        self::assertStringContainsString('Jue 19 sep · 20:30', $cuerpo);
        self::assertStringContainsString('programada', $cuerpo);
        // La que sigue en votación: sin fecha y con las candidatas.
        self::assertStringContainsString('Sin fecha todavía', $cuerpo);
        self::assertStringContainsString('en votación de títulos', $cuerpo);
        self::assertStringContainsString('La ciénaga + Zama', $cuerpo);
        self::assertStringContainsString('títulos candidatos', $cuerpo);
    }

    public function testLaCanceladaSigueEnElPanel(): void
    {
        $funcion = $this->programada($this->sala(self::ORGANIZADORA, 'El Galpón'), '2030-09-19 20:30:00');
        $funcion->cancelar();
        $this->funciones->guardar($funcion);

        $cuerpo = $this->pedir(self::ORGANIZADORA)->cuerpo();

        self::assertStringContainsString($funcion->id, $cuerpo);
        self::assertStringContainsString('cancelada', $cuerpo);
    }

    public function testLaFuncionDeUnaSalaSinHabilitarNoEnlazaASuFicha(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-09-19 20:30:00');
        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$sala->id]);

        $cuerpo = $this->pedir(self::ORGANIZADORA)->cuerpo();

        // Está en el panel, pero sin enlace: hasta que la habiliten no hay ficha que mostrar.
        self::assertStringContainsString($funcion->id, $cuerpo);
        self::assertStringContainsString('esperando habilitación', $cuerpo);
        self::assertStringNotContainsString("/cartelera/{$funcion->id}", $cuerpo);
    }

    public function testLaFuncionDeUnaSalaDadaDeBajaNoAparece(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$sala->id]);

        self::assertStringNotContainsString($funcion->id, $this->pedir(self::ORGANIZADORA)->cuerpo());
    }

    public function testSinFuncionesLoDice(): void
    {
        $this->funcion($this->sala(self::OTRO_ORGANIZADOR, 'El Sótano'));

        self::assertStringContainsString('Todavía no tenés ninguna función en tus salas.', $this->pedir(self::ORGANIZADORA)->cuerpo());
    }

    public function testUnEspectadorNoEntraYSinSesionHayQueIngresar(): void
    {
        $this->funcion($this->sala(self::ORGANIZADORA, 'El Galpón'));

        self::assertSame(403, $this->pedir(self::ORGANIZADORA, 'espectador')->estado());
        self::assertSame(401, $this->pedir(null)->estado());
    }
}
