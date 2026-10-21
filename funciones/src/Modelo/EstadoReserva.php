<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

/**
 * En qué parte de su ciclo de vida está una reserva: los mismos valores que el ENUM de la tabla.
 *
 * Sin pendiente de pago: la reserva se confirma sin cobrar, y el estado que espera el pago llega
 * con el extra de Mercado Pago.
 */
enum EstadoReserva: string
{
    case Confirmada = 'confirmada';
    case Cancelada = 'cancelada';
    case Asistio = 'asistio';

    /**
     * Si cuenta para el cupo: quien ya entró sigue ocupando su lugar.
     */
    public function ocupaLugares(): bool
    {
        return $this !== self::Cancelada;
    }
}
