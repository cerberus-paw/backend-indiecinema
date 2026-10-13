<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

/**
 * Una página del listado de usuarios del administrador, como PaginaDeCartelera en programación.
 */
final readonly class PaginaDeUsuarios
{
    /**
     * @param list<UsuarioEnListado> $usuarios
     * @param positive-int           $numero   desde 1
     * @param positive-int           $tamano   cuántos usuarios entran en una página
     * @param int                    $total    los usuarios que coinciden con la búsqueda, en todas las páginas
     */
    public function __construct(
        public array $usuarios,
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

    /**
     * La primera existe siempre, aunque no haya usuarios: es la que dice que no hubo resultados.
     */
    public function existe(): bool
    {
        return $this->numero <= $this->totalDePaginas();
    }
}
