<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Pruebas\ConProgramacionDePrueba;
use IndieCinema\Funciones\Servicios\HistorialDePrueba;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Las ventas, la asistencia y las calificaciones del historial de prueba (EF-09.4): el modelo da
 * los números que esperan los reportes, y la carga los deja en la base contra un programación de
 * mentira con las funciones del historial (Dobles/programacion.php), sin duplicar nada.
 */
final class HistorialDePruebaTest extends TestCase
{
    use ConBaseDePruebas;
    use ConProgramacionDePrueba;

    private const MIERCOLES = '00000005-0000-4000-8000-000000000001';
    private const SABADO = '00000005-0000-4000-8000-000000000002';
    private const METROPOLIS = '00000002-0000-4000-8000-000000000001';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    private static function funcion(string $fecha, int $cupo = 80, array $obras = ['00000002-0000-4000-8000-000000000008']): FuncionDeProgramacion
    {
        return new FuncionDeProgramacion('id', 'finalizada', new DateTimeImmutable($fecha), 2500.0, $cupo, [], true, 'sala', 'Sala', 'Luján', true, 'organizador', $obras);
    }

    /**
     * @param list<array{cantidad: int, asistio: bool, puntaje: ?int}> $reservas
     *
     * @return array{int, int, list<int>} lugares vendidos, los que fueron y los puntajes
     */
    private static function sumar(array $reservas): array
    {
        return [
            array_sum(array_column($reservas, 'cantidad')),
            array_sum(array_map(static fn (array $reserva): int => $reserva['asistio'] ? $reserva['cantidad'] : 0, $reservas)),
            array_values(array_filter(array_column($reservas, 'puntaje'), static fn (?int $puntaje): bool => $puntaje !== null)),
        ];
    }

    public function testLosMiercolesVanPocosYLosSabadosCasiTodos(): void
    {
        // 22 % de 80 y el 80 % de ésos; 93 % de 80 y el 95 %.
        [$vendidas, $fueron] = self::sumar(HistorialDePrueba::reservas(self::funcion('2026-11-11 20:30')));
        self::assertSame([17, 13], [$vendidas, $fueron]);
        [$vendidas, $fueron] = self::sumar(HistorialDePrueba::reservas(self::funcion('2026-11-07 21:00')));
        self::assertSame([74, 70], [$vendidas, $fueron]);
    }

    public function testMetropolisSeLlenaYGustaMas(): void
    {
        [$vendidas, $fueron, $puntajes] = self::sumar(HistorialDePrueba::reservas(self::funcion('2026-11-07 21:00', obras: [self::METROPOLIS])));
        [, , $otros] = self::sumar(HistorialDePrueba::reservas(self::funcion('2026-11-07 21:00')));

        self::assertSame([78, 74], [$vendidas, $fueron]);
        self::assertGreaterThanOrEqual(4.5, array_sum($puntajes) / count($puntajes));
        self::assertLessThan(4.0, array_sum($otros) / count($otros));
    }

    public function testLasReservasSonDeAUnaODosYSoloCalificanLosQueFueron(): void
    {
        $reservas = HistorialDePrueba::reservas(self::funcion('2026-11-13 21:00', 120));

        foreach ($reservas as $reserva) {
            self::assertContains($reserva['cantidad'], [1, 2]);
            if (!$reserva['asistio']) {
                self::assertNull($reserva['puntaje']);
            }
        }
    }

    public function testCargaLasFinalizadasDelHistorialSinDuplicar(): void
    {
        $historial = HistorialDePrueba::para($this->configuracion, $this->base, self::funcionesDeProgramacion());

        // La tercera sigue programada: no tiene ventas que inventar.
        self::assertSame(['agregadas' => 2, 'yaEstaban' => 0, 'reservas' => 6 + 28, 'calificaciones' => 3 + 17], $historial->cargar());
        self::assertSame(['agregadas' => 0, 'yaEstaban' => 2, 'reservas' => 0, 'calificaciones' => 0], $historial->cargar());

        // El miércoles, en el doble con 40 lugares: 8 vendidos, 6 fueron, pagados por Mercado Pago.
        $miercoles = $this->base->fila(
            "SELECT SUM(cantidad) AS vendidas, SUM(IF(estado = 'asistio', cantidad, 0)) AS fueron, COUNT(pago_id) AS pagadas, COUNT(*) AS reservas
             FROM reserva WHERE funcion_id = ?",
            [self::MIERCOLES],
        );
        self::assertEquals(['vendidas' => 8, 'fueron' => 6, 'pagadas' => 6, 'reservas' => 6], $miercoles);
        self::assertSame(1, (int) $this->base->fila("SELECT COUNT(*) AS n FROM reserva WHERE funcion_id = ? AND estado = 'ausente'", [self::MIERCOLES])['n']);
        // Cada calificación, con la obra de la función y de alguien que fue.
        self::assertSame(3, (int) $this->base->fila(
            "SELECT COUNT(*) AS n FROM calificacion c JOIN calificacion_obra co ON co.calificacion_id = c.id JOIN reserva r ON r.id = c.reserva_id
             WHERE c.funcion_id = ? AND r.estado = 'asistio' AND co.obra_id = '0b0b0b0b-0b0b-40b0-80b0-0b0b0b0b0b0b'",
            [self::MIERCOLES],
        )['n']);
        // Los compradores son de la serie de los votantes de programación, desde el 10.
        self::assertSame(HistorialDePrueba::idDeEspectador(10), $this->base->fila('SELECT usuario_id FROM reserva WHERE funcion_id = ? ORDER BY usuario_id LIMIT 1', [self::SABADO])['usuario_id']);
    }

    public function testEnProduccionPideLaConfirmacion(): void
    {
        $produccion = new Configuracion(['app' => ['entorno' => 'produccion'], 'base' => self::baseDePruebas()]);

        HistorialDePrueba::para($produccion, $this->base, self::funcionesDeProgramacion(), true);
        $this->expectException(RuntimeException::class);
        HistorialDePrueba::para($produccion, $this->base, self::funcionesDeProgramacion());
    }
}
