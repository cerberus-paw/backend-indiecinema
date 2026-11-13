<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Recaudacion\Modelo\CalificacionDeFuncion;
use RuntimeException;

/**
 * El promedio de las calificaciones de cada función, pedido a funciones por la API interna
 * (GET /interno/calificaciones/resumen?funciones=…), de a cien como acepta.
 */
final class CalificacionesDeFunciones
{
    /** Lo más que acepta funciones por tipo (CalificacionesInternas::MAXIMO_POR_TIPO). */
    public const MAXIMO_POR_PEDIDO = 100;

    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * @param list<string> $funcionesIds
     *
     * @return array<string, CalificacionDeFuncion> por id; las que nadie calificó no vienen
     *
     * @throws ExcepcionHttp    503 si funciones no responde a tiempo
     * @throws RuntimeException si responde algo que no es el resumen
     */
    public function deVarias(array $funcionesIds): array
    {
        $calificaciones = [];
        foreach (array_chunk(array_values(array_unique($funcionesIds)), self::MAXIMO_POR_PEDIDO) as $tanda) {
            $datos = $this->cliente->get('funciones', '/interno/calificaciones/resumen', ['funciones' => implode(',', $tanda)]);
            $porFuncion = is_array($datos) ? ($datos['funciones'] ?? null) : null;
            if (!is_array($porFuncion)) {
                throw new RuntimeException('funciones no respondió el resumen de las calificaciones.');
            }
            foreach ($porFuncion as $id => $resumen) {
                if (!is_array($resumen) || !(is_float($resumen['promedio'] ?? null) || is_int($resumen['promedio'] ?? null)) || !is_int($resumen['cantidad'] ?? null)) {
                    throw new RuntimeException("funciones respondió mal el resumen de las calificaciones de la función {$id}.");
                }
                $calificaciones[(string) $id] = new CalificacionDeFuncion((float) $resumen['promedio'], $resumen['cantidad']);
            }
        }

        return $calificaciones;
    }
}
