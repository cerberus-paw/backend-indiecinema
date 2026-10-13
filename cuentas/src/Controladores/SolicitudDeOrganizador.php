<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\EstadoSolicitud;
use IndieCinema\Cuentas\Modelo\Solicitud;
use IndieCinema\Cuentas\Servicios\ServicioDeSolicitudes;
use IndieCinema\Cuentas\Servicios\SolicitudPendiente;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * /cuenta/solicitud: un espectador pide ser organizador para abrir su sala. Sigue el wireframe
 * «solicitud-de-sala» de la E2, recortado a lo que pide la E3: los datos de la sala, el contacto,
 * la presentación y las declaraciones. La ruta pide sesión, el núcleo ya controló el token CSRF
 * del POST y nginx limita cuántos se mandan por minuto. Se guarda y le llega al equipo por
 * correo; mientras el administrador no la resuelve, la pantalla muestra que está en revisión en
 * lugar del formulario.
 */
final class SolicitudDeOrganizador extends Controlador
{
    private const PLANTILLA = 'solicitud.html.twig';

    public function __construct(private readonly ServicioDeSolicitudes $solicitudes)
    {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->formulario(self::usuario($peticion), null, $peticion->consulta('enviada') === '1');
    }

    public function enviar(Peticion $peticion): Respuesta
    {
        $usuario = self::usuario($peticion);
        // Ya lo es, o tiene un rol que lo incluye: no hay nada que pedir.
        if ($usuario->alcanza(Rol::Organizador)) {
            return $this->formulario($usuario, estado: 409);
        }

        $formulario = FormularioDeSolicitud::desdeCampos($peticion->cuerpo());
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->formulario($usuario, $formulario, estado: 422);
        }

        try {
            $this->solicitudes->enviar($datos, $usuario);
        } catch (SolicitudPendiente) {
            // Otra pestaña, o el mismo POST dos veces: la pantalla muestra la que ya está en revisión.
            return $this->formulario($usuario, estado: 409);
        }

        // 303 a la pantalla con el aviso: recargar no vuelve a mandar la solicitud.
        return $this->redirigir('/solicitud?enviada=1');
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');
    }

    /**
     * La plantilla arma los atributos de cada campo (required, minlength, maxlength y pattern) con
     * las mismas reglas que valida FormularioDeSolicitud.
     */
    private function formulario(Usuario $usuario, ?FormularioDeSolicitud $formulario = null, bool $enviada = false, int $estado = 200): Respuesta
    {
        $yaEsOrganizador = $usuario->alcanza(Rol::Organizador);
        $ultima = $yaEsOrganizador ? null : $this->solicitudes->ultimaDe($usuario);
        $datos = [
            'reglas' => FormularioDeSolicitud::REGLAS,
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
            'ya_es_organizador' => $yaEsOrganizador,
            'solicitud' => $ultima === null ? null : self::solicitud($ultima),
        ];
        // La plantilla muestra cada mensaje si la variable existe: sólo se pasan cuando hay algo
        // que decir.
        if ($formulario?->errores) {
            $datos['error_general'] = 'Revisá los campos marcados.';
        }
        if ($enviada && $ultima?->estado === EstadoSolicitud::Pendiente) {
            $datos['mensaje_exito'] = 'Recibimos tu solicitud. El equipo la revisa y te responde al correo de contacto que dejaste.';
        }

        return $this->vista(self::PLANTILLA, $datos, $estado);
    }

    /**
     * La última solicitud, como la muestra la pantalla: si está pendiente, en lugar del
     * formulario; si se resolvió, con el motivo.
     *
     * @return array{estado: string, sala: string, enviada: string, motivo: ?string}
     */
    private static function solicitud(Solicitud $solicitud): array
    {
        return [
            'estado' => $solicitud->estado->value,
            'sala' => $solicitud->datos->sala,
            'enviada' => FechaParaMostrar::dia($solicitud->enviadaEn),
            'motivo' => $solicitud->motivo,
        ];
    }
}
