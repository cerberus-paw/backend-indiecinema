<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Nucleo\Configuracion;

/**
 * Hasta cuándo el espectador puede cancelar su reserva (EF-22.9): hasta horas_minimas_cancelacion
 * horas antes de la función, de [reservas] en config.ini (24 si no está). El profe lo pidió el
 * 18/11 como en cualquier sistema de turnos: la sala tiene costos que no puede perder media hora
 * antes, y el límite mantiene el compromiso de la gente. Un número fijo para empezar.
 *
 * Sólo frena a la confirmada. La pendiente de pago se cancela hasta que empieza la función: no
 * hay plata de por medio, vence sola, y soltar sus lugares antes es mejor para la sala.
 */
final class PoliticaDeCancelacion
{
    public const HORAS_MINIMAS = 24;

    public readonly int $horasMinimas;

    public function __construct(Configuracion $configuracion)
    {
        $horas = $configuracion->obtener('reservas.horas_minimas_cancelacion', self::HORAS_MINIMAS);
        // Un valor que no es un entero de 0 en adelante no puede dejar sin política: va el de siempre.
        $this->horasMinimas = is_int($horas) && $horas >= 0 ? $horas : self::HORAS_MINIMAS;
    }

    /**
     * Para las pruebas y los dobles, sin armar un config.ini.
     */
    public static function deHoras(int $horas): self
    {
        return new self(new Configuracion(['reservas' => ['horas_minimas_cancelacion' => $horas]]));
    }

    /**
     * El último momento en que se puede cancelar una confirmada de esta función; null si la
     * función no tiene fecha (en votación) o programación ya no la muestra.
     */
    public function limite(?FuncionDeProgramacion $funcion): ?DateTimeImmutable
    {
        return $funcion?->fechaHora?->modify("-{$this->horasMinimas} hours");
    }

    /**
     * Por qué el espectador ya no la puede cancelar, o null si todavía puede. Los mensajes del
     * ciclo de vida (ya cancelada, asistió, ausente) los da ServicioDeReservas::cancelar().
     */
    public function motivoParaNoCancelar(Reserva $reserva, ?FuncionDeProgramacion $funcion, DateTimeImmutable $ahora = new DateTimeImmutable()): ?string
    {
        if (!$reserva->estado->puedePasarA(EstadoReserva::Cancelada)) {
            return 'Esta reserva ya no se puede cancelar.';
        }
        // Si programación ya no muestra la función (su sala se dio de baja), se puede.
        if ($funcion?->fechaHora === null) {
            return null;
        }
        if ($funcion->fechaHora <= $ahora) {
            return 'La función ya empezó: la reserva ya no se puede cancelar.';
        }
        $limite = $this->limite($funcion);
        if ($reserva->estado === EstadoReserva::Confirmada && $limite !== null && $ahora >= $limite) {
            return "Las reservas se cancelan hasta {$this->horasEscritas()} antes de la función, y ya falta menos: la sala cuenta con esos lugares.";
        }

        return null;
    }

    /**
     * La política, para mostrarla donde se reserva.
     */
    public function texto(): string
    {
        return $this->horasMinimas === 0
            ? 'Podés cancelar tu reserva hasta que empiece la función.'
            : "Podés cancelar tu reserva hasta {$this->horasEscritas()} antes de la función; después, ya no.";
    }

    private function horasEscritas(): string
    {
        return $this->horasMinimas === 1 ? '1 hora' : "{$this->horasMinimas} horas";
    }
}
