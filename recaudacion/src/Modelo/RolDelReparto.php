<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * A quién le toca cada parte de lo cobrado de una función (EF-08.2). Los valores son los de la
 * columna reparto_parte.rol.
 */
enum RolDelReparto: string
{
    /** El organizador de la sala donde se dio la función. */
    case Sala = 'sala';

    /** El dueño de una de las obras que se proyectaron. */
    case Dueno = 'dueno';

    /** El sitio: no hay a quién transferirle, y se queda con el redondeo. */
    case Plataforma = 'plataforma';

    /** Cómo se nombra en la pantalla del organizador y en las liquidaciones (EF-08.3). */
    public function comoTexto(): string
    {
        return match ($this) {
            self::Sala => 'la sala',
            self::Dueno => 'el dueño de la obra',
            self::Plataforma => 'la plataforma',
        };
    }
}
