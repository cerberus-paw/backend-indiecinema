<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Lo que el organizador carga de su obra, ya validado. El alta y la edición reciben lo mismo,
 * así que va aparte de la obra, que además tiene id, dueño, afiche y dada_de_baja_en.
 */
final readonly class DatosDeObra
{
    /**
     * @param int                    $duracionMinutos en minutos
     * @param non-empty-list<string> $generosIds
     */
    public function __construct(
        public string $titulo,
        public string $sinopsis,
        public int $anio,
        public string $director,
        public int $duracionMinutos,
        public TipoObra $tipo,
        public OrigenCopia $origenCopia,
        public array $generosIds,
    ) {
    }
}
