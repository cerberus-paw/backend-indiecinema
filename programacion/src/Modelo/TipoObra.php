<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

enum TipoObra: string
{
    case Pelicula = 'pelicula';
    case Cortometraje = 'cortometraje';
}
