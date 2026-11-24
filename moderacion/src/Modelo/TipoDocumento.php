<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * Los papeles que pide una sala (EF-07.4): el seguro y la habilitación del espacio, los dos
 * obligatorios (grilling de EF-07, Q5).
 */
enum TipoDocumento: string
{
    case Seguro = 'seguro';
    case Habilitacion = 'habilitacion';

    /** Como título: «Seguro», «Habilitación». */
    public function nombre(): string
    {
        return match ($this) {
            self::Seguro => 'Seguro',
            self::Habilitacion => 'Habilitación',
        };
    }

    /** Con su artículo, para armar los mensajes: «el seguro», «la habilitación». */
    public function conArticulo(): string
    {
        return match ($this) {
            self::Seguro => 'el seguro',
            self::Habilitacion => 'la habilitación',
        };
    }

    /** Para el error de aprobar con el papel vencido: «El seguro está vencido». */
    public function estaVencido(): string
    {
        return match ($this) {
            self::Seguro => 'El seguro está vencido',
            self::Habilitacion => 'La habilitación está vencida',
        };
    }

    /**
     * Los que marcó el moderador al devolver una solicitud (EF-22.10), de lo que mandó el
     * formulario: como RequisitoDeSala::tildados(), lo que no es un tipo no cuenta, y uno repetido
     * cuenta una vez.
     *
     * @return list<self> en el orden de los casos
     */
    public static function marcados(mixed $campo): array
    {
        if (!is_array($campo)) {
            return [];
        }
        $valores = array_filter($campo, is_string(...));

        return array_values(array_filter(self::cases(), static fn (self $tipo): bool => in_array($tipo->value, $valores, true)));
    }

    /** «del seguro», «de la habilitación». */
    public function deQue(): string
    {
        return match ($this) {
            self::Seguro => 'del seguro',
            self::Habilitacion => 'de la habilitación',
        };
    }
}
