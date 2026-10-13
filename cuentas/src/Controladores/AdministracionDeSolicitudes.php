<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\Solicitud;
use IndieCinema\Cuentas\Servicios\ServicioDeSolicitudes;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use LogicException;

/**
 * GET /cuenta/administracion/solicitudes: la bandeja de solicitudes para ser organizador, como el
 * wireframe de la E2 (moderacion-solicitudes). A la izquierda, las pendientes; a la derecha, la
 * elegida (?id=, o la que lleva más tiempo esperando) con sus datos, quién la mandó y la
 * resolución. Aprobar o rechazar es un POST con el motivo, que se le muestra a quien la mandó;
 * aprobarla le da el rol de organizador. Sólo el administrador llega: lo controla el rol de la
 * ruta, y el núcleo ya controló el token CSRF de los POST.
 */
final class AdministracionDeSolicitudes extends Controlador
{
    /** Como la columna solicitud.motivo. */
    private const MAXIMO_MOTIVO = 1000;

    public function __construct(private readonly ServicioDeSolicitudes $servicio)
    {
    }

    /**
     * @throws ExcepcionHttp 404 si la solicitud de ?id= no existe
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $id = $peticion->consulta('id');

        return $this->bandeja(is_string($id) ? $id : null, mensaje: $this->mensaje($peticion));
    }

    /**
     * @throws ExcepcionHttp 404 si la solicitud no existe
     */
    public function aprobar(Peticion $peticion): Respuesta
    {
        return $this->resolver($peticion, aprobar: true);
    }

    /**
     * @throws ExcepcionHttp 404 si la solicitud no existe
     */
    public function rechazar(Peticion $peticion): Respuesta
    {
        return $this->resolver($peticion, aprobar: false);
    }

    private function resolver(Peticion $peticion, bool $aprobar): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $motivo = self::motivo($peticion->campo('motivo'));
        $error = match (true) {
            $motivo === '' => 'Escribí el motivo: se le muestra a quien la mandó.',
            FormularioDeSolicitud::largo($motivo) > self::MAXIMO_MOTIVO => 'El motivo puede tener hasta ' . self::MAXIMO_MOTIVO . ' caracteres.',
            default => null,
        };
        if ($error !== null) {
            return $this->bandeja($id, $motivo, $error, 422);
        }

        $administrador = $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de administrador: sin sesión no llega acá.');
        $resuelta = $aprobar
            ? $this->servicio->aprobar($id, $administrador, $motivo)
            : $this->servicio->rechazar($id, $administrador, $motivo);
        $aviso = match (true) {
            !$resuelta => 'ya_resuelta',
            $aprobar => 'aprobada',
            default => 'rechazada',
        };

        // 303 a la bandeja con el aviso: recargar no vuelve a mandar la resolución.
        return $this->redirigir('/administracion/solicitudes?' . http_build_query([$aviso => $id]));
    }

    /**
     * @param ?string $id la elegida; sin ella, la primera pendiente
     *
     * @throws ExcepcionHttp 404 si la elegida no existe
     */
    private function bandeja(?string $id, string $motivo = '', ?string $error = null, int $estado = 200, ?string $mensaje = null): Respuesta
    {
        $pendientes = $this->servicio->pendientes();
        $elegida = $id !== null ? $this->servicio->buscar($id) : ($pendientes[0] ?? null);

        $datos = [
            'pendientes' => array_map(static fn (Solicitud $solicitud): array => [
                'id' => $solicitud->id,
                'sala' => $solicitud->datos->sala,
                'localidad' => $solicitud->datos->localidad,
                'solicitante' => $solicitud->solicitante->nombre,
                'enviada' => FechaParaMostrar::dia($solicitud->enviadaEn),
                'elegida' => $solicitud->id === $elegida?->id,
            ], $pendientes),
            'total_pendientes' => $this->servicio->cuantasPendientes(),
            'elegida' => $elegida === null ? null : self::detalle($elegida),
            'motivo' => $motivo,
            'maximo_motivo' => self::MAXIMO_MOTIVO,
        ];
        // La plantilla muestra cada mensaje si la variable existe: sólo se pasan cuando hay algo
        // que decir.
        if ($error !== null) {
            $datos['error_motivo'] = $error;
        }
        if ($mensaje !== null) {
            $datos['mensaje_exito'] = $mensaje;
        }

        return $this->vista('administracion_solicitudes.html.twig', $datos, $estado);
    }

    /**
     * Lo que muestra la columna de la derecha, ya escrito como se lee.
     *
     * @return array<string, mixed>
     */
    private static function detalle(Solicitud $solicitud): array
    {
        return [
            'id' => $solicitud->id,
            'estado' => $solicitud->estado->value,
            'sala' => $solicitud->datos->sala,
            'localidad' => $solicitud->datos->localidad,
            'presentacion' => $solicitud->datos->presentacion,
            'correo' => $solicitud->datos->correo,
            'telefono' => $solicitud->datos->telefono,
            'enviada' => FechaParaMostrar::diaYHora($solicitud->enviadaEn),
            'motivo' => $solicitud->motivo,
            'resuelta' => $solicitud->resueltaEn === null ? null : FechaParaMostrar::diaYHora($solicitud->resueltaEn),
            'solicitante' => [
                'nombre' => $solicitud->solicitante->nombre,
                'correo' => $solicitud->solicitante->correo,
                'cuenta_creada' => FechaParaMostrar::dia($solicitud->solicitante->cuentaCreadaEn),
            ],
        ];
    }

    /**
     * El aviso de la resolución que se acaba de hacer, con el nombre de la sala, o null.
     */
    private function mensaje(Peticion $peticion): ?string
    {
        $avisos = [
            'aprobada' => 'Aprobaste la solicitud de «%s». Va a tener el rol de organizador la próxima vez que ingrese.',
            'rechazada' => 'Rechazaste la solicitud de «%s».',
            'ya_resuelta' => 'La solicitud de «%s» ya estaba resuelta: no se cambió nada.',
        ];
        foreach ($avisos as $clave => $texto) {
            $id = $peticion->consulta($clave);
            if (!is_string($id)) {
                continue;
            }
            try {
                return sprintf($texto, $this->servicio->buscar($id)->datos->sala);
            } catch (ExcepcionHttp) {
                return null;
            }
        }

        return null;
    }

    /**
     * Como la presentación de la solicitud: conserva los renglones y saca los demás caracteres de
     * control. Un campo que llega como arreglo cuenta como vacío.
     */
    private static function motivo(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }

        return trim((string) preg_replace('/(?!\n)\p{Cc}/u', '', str_replace(["\r\n", "\r"], "\n", $valor)));
    }
}
