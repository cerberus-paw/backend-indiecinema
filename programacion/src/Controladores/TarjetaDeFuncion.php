<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\ObraEnCartelera;

/**
 * Lo que muestra parciales/tarjeta_funcion del paquete front, con la fecha y el precio ya escritos
 * como se leen. La plantilla recibe datos y no la entidad, así no depende de cómo está armada.
 */
final class TarjetaDeFuncion
{
    /** La imagen no tiene intl, así que los nombres van acá. */
    private const DIAS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * @return array{id: string, titulo: string, estado: string, precio: string, sala: string, localidad: string, fecha: string}
     */
    public static function datos(FuncionEnCartelera $funcion): array
    {
        return [
            'id' => $funcion->id,
            // Una función con varias obras las proyecta todas: el título las nombra a todas.
            'titulo' => implode(' + ', array_map(static fn (ObraEnCartelera $obra): string => $obra->titulo, $funcion->obras)),
            'estado' => 'PROGRAMADA',
            'precio' => self::precio($funcion->precioBase),
            'sala' => $funcion->sala,
            'localidad' => $funcion->localidad,
            'fecha' => self::fecha($funcion->fechaHora),
        ];
    }

    /**
     * «Sáb 19 sep · 20:30»
     */
    public static function fecha(DateTimeImmutable $fecha): string
    {
        return sprintf(
            '%s %d %s · %s',
            self::DIAS[(int) $fecha->format('w')],
            (int) $fecha->format('j'),
            self::MESES[(int) $fecha->format('n') - 1],
            $fecha->format('H:i'),
        );
    }

    /**
     * «$ 3.500», con los centavos sólo si tiene; 0 es «a la gorra».
     */
    public static function precio(float $precio): string
    {
        if ($precio == 0) {
            return 'A la gorra';
        }
        $decimales = fmod($precio, 1.0) == 0 ? 0 : 2;

        return '$ ' . number_format($precio, $decimales, ',', '.');
    }
}
