<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use PHPUnit\Framework\TestCase;

/**
 * GET /recaudacion/reportes de punta a punta (EF-09.1): la aplicación entera con sus rutas y el
 * paquete front, contra un programación y un funciones de mentira con `php -S`, como en el
 * compose. Los datos de los dobles están armados para que salga cada sugerencia
 * (Dobles/programacion.php).
 */
final class ReportesTest extends TestCase
{
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const SIN_FUNCIONES = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ADMINISTRADORA = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const NOVIEMBRE = '/reportes?desde=2026-11-01&hasta=2026-11-30';
    private const DOS_MESES = 'desde=2026-10-01&hasta=2026-11-30';
    private const NORTE = '10000000-0000-4000-8000-000000000002';

    /** @var list<resource> */
    private static array $servidores = [];

    private static string $programacion;
    private static string $funciones;

    /** Un puerto donde no atiende nadie: un subsistema caído. */
    private static string $caido;

    public static function setUpBeforeClass(): void
    {
        self::$programacion = self::levantar('programacion.php');
        self::$funciones = self::levantar('funciones.php');
        self::$caido = 'http://127.0.0.1:' . self::puertoLibre();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servidores as $servidor) {
            proc_terminate($servidor);
            proc_close($servidor);
        }
        self::$servidores = [];
    }

    private static function puertoLibre(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $puerto;
    }

    private static function levantar(string $doble): string
    {
        $puerto = self::puertoLibre();
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . "/Dobles/{$doble}"],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
        );
        if ($proceso !== false) {
            self::$servidores[] = $proceso;
        }
        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return "http://127.0.0.1:{$puerto}";
            }
            usleep(20_000);
        }
        self::fail("No arrancó el doble {$doble}.");
    }

    /**
     * @param array<string, string> $subsistemas los que cambian: para tirar uno abajo
     */
    private function pedir(string $ruta, string $usuario = self::ORGANIZADORA, string $rol = 'organizador', array $subsistemas = []): Respuesta
    {
        $configuracion = new Configuracion([
            'app' => ['subsistema' => 'recaudacion', 'prefijo' => '/recaudacion', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'subsistemas' => [...['programacion' => self::$programacion, 'funciones' => self::$funciones], ...$subsistemas],
            'interno' => ['token' => 'token-de-recaudacion'],
        ]);
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('recaudacion', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return PaginasParaValidar::anotar('GET', $ruta, $aplicacion->atender(new Peticion('GET', (string) parse_url($ruta, PHP_URL_PATH), $consulta, [], [
            'X-Nginx-Secreto' => 'secreto-de-nginx',
            'X-Usuario-Id' => $usuario,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Olga Organizadora'),
        ], [], [])));
    }

    /** El texto que se ve, sin etiquetas ni espacios de más: para buscar frases enteras. */
    private static function texto(Respuesta $respuesta): string
    {
        return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($respuesta->cuerpo())));
    }

    public function testElOrganizadorVeLasSugerenciasDeSusSalas(): void
    {
        $respuesta = $this->pedir(self::NOVIEMBRE);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $texto = self::texto($respuesta);
        self::assertStringContainsString('Lo que pasó en tus salas del 1 al 30 de noviembre de 2026', $texto);
        // Las tarjetas: 90 de 160 lugares, 83 de 90 fueron, (45 + 34,4 + 6) / 20.
        self::assertStringContainsString('Funciones 4 finalizadas', $texto);
        self::assertStringContainsString('Ocupación 56 %', $texto);
        self::assertStringContainsString('Asistencia 92 %', $texto);
        self::assertStringContainsString('Calificación 4,3', $texto);
        // Los miércoles, 16 de 80; el resto, 74 de 80.
        self::assertStringContainsString('El Galpón, los miércoles: vendió el 20 % de los lugares, contra el 93 % del resto de la semana.', $texto);
        self::assertStringContainsString('Probá una promoción los miércoles', $texto);
        // «Rojo»: 74 de 80, 70 de 74 fueron, 4,4 de promedio.
        self::assertMatchesRegularExpression('/Rojo 2 93 % 95 % 4,4/', $texto);
        self::assertStringNotContainsString('Ningún título llegó', $texto);
        self::assertStringContainsString('La ciénaga: 4 votos en 1 votación que perdió, el 40 % de los votos.', $texto);
        // Diez votaron y dos reservaron.
        self::assertMatchesRegularExpression('/El Galpón 4 56 % 92 % 4,3 20 %/', $texto);
        // Sólo sus salas.
        self::assertStringNotContainsString('Cineclub Norte', $texto);
        // En el panel del organizador, con «Reportes» marcado.
        self::assertMatchesRegularExpression('#<a href="/recaudacion/reportes" class="panel-menu-enlace activo" aria-current="page">Reportes</a>#', $respuesta->cuerpo());
    }

    public function testElAdministradorVeTodasLasSalas(): void
    {
        $respuesta = $this->pedir(self::NOVIEMBRE, self::ADMINISTRADORA, 'administrador');

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $texto = self::texto($respuesta);
        self::assertStringContainsString('Lo que pasó en todas las salas', $texto);
        self::assertStringContainsString('Funciones 5 finalizadas', $texto);
        // El Galpón rinde más que el Norte: 56 % contra 50 %.
        self::assertMatchesRegularExpression('/El Galpón 4 56 %.*Cineclub Norte 1 50 % 75 % — —/', $texto);
        self::assertStringContainsString('aria-label="Administración"', $respuesta->cuerpo());
    }

    public function testSinFuncionesEnElPeriodoLoDice(): void
    {
        $texto = self::texto($this->pedir(self::NOVIEMBRE, self::SIN_FUNCIONES));

        self::assertStringContainsString('No hubo funciones finalizadas en el período.', $texto);
        self::assertStringNotContainsString('Días para una promoción', $texto);
    }

    public function testSiFuncionesNoRespondeSaleLoQueSeVotoConUnAviso(): void
    {
        $respuesta = $this->pedir(self::NOVIEMBRE, subsistemas: ['funciones' => self::$caido]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $texto = self::texto($respuesta);
        self::assertStringContainsString('Funciones no respondió: faltan las ventas', $texto);
        self::assertStringContainsString('La ciénaga: 4 votos', $texto);
        self::assertStringContainsString('Sin las ventas de funciones no se puede calcular.', $texto);
        self::assertStringNotContainsString('Ocupación', $texto);
    }

    public function testSiProgramacionNoRespondeLoAvisaSinUnError(): void
    {
        $respuesta = $this->pedir(self::NOVIEMBRE, subsistemas: ['programacion' => self::$caido]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringContainsString('Programación no respondió', self::texto($respuesta));
    }

    public function testUnPeriodoQueNoSirveSeExplicaYConservaLoQueSeEligio(): void
    {
        $respuesta = $this->pedir('/reportes?desde=2026-11-30&hasta=2026-11-01');

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('El último día no puede ser anterior al primero.', self::texto($respuesta));
        self::assertStringContainsString('Lo que pasó en tus salas, y qué conviene hacer con eso.', self::texto($respuesta));
        self::assertStringContainsString('value="2026-11-30"', $respuesta->cuerpo());
        self::assertStringNotContainsString('Días para una promoción', self::texto($respuesta));
    }

    public function testSinPeriodoMuestraLosUltimosNoventaDias(): void
    {
        $respuesta = $this->pedir('/reportes');

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringContainsString('value="' . date('Y-m-d') . '"', $respuesta->cuerpo());
    }

    public function testUnEspectadorNoEntra(): void
    {
        self::assertSame(403, $this->pedir(self::NOVIEMBRE, rol: 'espectador')->estado());
    }

    // Totales por mes y por función, y el CSV (EF-09.2).

    public function testFuncionesMuestraCadaMesElTotalYLasDelUltimoMes(): void
    {
        $respuesta = $this->pedir('/reportes/funciones?' . self::DOS_MESES);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $texto = self::texto($respuesta);
        // Octubre: 20 de 40, 18 fueron, sin calificaciones.
        self::assertStringContainsString('Octubre de 2026 1 20 de 40 50 % 18 90 % $ 70.000 —', $texto);
        // Noviembre: 90 de 160, 83 de 90 fueron, 315.000 cobrados.
        self::assertStringContainsString('Noviembre de 2026 4 90 de 160 56 % 83 92 % $ 315.000 4,3', $texto);
        self::assertStringContainsString('Total del período 5 110 de 200 55 % 101 92 % $ 385.000 4,3', $texto);
        // Sin elegir, el último mes con funciones.
        self::assertStringContainsString('Funciones de noviembre de 2026', $texto);
        self::assertStringContainsString('Rojo sábado 7, 21:00 El Galpón 38 de 40 95 % 36 95 % $ 133.000 4,5 10 calificaciones', $texto);
        self::assertStringNotContainsString('HIPERVINCULO', $texto);
        self::assertStringContainsString('href="/recaudacion/reportes/funciones.csv?desde=2026-10-01&amp;hasta=2026-11-30"', $respuesta->cuerpo());
        self::assertMatchesRegularExpression('#<a href="/recaudacion/reportes/funciones\?desde=2026-10-01&amp;hasta=2026-11-30" class="reporte-pestana activa" aria-current="page">#', $respuesta->cuerpo());
    }

    public function testElMesSeEligeDesdeLaTablaDeLosMeses(): void
    {
        $respuesta = $this->pedir('/reportes/funciones?' . self::DOS_MESES . '&mes=2026-10');

        $texto = self::texto($respuesta);
        self::assertStringContainsString('Funciones de octubre de 2026', $texto);
        self::assertStringContainsString('=HIPERVINCULO("x") jueves 15, 20:00 El Galpón 20 de 40', $texto);
        self::assertStringNotContainsString('Rojo sábado', $texto);
        self::assertStringContainsString('href="/recaudacion/reportes/funciones?desde=2026-10-01&amp;hasta=2026-11-30&amp;mes=2026-10" class="tabla-admin-principal" aria-current="true"', $respuesta->cuerpo());
    }

    public function testElFiltroPorSalaYUnaSalaAjenaNoFiltra(): void
    {
        $delNorte = self::texto($this->pedir('/reportes/funciones?' . self::DOS_MESES . '&sala=' . self::NORTE, self::ADMINISTRADORA, 'administrador'));
        self::assertStringContainsString('Total del período 1 20 de 40', $delNorte);
        self::assertStringContainsString('Zama domingo 8, 20:00 Cineclub Norte', $delNorte);

        // El Norte no es de la organizadora: el filtro no aplica y ve las suyas.
        self::assertStringContainsString('Total del período 5 110 de 200', self::texto($this->pedir('/reportes/funciones?' . self::DOS_MESES . '&sala=' . self::NORTE)));
    }

    public function testElCsvTraeTodasLasFuncionesDelPeriodoSinFormulas(): void
    {
        $respuesta = $this->pedir('/reportes/funciones.csv?' . self::DOS_MESES);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('text/csv; charset=utf-8', $respuesta->cabecera('Content-Type'));
        self::assertSame('attachment; filename="funciones-2026-10-01-al-2026-11-30.csv"', $respuesta->cabecera('Content-Disposition'));
        $lineas = explode("\r\n", rtrim($respuesta->cuerpo(), "\r\n"));
        self::assertCount(6, $lineas);
        self::assertSame("\u{FEFF}Fecha;Hora;Sala;Obras;Lugares;Vendidas;\"Ocupación (%)\";Asistentes;Cobrado;Promedio;Calificaciones", $lineas[0]);
        // La primera es la de octubre: el título empieza con «=» y va con un apóstrofo adelante.
        self::assertSame('2026-10-15;20:00;"El Galpón";"\'=HIPERVINCULO(""x"")";40;20;50;18;70000,00;;0', $lineas[1]);
        self::assertSame('2026-11-07;21:00;"El Galpón";Rojo;40;38;95;36;133000,00;4,5;10', $lineas[3]);
    }

    public function testLasDosPartesDelReporteSeEnlazanConElMismoPeriodo(): void
    {
        $cuerpo = $this->pedir(self::NOVIEMBRE)->cuerpo();

        self::assertStringContainsString('<a href="/recaudacion/reportes/funciones?desde=2026-11-01&amp;hasta=2026-11-30" class="reporte-pestana">Funciones y meses</a>', $cuerpo);
    }

    public function testLosTotalesConUnPeriodoQueNoSirveDan422(): void
    {
        self::assertSame(422, $this->pedir('/reportes/funciones?desde=2026-11-30&hasta=2026-11-01')->estado());
        self::assertSame(422, $this->pedir('/reportes/funciones.csv?desde=2026-11-30&hasta=2026-11-01')->estado());
    }
}
