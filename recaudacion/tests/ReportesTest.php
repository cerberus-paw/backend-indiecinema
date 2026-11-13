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
        self::assertStringNotContainsString('Qué conviene hacer', $texto);
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
        self::assertStringContainsString('value="2026-11-30"', $respuesta->cuerpo());
        self::assertStringNotContainsString('Qué conviene hacer', self::texto($respuesta));
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
}
