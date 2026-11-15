<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * Los puntos del checklist con el que el moderador revisa una solicitud de sala (EF-07.9). Son
 * fijos: aprobar exige los cuatro, y cada resolución registra cuáles tildó. El valor es el nombre de
 * su columna en revision_requisitos y del campo del formulario.
 */
enum RequisitoDeSala: string
{
    case SeguroVigente = 'seguro_vigente';
    case Habilitacion = 'habilitacion';
    case Capacidad = 'capacidad';
    case Condiciones = 'condiciones';

    /** Como se lee en el checklist: «Seguro vigente». */
    public function nombre(): string
    {
        return match ($this) {
            self::SeguroVigente => 'Seguro vigente',
            self::Habilitacion => 'Habilitación del espacio',
            self::Capacidad => 'Capacidad declarada',
            self::Condiciones => 'Condiciones del espacio',
        };
    }

    /** Qué tiene que mirar el moderador para tildarlo. */
    public function aclaracion(): string
    {
        return match ($this) {
            self::SeguroVigente => 'La póliza es del espacio declarado y está al día.',
            self::Habilitacion => 'El certificado es de la dirección declarada.',
            self::Capacidad => 'La capacidad coincide con la que figura en la habilitación.',
            self::Condiciones => 'La foto y la descripción muestran un lugar apto para proyectar.',
        };
    }

    /**
     * Los que se tildaron, de lo que mandó el formulario: lo que no es un punto no cuenta, y uno
     * repetido cuenta una vez. Un campo que no es una lista cuenta como ninguno.
     *
     * @return list<self> en el orden del checklist
     */
    public static function tildados(mixed $campo): array
    {
        if (!is_array($campo)) {
            return [];
        }
        $valores = array_filter($campo, is_string(...));

        return array_values(array_filter(self::cases(), static fn (self $requisito): bool => in_array($requisito->value, $valores, true)));
    }

    /**
     * Si están los cuatro.
     *
     * @param list<self> $tildados
     */
    public static function completo(array $tildados): bool
    {
        return count(array_unique(array_map(static fn (self $requisito): string => $requisito->value, $tildados))) === count(self::cases());
    }
}
