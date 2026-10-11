<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use IndieCinema\Nucleo\Uuid;

/**
 * Una película o un cortometraje que se puede proyectar, y el usuario dueño de la copia.
 *
 * En la E2 es una sola clase con el tipo como enumeración: película y cortometraje no se
 * comportan distinto, así que no hace falta una subclase para cada uno.
 */
final readonly class Obra
{
    /** El de la película más antigua que se conserva (La escena del jardín de Roundhay). */
    private const PRIMER_ANIO = 1888;

    /**
     * @param int $duracionMinutos en minutos
     */
    public function __construct(
        public string $id,
        public string $duenoId,
        public string $titulo,
        public string $sinopsis,
        public int $anio,
        public string $director,
        public int $duracionMinutos,
        public TipoObra $tipo,
        public OrigenCopia $origenCopia,
    ) {
    }

    /**
     * @throws ReglaIncumplida sin título, con una duración que no es positiva o con un año que no
     *                         puede ser
     */
    public static function nueva(
        string $duenoId,
        string $titulo,
        string $sinopsis,
        int $anio,
        string $director,
        int $duracionMinutos,
        TipoObra $tipo,
        OrigenCopia $origenCopia,
    ): self {
        if (trim($titulo) === '') {
            throw new ReglaIncumplida('La obra necesita un título.');
        }
        if ($duracionMinutos <= 0) {
            throw new ReglaIncumplida('La duración de la obra tiene que ser de al menos un minuto.');
        }
        if ($anio < self::PRIMER_ANIO || $anio > (int) date('Y')) {
            throw new ReglaIncumplida('El año de la obra tiene que estar entre ' . self::PRIMER_ANIO . ' y el actual.');
        }

        return new self(Uuid::nuevo(), $duenoId, $titulo, $sinopsis, $anio, $director, $duracionMinutos, $tipo, $origenCopia);
    }
}
