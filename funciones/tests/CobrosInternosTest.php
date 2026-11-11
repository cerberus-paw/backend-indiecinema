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
 * Lo cobrado de cada función para recaudación (EF-02.5), de punta a punta: la aplicación entera,
 * la autorización por token de la API interna y un MySQL de verdad.
 */
final class CobrosInternosTest extends TestCase
{
    use ConBaseDePruebas;

    private const TOKEN_DE_RECAUDACION = 'token-de-recaudacion';
    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';
    private const FUNCION = '12121212-1212-4212-8212-121212121212';
    private const OTRA = '34343434-3434-4434-8434-343434343434';
    private const SIN_RESERVAS = '56565656-5656-4656-8656-565656565656';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    /** @var array<string, string> nombre => id de cada reserva de la prueba */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'recaudacion' => hash('sha256', self::TOKEN_DE_RECAUDACION),
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        // Pagadas con Mercado Pago: una confirmada y una con asistencia.
        $this->reserva('pagada', self::FUNCION, 2, '7000.00', 'confirmada', 'pago-1', '2026-11-01 10:00:00');
        $this->reserva('asistio', self::FUNCION, 1, '3500.50', 'asistio', 'pago-2', '2026-11-01 11:00:00');
        // Se confirmó con el cobro apagado: total, pero nada cobrado.
        $this->reserva('sin_cobro', self::FUNCION, 1, '3500.00', 'confirmada', null, '2026-11-01 12:00:00');
        // No cuentan: la que todavía espera el pago y la cancelada.
        $this->reserva('pendiente', self::FUNCION, 1, '3500.00', 'pendiente_pago', null, '2026-11-01 13:00:00');
        $this->reserva('cancelada', self::FUNCION, 3, '10500.00', 'cancelada', null, '2026-11-01 14:00:00');
        $this->reserva('de_otra', self::OTRA, 1, '0.00', 'confirmada', null, '2026-11-01 15:00:00');
    }

    private function reserva(string $nombre, string $funcion, int $cantidad, string $total, string $estado, ?string $pago, string $creada): void
    {
        $this->ids[$nombre] = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en, vence_en, pago_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->ids[$nombre], $funcion, '99999999-9999-4999-8999-999999999999', strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                $cantidad, $total, $estado, $creada, $estado === 'pendiente_pago' ? '2099-01-01 00:00:00' : null, $pago,
            ],
        );
    }

    private function pedir(string $ruta, string $llamador = 'recaudacion', string $token = self::TOKEN_DE_RECAUDACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return $aplicacion->atender(new Peticion('GET', (string) parse_url($ruta, PHP_URL_PATH), $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    public function testDeUnaFuncionDaCadaReservaEnPieConLoCobradoYElNeto(): void
    {
        $respuesta = $this->pedir('/interno/funciones/' . self::FUNCION . '/cobros');

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $datos = json_decode($respuesta->cuerpo(), true);
        self::assertSame(self::FUNCION, $datos['funcion_id']);
        self::assertSame([$this->ids['pagada'], $this->ids['asistio'], $this->ids['sin_cobro']], array_column($datos['reservas'], 'id'));
        self::assertSame([
            'id' => $this->ids['pagada'],
            'usuario_id' => '99999999-9999-4999-8999-999999999999',
            'cantidad' => 2,
            'total' => '7000.00',
            'cobrado' => '7000.00',
            'pago_id' => 'pago-1',
            'estado' => 'confirmada',
            'reembolsada' => false,
        ], $datos['reservas'][0]);
        // La confirmada sin pago tiene total, pero no se cobró nada.
        self::assertSame(['3500.00', '0.00', null], [$datos['reservas'][2]['total'], $datos['reservas'][2]['cobrado'], $datos['reservas'][2]['pago_id']]);
        // 7000.00 + 3500.50, sumado en centavos.
        self::assertSame('10500.50', $datos['neto']);
    }

    public function testDeVariasEnUnPedidoConLasQueNoTienenReservasEnCero(): void
    {
        $datos = json_decode($this->pedir('/interno/cobros?funciones=' . self::FUNCION . ',' . self::OTRA . ',' . self::SIN_RESERVAS)->cuerpo(), true);

        self::assertSame([self::FUNCION, self::OTRA, self::SIN_RESERVAS], array_keys($datos['cobros']));
        self::assertSame('10500.50', $datos['cobros'][self::FUNCION]['neto']);
        // A la gorra: la reserva está, con total y cobrado en cero.
        self::assertSame('0.00', $datos['cobros'][self::OTRA]['neto']);
        self::assertSame('0.00', $datos['cobros'][self::OTRA]['reservas'][0]['cobrado']);
        self::assertSame(['reservas' => [], 'neto' => '0.00'], $datos['cobros'][self::SIN_RESERVAS]);
    }

    public function testSoloLaLlamaRecaudacionYValidaLosIds(): void
    {
        self::assertSame(403, $this->pedir('/interno/funciones/' . self::FUNCION . '/cobros', 'programacion', self::TOKEN_DE_PROGRAMACION)->estado());
        self::assertSame(403, $this->pedir('/interno/cobros?funciones=' . self::FUNCION, 'programacion', self::TOKEN_DE_PROGRAMACION)->estado());
        self::assertSame(403, $this->pedir('/interno/cobros?funciones=' . self::FUNCION, token: 'otro-token')->estado());
        self::assertSame(404, $this->pedir('/interno/funciones/no-es-un-uuid/cobros')->estado());
        self::assertSame(400, $this->pedir('/interno/cobros')->estado());
        self::assertSame(400, $this->pedir('/interno/cobros?funciones=no-es-un-uuid')->estado());
        $muchas = implode(',', array_map(static fn (): string => Uuid::nuevo(), range(1, 101)));
        self::assertSame(400, $this->pedir("/interno/cobros?funciones={$muchas}")->estado());
    }
}
