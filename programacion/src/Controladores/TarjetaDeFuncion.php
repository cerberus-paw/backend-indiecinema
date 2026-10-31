<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
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
     * Las tarjetas de una página, con los lugares libres de cada una.
     *
     * @param list<FuncionEnCartelera>  $funciones
     * @param ?array<string, int>       $ocupados  funcion_id => lugares ocupados, de funciones; null
     *                                             si no respondió, y entonces van sin ese dato
     *
     * @return list<array<string, mixed>>
     */
    public static function todas(array $funciones, ?array $ocupados): array
    {
        return array_map(static fn (FuncionEnCartelera $funcion): array => self::datos($funcion, $ocupados[$funcion->id] ?? null), $funciones);
    }

    /**
     * @param ?int $ocupados los lugares que ya tomaron las reservas, o null si no se saben
     *
     * @return array{id: string, titulo: string, estado: string, precio: string, sala: string, localidad: string, fecha: ?string, lugares_libres: ?int, agotada: bool}
     */
    public static function datos(FuncionEnCartelera $funcion, ?int $ocupados = null): array
    {
        $libres = self::lugaresLibres($funcion->estado, $funcion->cupo, $ocupados);

        return [
            'id' => $funcion->id,
            // Una función con varias obras las proyecta todas: el título las nombra a todas.
            'titulo' => implode(' + ', array_map(static fn (ObraEnCartelera $obra): string => $obra->titulo, $funcion->obras)),
            'estado' => $funcion->estado === EstadoFuncion::EnVotacionTitulos ? 'EN_VOTACION_TITULOS' : 'PROGRAMADA',
            'precio' => self::precio($funcion->precioBase),
            'sala' => $funcion->sala,
            'localidad' => $funcion->localidad,
            'fecha' => $funcion->fechaHora !== null ? self::fecha($funcion->fechaHora) : null,
            'lugares_libres' => $libres,
            'agotada' => $libres === 0,
        ];
    }

    /**
     * Los lugares que quedan, sólo en una función programada: en votación todavía no se reserva.
     * Nunca menos de cero: si el organizador bajó el cupo después de que reservaron, está agotada.
     *
     * @return ?int null si no se reserva o si funciones no dio el dato
     */
    public static function lugaresLibres(EstadoFuncion $estado, int $cupo, ?int $ocupados): ?int
    {
        return $estado === EstadoFuncion::Programada && $ocupados !== null ? max(0, $cupo - $ocupados) : null;
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
