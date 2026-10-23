<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

/**
 * Los estados del acuerdo de fecha con los espectadores.
 *
 * Nace abierto para que voten las franjas propuestas; al cerrar, si la franja ganadora
 * alcanza el mínimo pasa a confirmado (y la función a programada); si no lo alcanza,
 * pasa a sin_minimo y el organizador decide qué hacer. Se puede cancelar en cualquier momento.
 */
enum EstadoAcuerdo: string
{
    case Abierto = 'abierto';
    case Confirmado = 'confirmado';
    case SinMinimo = 'sin_minimo';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierto => 'abierto',
            self::Confirmado => 'confirmado',
            self::SinMinimo => 'sin mínimo',
            self::Cancelado => 'cancelado',
        };
    }

    public function estaAbierto(): bool
    {
        return $this === self::Abierto;
    }
}
