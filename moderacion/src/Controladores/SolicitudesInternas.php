<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use DateTimeInterface;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSolicitudes;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;

/**
 * La API interna de solicitudes de sala para cuentas (EF-07.6), que muestra en «Mi cuenta» en qué
 * quedó la última que mandó el usuario. Sólo la llama cuentas, con su token: lo controla la ruta.
 */
final class SolicitudesInternas extends Controlador
{
    public function __construct(private readonly RepositorioDeSolicitudes $solicitudes)
    {
    }

    /**
     * GET /interno/solicitudes/ultima/{usuario}: el estado de la última, el nombre de la sala, el
     * motivo que ve quien la mandó (el de la revisión manual mientras está en revisión, el de la
     * resolución después; null en una pendiente) y la fecha de lo último que le pasó, en UTC. Una
     * devuelta (EF-22.10) trae el motivo y la fecha de la devolución, y en «vence» el último día
     * para completarla (AAAA-MM-DD); una vencida, el motivo y la fecha del cierre.
     *
     * @throws ExcepcionHttp 404 si nunca mandó una
     */
    public function ultima(Peticion $peticion): Respuesta
    {
        $usuario = (string) $peticion->parametro('usuario');
        $solicitud = Uuid::esValido($usuario) ? $this->solicitudes->ultimaDe($usuario) : null;
        if ($solicitud === null) {
            throw ExcepcionHttp::noEncontrada();
        }
        $devolucion = $solicitud->estado === EstadoSolicitud::Devuelta ? $solicitud->devolucion : null;
        if ($devolucion !== null) {
            return $this->json([
                'estado' => $solicitud->estado->value,
                'sala' => $solicitud->datos->nombre,
                'motivo' => $devolucion->motivo,
                'fecha' => $devolucion->devueltaEn->format(DateTimeInterface::ATOM),
                'vence' => $devolucion->vence->format('Y-m-d'),
            ]);
        }
        $enRevision = $solicitud->estado === EstadoSolicitud::EnRevisionManual;

        return $this->json([
            'estado' => $solicitud->estado->value,
            'sala' => $solicitud->datos->nombre,
            'motivo' => $enRevision ? $solicitud->revisionMotivo : $solicitud->motivo,
            'fecha' => ($enRevision ? $solicitud->revisionEn : $solicitud->resueltaEn ?? $solicitud->enviadaEn)?->format(DateTimeInterface::ATOM),
        ]);
    }
}
