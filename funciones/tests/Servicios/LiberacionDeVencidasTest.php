<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\LiberacionDeVencidas;
use IndieCinema\Funciones\Servicios\ServicioDePanelDeReservas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\TestCase;

/**
 * Las reservas que vencen sin pagar (X-01.5), contra un MySQL de verdad: la tarea las cancela con
 * su motivo, y antes de que pase, ya no cuentan para el cupo ni para los números del panel.
 */
final class LiberacionDeVencidasTest extends TestCase
{
    use ConBaseDePruebas;

    private const ESPECTADOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private BaseDeDatos $base;
    private RepositorioDeReservas $reservas;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->reservas = new RepositorioDeReservas($this->base);
    }

    private static function funcion(): FuncionDeProgramacion
    {
        return new FuncionDeProgramacion(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'programada', new DateTimeImmutable('2026-10-24 20:30:00'), 3500, 40,
            ['Zama'], true, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'Cine Club El Galpón', 'Luján', true, 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        );
    }

    /**
     * Un momento en la hora del sitio, sin depender de la zona de PHP: el contenedor la tiene en
     * php.ini, pero la CI corre en UTC, y la base guarda en UTC (12:15 acá son las 15:15 de allá).
     */
    private static function enArgentina(string $momento): DateTimeImmutable
    {
        return new DateTimeImmutable($momento, new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    /**
     * Una reserva hecha a esa hora: pendiente de pago, con 15 minutos para pagar, o confirmada.
     */
    private function reservada(string $hora, int $cantidad = 2, bool $pendiente = true): Reserva
    {
        $reserva = Reserva::nueva(self::funcion(), self::ESPECTADOR, $cantidad, self::enArgentina("2026-10-20 {$hora}"), $pendiente ? 15 : null);
        self::assertTrue($this->reservas->agregar($reserva));

        return $reserva;
    }

    private function estado(Reserva $reserva): ?string
    {
        return $this->reservas->buscar($reserva->id)?->estado->value;
    }

    public function testCancelaLasVencidasYDejaLasQueEstanATiempo(): void
    {
        $vencida = $this->reservada('12:00');
        $otraVencida = $this->reservada('12:05');
        $aTiempo = $this->reservada('12:30');
        $confirmada = $this->reservada('11:00', pendiente: false);

        $canceladas = (new LiberacionDeVencidas($this->reservas))->liberar(self::enArgentina('2026-10-20 12:40:00'));

        self::assertSame(2, $canceladas);
        self::assertSame('cancelada', $this->estado($vencida));
        self::assertSame('cancelada', $this->estado($otraVencida));
        self::assertSame('pendiente_pago', $this->estado($aTiempo));
        self::assertSame('confirmada', $this->estado($confirmada));
    }

    /**
     * El historial dice por qué, con el espectador como autor: es su reserva la que venció.
     */
    public function testElHistorialDiceQueVencio(): void
    {
        $vencida = $this->reservada('12:00');

        (new LiberacionDeVencidas($this->reservas))->liberar(self::enArgentina('2026-10-20 12:15:00'));

        self::assertSame(
            ['pendiente_pago', 'cancelada', self::ESPECTADOR, 'espectador', LiberacionDeVencidas::MOTIVO, '2026-10-20 15:15:00'],
            array_values($this->base->fila('SELECT estado_anterior, estado_nuevo, autor_id, autor_rol, motivo, hecho_en FROM cambio_reserva WHERE reserva_id = ?', [$vencida->id]) ?? []),
        );
    }

    /**
     * Correrla de más no hace nada: el cron la corre seguido.
     */
    public function testCorrerlaDeNuevoNoCambiaNada(): void
    {
        $this->reservada('12:00');
        $liberacion = new LiberacionDeVencidas($this->reservas);

        self::assertSame(1, $liberacion->liberar(self::enArgentina('2026-10-20 13:00:00')));
        self::assertSame(0, $liberacion->liberar(self::enArgentina('2026-10-20 13:05:00')));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM cambio_reserva'));
    }

    /**
     * Si el pago se acreditó justo antes, la reserva ya no está pendiente y no se toca.
     */
    public function testUnaQueSePagoMientrasTantoNoSeCancela(): void
    {
        $vencida = $this->reservada('12:00');
        self::assertCount(1, $this->reservas->vencidas(self::enArgentina('2026-10-20 12:20:00'), 10));
        $this->base->ejecutar("UPDATE reserva SET estado = 'confirmada', pago_id = '1324567890' WHERE id = ?", [$vencida->id]);

        self::assertSame([], $this->reservas->vencidas(self::enArgentina('2026-10-20 12:20:00'), 10));
        self::assertSame(0, (new LiberacionDeVencidas($this->reservas))->liberar(self::enArgentina('2026-10-20 12:20:00')));
        self::assertSame('confirmada', $this->estado($vencida));
    }

    public function testUnaVencidaYaNoOcupaLugaresAunqueTodaviaNoSeHayaCancelado(): void
    {
        $this->reservada('12:00', 3);
        $this->reservada('12:00', 4, pendiente: false);

        self::assertSame(7, $this->reservas->lugaresOcupados(self::funcion()->id, self::enArgentina('2026-10-20 12:14:59')));
        self::assertSame(4, $this->reservas->lugaresOcupados(self::funcion()->id, self::enArgentina('2026-10-20 12:15:00')));
        self::assertSame([self::funcion()->id => 4], $this->reservas->lugaresOcupadosDe([self::funcion()->id], self::enArgentina('2026-10-20 12:15:00')));
    }

    /**
     * El panel del organizador tampoco la cuenta entre las reservadas, ni la pendiente a tiempo
     * en lo recaudado, que cuenta sólo lo que de verdad se cobró (EF-06.5).
     */
    public function testElPanelNoCuentaLaVencidaNiSumaLoQueTodaviaNoSePago(): void
    {
        $this->reservada('12:00', 3);
        $this->reservada('12:20', 2);
        $pagada = $this->reservada('11:00', 4, pendiente: false);
        $this->base->ejecutar("UPDATE reserva SET pago_id = '1324567890' WHERE id = ?", [$pagada->id]);

        $resumen = ServicioDePanelDeReservas::resumen(self::funcion(), $this->reservas->deLaFuncion(self::funcion()->id), self::enArgentina('2026-10-20 12:25:00'));

        self::assertSame(6, $resumen['reservados']);
        self::assertSame(34, $resumen['libres']);
        // Los 14000 de la pagada: la vencida y la que está a tiempo todavía no pusieron nada.
        self::assertEquals(14000, $resumen['recaudado']);
        self::assertSame(2, $resumen['reservas']);
    }
}
