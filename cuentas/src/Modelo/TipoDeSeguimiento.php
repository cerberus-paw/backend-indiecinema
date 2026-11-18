<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

/**
 * Lo que un espectador puede seguir (EF-15.4).
 */
enum TipoDeSeguimiento: string
{
    case Sala = 'sala';
    case Organizador = 'organizador';
}
