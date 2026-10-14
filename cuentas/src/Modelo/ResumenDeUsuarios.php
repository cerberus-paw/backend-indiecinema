<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

/**
 * Los números de las tarjetas de «Usuarios y roles», como el wireframe de la E2: sólo los que
 * sabe cuentas. Las funciones realizadas y la recaudación son de otros subsistemas, y no están.
 */
final readonly class ResumenDeUsuarios
{
    /**
     * @param int $usuarios      todas las cuentas, en cualquier estado
     * @param int $nuevos        las que se crearon este mes
     * @param int $organizadores los organizadores con la cuenta activa
     */
    public function __construct(
        public int $usuarios,
        public int $nuevos,
        public int $organizadores,
    ) {
    }
}
