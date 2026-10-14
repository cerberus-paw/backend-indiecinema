<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\EstadoSolicitud;
use IndieCinema\Programacion\Modelo\SolicitudDeSala as Solicitud;
use IndieCinema\Programacion\Servicios\ServicioDeSolicitudes;
use IndieCinema\Programacion\Servicios\SolicitudPendiente;
use LogicException;

/**
 * GET y POST /solicitar-sala: la solicitud para abrir una sala, como el wireframe de la E2
 * (solicitud-de-sala). La manda el espectador que quiere ser organizador y el organizador que
 * suma otra sala: es el mismo trámite. Con una pendiente se ve su estado en lugar del
 * formulario; si la rechazaron, el motivo y el formulario con la sala, para corregirla y volver a
 * mandarla.
 */
final class SolicitudDeSala extends Controlador
{
    public function __construct(private readonly ServicioDeSolicitudes $servicio)
    {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $ultima = $this->servicio->ultimaDe(self::usuario($peticion));
        $formulario = $ultima?->estado === EstadoSolicitud::Rechazada ? FormularioDeSolicitud::desdeDatos($ultima->sala->datos) : null;

        return $this->pantalla($ultima, $formulario, mensaje: $peticion->consulta('enviada') === '1' ? 'La vas a ver acá hasta que te respondan.' : null);
    }

    public function enviar(Peticion $peticion): Respuesta
    {
        $usuario = self::usuario($peticion);
        $ultima = $this->servicio->ultimaDe($usuario);
        if ($ultima?->estaPendiente()) {
            // Dos pestañas, o reenviar el POST: se ve la que ya está en revisión.
            return $this->pantalla($ultima, null, estado: 409);
        }
        $corrigiendo = $ultima?->estado === EstadoSolicitud::Rechazada;
        $formulario = FormularioDeSolicitud::desdeCampos($peticion->cuerpo(), $peticion->archivo('imagen'), imagenObligatoria: !$corrigiendo);
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->pantalla($ultima, $formulario, 'Revisá los campos marcados.', estado: 422);
        }

        try {
            $this->servicio->solicitar($usuario, $datos, $formulario->sala->imagen);
        } catch (SolicitudPendiente) {
            return $this->pantalla($this->servicio->ultimaDe($usuario), null, estado: 409);
        }

        // 303: recargar no la vuelve a mandar.
        return $this->redirigir('/solicitar-sala?enviada=1');
    }

    private function pantalla(?Solicitud $ultima, ?FormularioDeSolicitud $formulario, ?string $error = null, ?string $mensaje = null, int $estado = 200): Respuesta
    {
        $corrigiendo = $ultima?->estado === EstadoSolicitud::Rechazada;
        $datos = [
            'reglas' => FormularioDeSolicitud::reglas(imagenObligatoria: !$corrigiendo),
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
            'corrigiendo' => $corrigiendo,
            'solicitud' => $ultima === null ? null : [
                'estado' => $ultima->estado->value,
                'sala' => $ultima->sala->datos->nombre,
                'imagen' => $ultima->sala->imagen === null ? null : "/salas/{$ultima->sala->id}/imagen",
                'enviada' => FechaParaMostrar::diaYHora($ultima->enviadaEn),
                'resuelta' => $ultima->resueltaEn === null ? null : FechaParaMostrar::diaYHora($ultima->resueltaEn),
                'motivo' => $ultima->motivo,
            ],
        ];
        // La plantilla muestra cada mensaje si la variable existe: sólo se pasan cuando hay algo
        // que decir.
        if ($error !== null) {
            $datos['error_general'] = $error;
        }
        if ($mensaje !== null) {
            $datos['mensaje_exito'] = $mensaje;
        }

        return $this->vista('solicitar_sala.html.twig', $datos, $estado);
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        // La ruta pide sesión: si llegó acá, hay usuario.
        return $peticion->usuario() ?? throw new LogicException('Ruta de espectador sin usuario.');
    }
}
