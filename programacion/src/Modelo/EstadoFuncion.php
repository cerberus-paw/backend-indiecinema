<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * El ciclo de vida de una función (E2, modelo de objetos de programación).
 *
 * Nace en votación de títulos; al cerrarla pasa al acuerdo de fecha con los espectadores, y con
 * la fecha fijada queda programada hasta que se proyecta. Se puede cancelar en cualquier momento
 * antes de terminar. Cancelada y finalizada no salen a ningún lado.
 */
enum EstadoFuncion: string
{
    case EnVotacionTitulos = 'en_votacion_titulos';
    case EnAcuerdoFecha = 'en_acuerdo_fecha';
    case Programada = 'programada';
    case Cancelada = 'cancelada';
    case Finalizada = 'finalizada';

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
            self::EnVotacionTitulos => 'en votación de títulos',
            self::EnAcuerdoFecha => 'en acuerdo de fecha',
            self::Programada => 'programada',
            self::Cancelada => 'cancelada',
            self::Finalizada => 'finalizada',
        };
    }

    public function esFinal(): bool
    {
        return $this->siguientes() === [];
    }

    /**
     * @return list<self>
     */
    private function siguientes(): array
    {
        return match ($this) {
            self::EnVotacionTitulos => [self::EnAcuerdoFecha, self::Cancelada],
            self::EnAcuerdoFecha => [self::Programada, self::Cancelada],
            self::Programada => [self::Finalizada, self::Cancelada],
            self::Cancelada, self::Finalizada => [],
        };
    }
}
