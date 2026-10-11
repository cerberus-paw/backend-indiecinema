<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DomainException;

/**
 * Una operación del modelo que no respeta una regla del dominio: un cupo mayor a la capacidad de
 * la sala, una fecha que ya pasó o un cambio de estado que el ciclo de vida no permite. El mensaje
 * dice cuál, para mostrárselo al organizador.
 */
final class ReglaIncumplida extends DomainException
{
}
