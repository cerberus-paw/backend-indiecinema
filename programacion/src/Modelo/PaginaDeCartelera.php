<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Una página de la cartelera filtrada y cuántas funciones hay en total con esos filtros.
 *
 * La primera página existe siempre, aunque no haya ninguna función: es la que muestra que no hubo
 * resultados. Las que pasan de la última no existen.
 */
final readonly class PaginaDeCartelera
{
    /**
     * @param list<FuncionEnCartelera> $funciones
     * @param positive-int             $numero    desde 1
     * @param positive-int             $tamano    cuántas funciones entran en una página
     * @param int                      $total     las funciones que cumplen los filtros, en todas las páginas
     */
    public function __construct(
        public array $funciones,
        public int $numero,
        public int $tamano,
        public int $total,
    ) {
    }

    /**
     * @return positive-int
     */
    public function totalDePaginas(): int
    {
        return max(1, intdiv($this->total + $this->tamano - 1, $this->tamano));
    }

    public function existe(): bool
    {
        return $this->numero <= $this->totalDePaginas();
    }
}
