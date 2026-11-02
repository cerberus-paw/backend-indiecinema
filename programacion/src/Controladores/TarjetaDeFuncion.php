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
     * Las tarjetas de una lista, cada una con su acuerdo de fecha si tiene (E3-13.F9) y con sus
     * lugares libres (E4-02).
     *
     * @param list<FuncionEnCartelera>                   $funciones
     * @param array<string, array<string, mixed>|null> $acuerdos  los de ServicioDeCartelera::acuerdosDe()
     * @param ?array<string, int>                      $ocupados  funcion_id => lugares ocupados, de
     *                                                            funciones; null si no respondió, y
     *                                                            entonces van sin ese dato
     *
     * @return list<array<string, mixed>>
     */
    public static function todas(array $funciones, array $acuerdos, ?array $ocupados = null): array
    {
        return array_map(
            static fn (FuncionEnCartelera $funcion): array => self::datos($funcion, $acuerdos[$funcion->id] ?? null, $ocupados[$funcion->id] ?? null),
            $funciones,
        );
    }

    /**
     * @param array<string, mixed>|null $acuerdo  el de funciones, si la función está en acuerdo de fecha
     * @param ?int                      $ocupados los lugares que ya tomaron las reservas, o null si no se saben
     *
     * @return array<string, mixed> id, titulo, estado, precio, sala, localidad, fecha, lugares_libres y
     *                              agotada; con el acuerdo abierto, también franjas_propuestas
     */
    public static function datos(FuncionEnCartelera $funcion, ?array $acuerdo = null, ?int $ocupados = null): array
    {
        $libres = self::lugaresLibres($funcion->estado, $funcion->cupo, $ocupados);

        return self::conAcuerdo([
            'id' => $funcion->id,
            // Una función con varias obras las proyecta todas: el título las nombra a todas.
            'titulo' => implode(' + ', array_map(static fn (ObraEnCartelera $obra): string => $obra->titulo, $funcion->obras)),
            'estado' => match ($funcion->estado) {
                EstadoFuncion::EnVotacionTitulos => 'EN_VOTACION_TITULOS',
                EstadoFuncion::EnAcuerdoFecha => 'EN_ACUERDO_FECHA',
                default => 'PROGRAMADA',
            },
            'precio' => self::precio($funcion->precioBase),
            'sala' => $funcion->sala,
            'localidad' => $funcion->localidad,
            'fecha' => $funcion->fechaHora !== null ? self::fecha($funcion->fechaHora) : null,
            'lugares_libres' => $libres,
            'agotada' => $libres === 0,
        ], $acuerdo);
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
     * Una función en acuerdo cuyo acuerdo cerró sin quórum ya no se vota: la tarjeta lo dice. Con
     * el acuerdo abierto, dice cuántas franjas hay, como el wireframe de la cartelera.
     *
     * @param array<string, mixed>      $datos
     * @param array<string, mixed>|null $acuerdo
     *
     * @return array<string, mixed>
     */
    private static function conAcuerdo(array $datos, ?array $acuerdo): array
    {
        if ($datos['estado'] !== 'EN_ACUERDO_FECHA' || $acuerdo === null) {
            return $datos;
        }
        if (($acuerdo['estado'] ?? null) === 'sin_minimo') {
            return ['estado' => 'ACUERDO_SIN_MINIMO'] + $datos;
        }
        $franjas = is_array($acuerdo['franjas'] ?? null) ? count($acuerdo['franjas']) : 0;

        return $datos + ['franjas_propuestas' => $franjas === 1 ? '1 franja propuesta' : "{$franjas} franjas propuestas"];
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
     * «Sáb 19», como la franja más votada en el wireframe del acuerdo.
     */
    public static function diaCorto(DateTimeImmutable $fecha): string
    {
        return sprintf('%s %d', self::DIAS[(int) $fecha->format('w')], (int) $fecha->format('j'));
    }

    /**
     * «17 sep 23:59», como la fecha límite del acuerdo en el wireframe.
     */
    public static function diaMesYHora(DateTimeImmutable $fecha): string
    {
        return sprintf('%d %s %s', (int) $fecha->format('j'), self::MESES[(int) $fecha->format('n') - 1], $fecha->format('H:i'));
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
