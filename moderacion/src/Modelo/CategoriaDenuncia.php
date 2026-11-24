<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * El motivo de una denuncia (EF-07.8, EF-22.11), en el orden en que se ofrece. Cada uno da la
 * gravedad de la denuncia; los del lugar sólo se ofrecen en salas y funciones. En «Otro», el
 * detalle es obligatorio.
 *
 * Se llama categoría, como su columna, porque el motivo es el del moderador al cerrarla.
 */
enum CategoriaDenuncia: string
{
    case Seguridad = 'seguridad';
    case NoSeHizo = 'no_se_hizo';
    case Ofensivo = 'ofensivo';
    case Falsa = 'falsa';
    case Derechos = 'derechos';
    case CambioSinAviso = 'cambio_sin_aviso';
    case Instalaciones = 'instalaciones';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Seguridad => 'Seguridad del lugar',
            self::NoSeHizo => 'La función no se hizo',
            self::Ofensivo => 'Contenido ofensivo o inapropiado',
            self::Falsa => 'Información falsa o engañosa',
            self::Derechos => 'Derechos de autor',
            self::CambioSinAviso => 'Cambio de horario o de lugar sin aviso',
            self::Instalaciones => 'Instalaciones en mal estado (butacas, sonido, imagen)',
            self::Otro => 'Otro',
        };
    }

    public function gravedad(): GravedadDenuncia
    {
        return match ($this) {
            self::Seguridad, self::NoSeHizo, self::Ofensivo => GravedadDenuncia::Alta,
            self::Falsa, self::Derechos, self::CambioSinAviso => GravedadDenuncia::Media,
            self::Instalaciones, self::Otro => GravedadDenuncia::Baja,
        };
    }

    /**
     * Si habla de lo que pasa en una sala: sólo tiene sentido donde hay un lugar.
     */
    public function delLugar(): bool
    {
        return in_array($this, [self::Seguridad, self::NoSeHizo, self::CambioSinAviso, self::Instalaciones], true);
    }
}
