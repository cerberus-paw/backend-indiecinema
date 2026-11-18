<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Recaudacion\Modelo\CobrosDeFuncion;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\VentasDeFuncion;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Junta lo que necesita un reporte (EF-09): recaudación no guarda funciones, reservas ni
 * calificaciones, así que las pide a quien las tiene. Si alguno no responde, el reporte sale con
 * lo que hay y un aviso, no con un error 500: programación sola ya dice qué se votó.
 */
final class ServicioDeReportes
{
    public function __construct(
        private readonly FuncionesFinalizadas $finalizadas,
        private readonly CobrosDeFunciones $cobros,
        private readonly CalificacionesDeFunciones $calificaciones,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @param ?string $organizadorId las de sus salas, o null para todas (el administrador)
     */
    public function delOrganizador(Periodo $periodo, ?string $organizadorId): DatosDelReporte
    {
        return $this->datos($periodo, $organizadorId, null);
    }

    /**
     * Las funciones que proyectaron alguna obra del dueño o la tuvieron en su votación, en
     * cualquier sala (EF-09.3).
     */
    public function delDueno(Periodo $periodo, string $duenoId): DatosDelReporte
    {
        return $this->datos($periodo, null, $duenoId);
    }

    private function datos(Periodo $periodo, ?string $organizadorId, ?string $duenoId): DatosDelReporte
    {
        $avisos = [];
        try {
            $respuesta = $this->finalizadas->delPeriodo($periodo, $organizadorId, $duenoId);
        } catch (ExcepcionHttp|RuntimeException $error) {
            $this->log->warning('El reporte salió sin las funciones de programación: {error}', ['error' => $error->getMessage()]);

            return new DatosDelReporte($periodo, [], null, null, ['Programación no respondió: no se pueden mostrar las funciones del período. Probá de nuevo en un rato.']);
        }
        $funciones = $respuesta['funciones'];
        if (!$respuesta['completas']) {
            $avisos[] = 'El período tiene demasiadas funciones: se cuentan las primeras ' . count($funciones) . '. Elegí uno más corto para verlas todas.';
        }
        $ids = array_map(static fn (FuncionFinalizada $funcion): string => $funcion->id, $funciones);

        $ventas = null;
        $calificaciones = null;
        if ($ids !== []) {
            try {
                $ventas = array_map(static fn (CobrosDeFuncion $cobros): VentasDeFuncion => VentasDeFuncion::deLosCobros($cobros), $this->cobros->deVarias($ids));
                $calificaciones = $this->calificaciones->deVarias($ids);
            } catch (ExcepcionHttp|RuntimeException $error) {
                $this->log->warning('El reporte salió sin las ventas de funciones: {error}', ['error' => $error->getMessage()]);
                $ventas = null;
                $calificaciones = null;
                $avisos[] = 'Funciones no respondió: faltan las ventas, la asistencia y las calificaciones, y las sugerencias que salen de ellas. Lo que se votó sí está.';
            }
        } else {
            $ventas = [];
            $calificaciones = [];
        }

        return new DatosDelReporte($periodo, $funciones, $ventas, $calificaciones, $avisos);
    }
}
