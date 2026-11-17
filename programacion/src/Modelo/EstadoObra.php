<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * En qué quedó la revisión de una obra (EF-07.7): la que carga un organizador queda pendiente
 * hasta que la moderación la aprueba o la rechaza. Sólo las aprobadas se ven en el sitio, son
 * candidatas o se programan.
 */
enum EstadoObra: string
{
    case Pendiente = 'pendiente';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
}
