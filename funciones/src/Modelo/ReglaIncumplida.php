<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DomainException;

/**
 * Una operación que no respeta una regla del dominio: reservar más lugares que los que quedan o
 * una función que ya empezó. El mensaje dice cuál, para mostrárselo al espectador.
 */
final class ReglaIncumplida extends DomainException
{
}
