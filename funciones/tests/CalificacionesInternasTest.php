<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Los promedios y las últimas calificaciones que pide programación para sus fichas (EF-04.3), con
 * la base de verdad y un cuentas de mentira para los nombres.
 */
final class CalificacionesInternasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;

    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';
    private const TOKEN_DE_RECAUDACION = 'token-de-recaudacion';
    private const SALA = '5a5a5a5a-5a5a-45a5-85a5-5a5a5a5a5a5a';
    private const OTRA_SALA = '6b6b6b6b-6b6b-46b6-86b6-6b6b6b6b6b6b';
    private const ORGANIZADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const ZAMA = '0b0b0b0b-0b0b-40b0-80b0-0b0b0b0b0b0b';
    private const CIENAGA = '0c0c0c0c-0c0c-40c0-80c0-0c0c0c0c0c0c';
    private const UNA = '13131313-1313-4313-8313-131313131313';
    private const OTRA = '24242424-2424-4424-8424-242424242424';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::iniciarCuentas();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerCuentas();
    }

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'subsistemas' => ['cuentas' => self::$urlCuentas],
            'interno' => ['token' => 'token-de-funciones'],
            'llamadores' => [
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
                'recaudacion' => hash('sha256', self::TOKEN_DE_RECAUDACION),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    private function pedir(string $ruta, string $token = self::TOKEN_DE_PROGRAMACION, string $llamador = 'programacion'): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return $aplicacion->atender(new Peticion('GET', (string) parse_url($ruta, PHP_URL_PATH), $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());

        return json_decode($respuesta->cuerpo(), true);
    }

    /**
     * Una calificación con su reserva, sin pasar por la página.
     *
     * @param list<string> $obras
     */
    private function calificacion(string $funcion, string $sala, string $usuario, int $puntaje, ?string $comentario, array $obras, string $creada, int $lugares = 1): void
    {
        $reserva = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, 0, 'asistio', ?)",
            [$reserva, $funcion, $usuario, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $lugares, $creada],
        );
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO calificacion (id, funcion_id, usuario_id, reserva_id, puntaje, comentario, sala_id, organizador_id, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $funcion, $usuario, $reserva, $puntaje, $comentario, $sala, self::ORGANIZADORA, $creada],
        );
        foreach ($obras as $obra) {
            $this->base->ejecutar('INSERT INTO calificacion_obra (calificacion_id, obra_id) VALUES (?, ?)', [$id, $obra]);
        }
    }

    private function cargar(): void
    {
        $this->calificacion(self::UNA, self::SALA, self::EVA, 5, 'Hermosa copia.', [self::ZAMA], '2026-11-01 23:00:00', 2);
        $this->calificacion(self::UNA, self::SALA, self::ELIAS, 4, null, [self::ZAMA], '2026-11-02 12:00:00');
        $this->calificacion(self::OTRA, self::OTRA_SALA, self::EVA, 2, 'Se cortó el sonido.', [self::ZAMA, self::CIENAGA], '2026-11-03 12:00:00');
    }

    public function testElResumenDaElPromedioYLaCantidadDeCadaUno(): void
    {
        $this->cargar();
        $sinCalificar = Uuid::nuevo();

        $datos = $this->json($this->pedir('/interno/calificaciones/resumen?salas=' . self::SALA . ',' . self::OTRA_SALA . ",{$sinCalificar}&obras=" . self::ZAMA . ',' . self::CIENAGA . '&organizadores=' . self::ORGANIZADORA . '&funciones=' . self::UNA . ",{$sinCalificar}"));

        self::assertEquals([
            self::SALA => ['promedio' => 4.5, 'cantidad' => 2],
            self::OTRA_SALA => ['promedio' => 2, 'cantidad' => 1],
        ], $datos['salas']);
        // Zama se dio en las dos funciones: (5 + 4 + 2) / 3.
        self::assertEquals([
            self::ZAMA => ['promedio' => 3.7, 'cantidad' => 3],
            self::CIENAGA => ['promedio' => 2, 'cantidad' => 1],
        ], $datos['obras']);
        self::assertEquals([self::ORGANIZADORA => ['promedio' => 3.7, 'cantidad' => 3]], $datos['organizadores']);
        self::assertEquals([self::UNA => ['promedio' => 4.5, 'cantidad' => 2]], $datos['funciones']);
        // Los que entraron: 2 de Eva y 1 de Elías. La que no tiene reservas, 0.
        self::assertSame([self::UNA => 3, $sinCalificar => 0], $datos['asistentes']);
    }

    public function testSinNadaQuePedirLasListasVienenVaciasComoObjetos(): void
    {
        $respuesta = $this->pedir('/interno/calificaciones/resumen');

        self::assertSame(200, $respuesta->estado());
        self::assertSame('{"salas":{},"obras":{},"organizadores":{},"funciones":{},"asistentes":{}}', $respuesta->cuerpo());
    }

    public function testLasUltimasDeUnaSalaODeUnaObraConElNombreDeQuienCalifico(): void
    {
        $this->cargar();

        $deLaSala = $this->json($this->pedir('/interno/calificaciones?sala=' . self::SALA))['calificaciones'];
        self::assertSame([
            ['funcion_id' => self::UNA, 'puntaje' => 4, 'comentario' => null, 'espectador' => 'Elías', 'fecha' => '2026-11-02'],
            // Las 23 h en UTC son las 20 h en la Argentina: el mismo día.
            ['funcion_id' => self::UNA, 'puntaje' => 5, 'comentario' => 'Hermosa copia.', 'espectador' => 'Eva', 'fecha' => '2026-11-01'],
        ], $deLaSala);

        $deLaObra = $this->json($this->pedir('/interno/calificaciones?obra=' . self::CIENAGA))['calificaciones'];
        self::assertSame(['Se cortó el sonido.'], array_column($deLaObra, 'comentario'));
    }

    public function testPideUnaSalaOUnaObraYSoloLaLlamaProgramacion(): void
    {
        self::assertSame(400, $this->pedir('/interno/calificaciones')->estado());
        self::assertSame(400, $this->pedir('/interno/calificaciones?sala=no-es-un-uuid')->estado());
        self::assertSame(403, $this->pedir('/interno/calificaciones?sala=' . self::SALA, 'otro-token')->estado());
        self::assertSame(403, $this->pedir('/interno/calificaciones/resumen', 'otro-token')->estado());
        $muchas = implode(',', array_map(static fn (): string => Uuid::nuevo(), range(1, 101)));
        self::assertSame(400, $this->pedir("/interno/calificaciones/resumen?salas={$muchas}")->estado());
    }

    /**
     * La reportería de recaudación (EF-09) pide los promedios de las funciones; las últimas, con el
     * nombre de cada espectador, siguen siendo sólo para las fichas de programación.
     */
    public function testRecaudacionPideElResumenPeroNoLasUltimas(): void
    {
        self::assertSame(200, $this->pedir('/interno/calificaciones/resumen?funciones=' . self::UNA, self::TOKEN_DE_RECAUDACION, 'recaudacion')->estado());
        self::assertSame(403, $this->pedir('/interno/calificaciones?sala=' . self::SALA, self::TOKEN_DE_RECAUDACION, 'recaudacion')->estado());
    }
}
