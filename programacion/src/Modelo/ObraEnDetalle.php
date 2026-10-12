<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Una obra que se proyecta en la función, con su información completa para la ficha pública.
 */
final readonly class ObraEnDetalle
{
    /**
     * @param list<string> $generos los nombres de los géneros asociados
     */
    public function __construct(
        public string $id,
        public string $titulo,
        public string $sinopsis,
        public int $anio,
        public string $director,
        public int $duracionMinutos,
        public TipoObra $tipo,
        /** El nombre del archivo en el volumen, o null si no tiene. */
        public ?string $afiche,
        public array $generos,
    ) {
    }
}
