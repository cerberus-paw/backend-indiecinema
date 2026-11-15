<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * Por qué está suspendida una sala (EF-07.5). Una sala puede tener varias a la vez, y vuelve a
 * publicarse cuando no queda ninguna (grilling de EF-07, Q2).
 */
enum CausaSuspension: string
{
    /** La retiró un moderador desde la bandeja de solicitudes. */
    case Retiro = 'retiro';
    /** Se le venció el seguro o la habilitación, o le faltan pasado el plazo. */
    case Vencimiento = 'vencimiento';
    /** La suspendió un moderador por una denuncia (EF-07.8). */
    case Denuncia = 'denuncia';
}
