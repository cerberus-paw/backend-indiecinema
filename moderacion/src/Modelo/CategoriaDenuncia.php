<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * De qué se trata una denuncia (EF-07.8). En «Otro», el detalle es obligatorio.
 */
enum CategoriaDenuncia: string
{
    case Ofensivo = 'ofensivo';
    case Derechos = 'derechos';
    case Falsa = 'falsa';
    case Seguridad = 'seguridad';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ofensivo => 'Contenido ofensivo o inapropiado',
            self::Derechos => 'Derechos de autor',
            self::Falsa => 'Información falsa o engañosa',
            self::Seguridad => 'Seguridad del lugar',
            self::Otro => 'Otro',
        };
    }
}
