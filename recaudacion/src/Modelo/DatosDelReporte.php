<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Todo lo que cruza un reporte: las funciones finalizadas del período, de programación, con lo que
 * sabe funciones de cada una. Si un subsistema no respondió, lo suyo viene en null y hay un aviso:
 * el reporte sale con lo que hay, no con un error (EF-09).
 */
final readonly class DatosDelReporte
{
    /**
     * @param list<FuncionFinalizada>                     $funciones
     * @param ?array<string, VentasDeFuncion>             $ventas         por id de función; null si funciones no respondió
     * @param ?array<string, CalificacionDeFuncion>       $calificaciones por id; las que no tienen no están; null si no respondió
     * @param list<string>                                $avisos         lo que faltó, para mostrarlo
     */
    public function __construct(
        public Periodo $periodo,
        public array $funciones,
        public ?array $ventas,
        public ?array $calificaciones,
        public array $avisos = [],
    ) {
    }

    public function ventasDe(string $funcionId): ?VentasDeFuncion
    {
        return $this->ventas[$funcionId] ?? null;
    }

    public function calificacionDe(string $funcionId): ?CalificacionDeFuncion
    {
        return $this->calificaciones[$funcionId] ?? null;
    }
}
