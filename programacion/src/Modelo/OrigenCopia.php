<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Con qué derecho se proyecta la copia: el dueño la hizo, ya no tiene derechos de autor o el
 * dueño compró la licencia.
 */
enum OrigenCopia: string
{
    case ObraPropia = 'obra_propia';
    case DominioPublico = 'dominio_publico';
    case LicenciaAdquirida = 'licencia_adquirida';
}
