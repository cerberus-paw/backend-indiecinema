<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

/**
 * El ciclo de vida de una reserva: los mismos valores que el ENUM de la tabla.
 *
 * Nace confirmada; el espectador la puede cancelar, y en la puerta el organizador registra la
 * asistencia. Cancelada y asistió no salen a ningún lado: una cancelada ya soltó sus lugares y
 * quizás los tomó otro, así que se reserva de nuevo; y quien entró ya usó la entrada.
 *
 * Sin pendiente de pago: la reserva se confirma sin cobrar, y el estado que espera el pago llega
 * con el extra de Mercado Pago.
 */
enum EstadoReserva: string
{
    case Confirmada = 'confirmada';
    case Cancelada = 'cancelada';
    case Asistio = 'asistio';

    public function puedePasarA(self $siguiente): bool
    {
        return in_array($siguiente, $this->siguientes(), true);
    }

    /**
     * Cómo se nombra en los mensajes y las pantallas.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Confirmada => 'confirmada',
            self::Cancelada => 'cancelada',
            self::Asistio => 'con asistencia',
        };
    }

    public function esFinal(): bool
    {
        return $this->siguientes() === [];
    }

    /**
     * Si cuenta para el cupo: quien ya entró sigue ocupando su lugar.
     */
    public function ocupaLugares(): bool
    {
        return $this !== self::Cancelada;
    }

    /**
     * @return list<self>
     */
    private function siguientes(): array
    {
        return match ($this) {
            self::Confirmada => [self::Cancelada, self::Asistio],
            self::Cancelada, self::Asistio => [],
        };
    }
}
