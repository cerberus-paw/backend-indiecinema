<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\ReporteDeObra;

/**
 * Las obras de un dueño en las funciones finalizadas del período (EF-09.3). Programación ya manda
 * sólo las funciones en las que estuvo alguna obra suya; acá se separan las suyas de las de otros
 * dueños que compartieron la función.
 */
final class ObrasDelDueno
{
    /**
     * @return array<string, ReporteDeObra> por id, por título
     */
    public static function obras(DatosDelReporte $datos, string $duenoId): array
    {
        $obras = [];
        foreach ($datos->funciones as $funcion) {
            $total = $funcion->votos();
            foreach ($funcion->obras as $obra) {
                if ($obra->duenoId !== $duenoId) {
                    continue;
                }
                $reporte = $obras[$obra->id] ??= new ReporteDeObra($obra->id, $obra->titulo);
                if ($obra->elegida) {
                    $reporte->funciones[] = $funcion;
                    $reporte->proyecciones->sumar($funcion, $datos->ventasDe($funcion->id), $datos->calificacionDe($funcion->id));
                }
                // Sólo las que tuvieron votación: una función con fecha fija no es una votación ganada.
                if ($total > 0) {
                    $reporte->votaciones[] = ['funcion' => $funcion, 'votos' => $obra->votos, 'total' => $total, 'gano' => $obra->elegida];
                }
            }
        }
        uasort($obras, static fn (ReporteDeObra $a, ReporteDeObra $b): int => [$a->titulo, $a->obraId] <=> [$b->titulo, $b->obraId]);

        return $obras;
    }
}
