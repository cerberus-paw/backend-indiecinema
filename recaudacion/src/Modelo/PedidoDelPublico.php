<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Una obra que el público votó pero perdió y no se proyectó en el período: lo que un cine que
 * elige solo nunca se entera de que le pidieron.
 */
final readonly class PedidoDelPublico
{
    /**
     * @param int $votaciones las votaciones que perdió con buena parte de los votos
     * @param int $votos      los que sacó en ésas
     * @param int $porcentaje lo que son esos votos sobre todos los de esas votaciones
     */
    public function __construct(
        public string $obraId,
        public string $titulo,
        public int $votaciones,
        public int $votos,
        public int $porcentaje,
    ) {
    }
}
