<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Ciclo de vida de un pedido de título ausente en el catálogo (EF-11).
 * Empieza «abierto», pasa a «activo» al alcanzar el umbral de adhesiones y queda «tomado»
 * cuando un organizador lo vincula a una obra cargada en su sala.
 */
enum EstadoPedido: string
{
    case Abierto = 'abierto';
    case Activo = 'activo';
    case Tomado = 'tomado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierto => 'Abierto',
            self::Activo => 'Activo',
            self::Tomado => 'Tomado',
        };
    }
}
