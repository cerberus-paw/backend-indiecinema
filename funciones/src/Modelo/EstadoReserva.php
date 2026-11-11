<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

/**
 * El ciclo de vida de una reserva: los mismos valores que el ENUM de la tabla.
 *
 * La de una función paga nace pendiente de pago (X-01): ocupa sus lugares mientras el espectador
 * paga, y se confirma sólo con el aviso de Mercado Pago, o se cancela si vence o si él la cancela.
 * La de una función a la gorra, o con el cobro apagado, nace confirmada.
 *
 * Confirmada, el espectador la puede cancelar, y en la puerta el organizador registra la
 * asistencia. Cancelada y asistió no salen a ningún lado: una cancelada ya soltó sus lugares y
 * quizás los tomó otro, así que se reserva de nuevo; y quien entró ya usó la entrada.
 *
 * Al finalizar la función (EF-04.1), la confirmada que no tiene la asistencia queda ausente. De
 * ahí sólo pasa a asistió, si el organizador la registra tarde: la tarea que finaliza corre cada
 * quince minutos y el que estaba en la puerta puede no haber llegado a anotarla.
 */
enum EstadoReserva: string
{
    case PendientePago = 'pendiente_pago';
    case Confirmada = 'confirmada';
    case Cancelada = 'cancelada';
    case Asistio = 'asistio';
    case Ausente = 'ausente';

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
            self::PendientePago => 'pendiente de pago',
            self::Confirmada => 'confirmada',
            self::Cancelada => 'cancelada',
            self::Asistio => 'con asistencia',
            self::Ausente => 'no asistió',
        };
    }

    public function esFinal(): bool
    {
        return $this->siguientes() === [];
    }

    /**
     * Si cuenta para el cupo: quien ya entró sigue ocupando su lugar, y quien está pagando
     * también, así nadie le gana el lugar a mitad del pago. La de quien no fue también: la función
     * ya terminó, y lo que pagó sigue siendo parte de lo recaudado.
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
            self::PendientePago => [self::Confirmada, self::Cancelada],
            self::Confirmada => [self::Cancelada, self::Asistio, self::Ausente],
            self::Ausente => [self::Asistio],
            self::Cancelada, self::Asistio => [],
        };
    }
}
