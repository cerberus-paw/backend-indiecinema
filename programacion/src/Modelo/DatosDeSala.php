<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Lo que el organizador carga de su sala, ya validado. El alta y la edición reciben lo mismo, así
 * que va aparte de la sala, que además tiene id, dueño, imagen y el flag de habilitada.
 */
final readonly class DatosDeSala
{
    /**
     * @param int $duracionFuncion      en minutos
     * @param int $tiempoEntreFunciones en minutos
     */
    public function __construct(
        public string $nombre,
        public string $descripcion,
        public string $direccion,
        public string $localidad,
        public int $capacidad,
        public int $peliculasPorFuncion,
        public int $duracionFuncion,
        public int $tiempoEntreFunciones,
    ) {
    }
}
