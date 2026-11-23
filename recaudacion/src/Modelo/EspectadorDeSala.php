<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Un espectador en el ranking de una sala (EF-22.19): lo que hizo en sus funciones del período y
 * el puntaje que sale de eso.
 */
final readonly class EspectadorDeSala
{
    /** Ir es lo que llena la sala: vale más que participar votando o calificando. */
    public const PUNTOS_POR_IR = 3;
    public const PUNTOS_POR_VOTAR = 1;
    public const PUNTOS_POR_CALIFICAR = 1;

    /**
     * @param ?string $nombre    el de cuentas, o null si cuentas no respondió
     * @param int     $fue       a cuántas funciones fue
     * @param int     $voto      en cuántas votaciones de títulos votó
     * @param int     $califico  cuántas funciones calificó
     * @param int     $pagado    lo que pagó, en centavos
     */
    public function __construct(
        public string $usuarioId,
        public ?string $nombre,
        public int $fue,
        public int $voto,
        public int $califico,
        public int $pagado,
    ) {
    }

    public function puntaje(): int
    {
        return self::PUNTOS_POR_IR * $this->fue + self::PUNTOS_POR_VOTAR * $this->voto + self::PUNTOS_POR_CALIFICAR * $this->califico;
    }
}
