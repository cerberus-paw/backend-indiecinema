<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Programacion\Modelo\Sala;

/**
 * Lo que muestra parciales/tarjeta_sala del paquete front, que usan «Mis salas» y el listado
 * público. La plantilla recibe datos y no la entidad, así no depende de cómo está armada.
 */
final class TarjetaDeSala
{
    /**
     * @return array{id: string, nombre: string, localidad: string, capacidad: int, imagen: ?string, habilitada: bool}
     */
    public static function datos(Sala $sala): array
    {
        return [
            'id' => $sala->id,
            'nombre' => $sala->datos->nombre,
            'localidad' => $sala->datos->localidad,
            'capacidad' => $sala->datos->capacidad,
            'imagen' => $sala->imagen === null ? null : "/salas/{$sala->id}/imagen",
            'habilitada' => $sala->habilitada,
        ];
    }
}
