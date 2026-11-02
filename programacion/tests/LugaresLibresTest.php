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
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use IndieCinema\Programacion\Servicios\OcupacionDeFunciones;
use PHPUnit\Framework\TestCase;

/**
 * Los lugares libres en la cartelera, la portada, la ficha de la sala y la ficha de la función
 * (E4-02.2), y que todo se siga viendo si funciones no responde (E4-02.3). De punta a punta: la
 * aplicación entera con las plantillas del paquete front, un MySQL de verdad con los datos de
 * demostración y un funciones de mentira (Dobles/ocupacion.php) atendiendo con `php -S`.
 */
final class LugaresLibresTest extends TestCase
{
    use ConBaseDePruebas;

    /** @var resource|null */
    private static $servidor = null;

    private static string $urlFunciones;
    private static string $ocupacion;

    private BaseDeDatos $base;
    private string $carpeta;

    public static function setUpBeforeClass(): void
    {
        self::$ocupacion = tempnam(sys_get_temp_dir(), 'ocupacion-');
        // Un puerto libre: el sistema da uno al pedir el 0, y se suelta para que lo use php -S.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$urlFunciones = "http://127.0.0.1:{$puerto}";

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/ocupacion.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            [...getenv(), 'OCUPACION' => self::$ocupacion],
        );
        self::$servidor = $proceso === false ? null : $proceso;
        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail('No arrancó el funciones de prueba.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
        @unlink(self::$ocupacion);
    }

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->base = self::baseConTablasNuevas($this->configuracion());
        DatosDePrueba::para(
            $this->configuracion(),
            new RepositorioDeGeneros($this->base),
            new RepositorioDeSalas($this->base),
            new RepositorioDeObras($this->base),
            new RepositorioDeFunciones($this->base),
        )->cargar();
        file_put_contents(self::$ocupacion, '{}');
    }

    protected function tearDown(): void
    {
        foreach (['salas', 'afiches'] as $subcarpeta) {
            array_map('unlink', glob("{$this->carpeta}/{$subcarpeta}/*") ?: []);
            @rmdir("{$this->carpeta}/{$subcarpeta}");
        }
        @rmdir($this->carpeta);
    }

    /**
     * @param ?string $funciones la URL de funciones; por defecto, la de mentira
     */
    private function configuracion(?string $funciones = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'subsistemas' => ['funciones' => $funciones ?? self::$urlFunciones],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
    }

    private function pedir(string $ruta, ?string $funciones = null): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion($funciones), new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', $ruta, [], [], [], [], []));
    }

    /**
     * Las dos primeras funciones programadas de la cartelera, con su cupo: a la primera se la deja
     * agotada y a la segunda con 5 lugares.
     *
     * @return array{0: array{id: string, cupo: int, sala_id: string}, 1: array{id: string, cupo: int, sala_id: string}}
     */
    private function ocuparDosFunciones(): array
    {
        $funciones = $this->base->filas(
            "SELECT id, cupo, sala_id FROM funcion WHERE estado = 'programada' AND fecha_hora > NOW() ORDER BY fecha_hora, id LIMIT 2",
        );
        [$agotada, $conLugar] = array_map(static fn (array $fila): array => ['id' => (string) $fila['id'], 'cupo' => (int) $fila['cupo'], 'sala_id' => (string) $fila['sala_id']], $funciones);
        file_put_contents(self::$ocupacion, json_encode([$agotada['id'] => $agotada['cupo'], $conLugar['id'] => $conLugar['cupo'] - 5]));

        return [$agotada, $conLugar];
    }

    public function testLaCarteleraMuestraLosLugaresLibresYLasAgotadas(): void
    {
        [$agotada, $conLugar] = $this->ocuparDosFunciones();

        $respuesta = $this->pedir('/cartelera');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('5 lugares libres', $respuesta->cuerpo());
        self::assertStringContainsString('Agotada', $respuesta->cuerpo());
        // El resto, sin reservas, con el cupo entero libre.
        self::assertMatchesRegularExpression('/\d+ lugares libres/', str_replace('5 lugares libres', '', $respuesta->cuerpo()));
    }

    public function testLaCarteleraEnJsonTraeLosLugaresLibres(): void
    {
        [$agotada, $conLugar] = $this->ocuparDosFunciones();

        $respuesta = $this->pedir('/cartelera');
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', fopen('php://memory', 'w')));
        $json = json_decode($aplicacion->atender(new Peticion('GET', '/cartelera', [], [], ['Accept' => 'application/json'], [], []))->cuerpo(), true);
        $porId = array_column($json['funciones'], null, 'id');

        self::assertSame(200, $respuesta->estado());
        self::assertSame(0, $porId[$agotada['id']]['lugares_libres']);
        self::assertTrue($porId[$agotada['id']]['agotada']);
        self::assertSame(5, $porId[$conLugar['id']]['lugares_libres']);
        self::assertFalse($porId[$conLugar['id']]['agotada']);
    }

    public function testLaFichaMuestraLosLugaresYLaBarraYElMaximoDelFormulario(): void
    {
        [, $conLugar] = $this->ocuparDosFunciones();

        $cuerpo = $this->pedir("/cartelera/{$conLugar['id']}")->cuerpo();

        self::assertStringContainsString('5 lugares libres', $cuerpo);
        self::assertStringContainsString('<progress class="ficha-reserva-barra" max="' . $conLugar['cupo'] . '" value="' . ($conLugar['cupo'] - 5) . '"', $cuerpo);
        self::assertStringContainsString('de ' . $conLugar['cupo'], $cuerpo);
    }

    public function testUnaFichaAgotadaNoOfreceReservar(): void
    {
        [$agotada] = $this->ocuparDosFunciones();

        $cuerpo = $this->pedir("/cartelera/{$agotada['id']}")->cuerpo();

        self::assertStringContainsString('Función agotada', $cuerpo);
        self::assertStringNotContainsString('Reservá tus lugares', $cuerpo);
        self::assertStringNotContainsString('/funcion/reservar/', $cuerpo);
    }

    public function testUnaFuncionQueYaPasoNoDiceAgotada(): void
    {
        // Se tomaron todos los lugares, pero ya se dio: la ficha no ofrece esperar una cancelación.
        [$agotada] = $this->ocuparDosFunciones();
        $this->base->ejecutar('UPDATE funcion SET fecha_hora = NOW() - INTERVAL 1 DAY WHERE id = ?', [$agotada['id']]);

        $cuerpo = $this->pedir("/cartelera/{$agotada['id']}")->cuerpo();

        self::assertStringNotContainsString('Función agotada', $cuerpo);
        self::assertStringNotContainsString('lugares libres', $cuerpo);
    }

    public function testMasDeCienFuncionesSePidenDeACien(): void
    {
        // Como la ficha de una sala con muchas funciones: funciones no acepta más de 100 por pedido.
        $ids = array_map(static fn (): string => Uuid::nuevo(), range(1, 150));
        file_put_contents(self::$ocupacion, json_encode([$ids[0] => 3, $ids[149] => 7]));

        $ocupados = (new OcupacionDeFunciones($this->configuracion(), new Log('programacion', fopen('php://memory', 'w'))))->deVarias($ids);

        self::assertIsArray($ocupados);
        self::assertCount(150, $ocupados);
        self::assertSame([3, 7], [$ocupados[$ids[0]], $ocupados[$ids[149]]]);
    }

    public function testLaPortadaYLaFichaDeLaSalaTambienLosMuestran(): void
    {
        [, $conLugar] = $this->ocuparDosFunciones();

        self::assertStringContainsString('lugares libres', $this->pedir('/')->cuerpo());
        self::assertStringContainsString('lugares libres', $this->pedir("/salas/{$conLugar['sala_id']}")->cuerpo());
    }

    public function testSinFuncionesLaCarteleraYLaFichaSeVenIgualSinLosLugares(): void
    {
        [, $conLugar] = $this->ocuparDosFunciones();
        // Un puerto en el que no escucha nadie: como funciones apagado.
        $apagado = 'http://127.0.0.1:9';

        $cartelera = $this->pedir('/cartelera', $apagado);
        $ficha = $this->pedir("/cartelera/{$conLugar['id']}", $apagado);
        $portada = $this->pedir('/', $apagado);

        self::assertSame(200, $cartelera->estado());
        self::assertStringNotContainsString('lugares libres', $cartelera->cuerpo());
        self::assertSame(200, $portada->estado());
        self::assertSame(200, $ficha->estado());
        self::assertStringNotContainsString('ficha-reserva-barra', $ficha->cuerpo());
        // Sin el dato se puede reservar igual: el cupo lo controla funciones al reservar.
        self::assertStringContainsString('Reservá tus lugares', $ficha->cuerpo());
    }
}
