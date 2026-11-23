<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Moderacion\Modelo\SolicitudDeSala as Solicitud;
use IndieCinema\Moderacion\Servicios\NoSePuedeCompletar;
use IndieCinema\Moderacion\Servicios\ServicioDeSolicitudes;
use IndieCinema\Moderacion\Servicios\SolicitudPendiente;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * GET y POST /solicitar-sala: la solicitud para abrir una sala, como el wireframe de la E2
 * (solicitud-de-sala). La manda el espectador que quiere ser organizador y el organizador que
 * suma otra sala: es el mismo trámite. Con una pendiente, o en revisión manual (EF-07.6), se ve
 * su estado en lugar del formulario; si la rechazaron, el motivo y el formulario con la sala, para corregirla y volver a
 * mandarla. Si se la devolvieron porque falta un documento (EF-22.10), el motivo, la fecha límite y
 * un formulario sólo con esos documentos: mandarlos completa la misma solicitud.
 *
 * Con ?sala={id} es el pedido de cambio de dirección de una sala publicada (EF-07.6): el mismo
 * formulario, cargado con los datos que tiene la sala en programación, con la foto opcional y los
 * PDF del lugar nuevo. Sólo para su organizador; para los demás, 404.
 *
 * Es de moderación desde EF-07.2, pero la dirección es la de siempre: nginx la trae sin el prefijo
 * /moderacion, así que acá las direcciones a sí misma van sin él.
 */
final class SolicitudDeSala extends Controlador
{
    private const URL = '/solicitar-sala';

    public function __construct(private readonly ServicioDeSolicitudes $servicio)
    {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $sala = self::salaDelCambio($peticion);
        if ($sala !== null) {
            $datos = $this->servicio->salaParaCambiar(self::usuario($peticion), $sala);

            return $this->pantallaDelCambio($sala, $datos->nombre, FormularioDeSolicitud::desdeDatos($datos));
        }
        $ultima = $this->servicio->ultimaDe(self::usuario($peticion));
        if ($ultima?->estado === EstadoSolicitud::Devuelta) {
            return $this->pantalla($ultima, null);
        }
        $formulario = $ultima?->estado === EstadoSolicitud::Rechazada ? FormularioDeSolicitud::desdeDatos($ultima->datos, $this->servicio->documentosDe($ultima)) : null;

        return $this->pantalla($ultima, $formulario, mensaje: $peticion->consulta('enviada') === '1' ? 'La vas a ver acá hasta que te respondan.' : null);
    }

    public function enviar(Peticion $peticion): Respuesta
    {
        $sala = self::salaDelCambio($peticion);
        if ($sala !== null) {
            return $this->enviarCambio($peticion, $sala);
        }
        $usuario = self::usuario($peticion);
        $ultima = $this->servicio->ultimaDe($usuario);
        if ($ultima?->estado === EstadoSolicitud::Devuelta) {
            return $this->completar($peticion, $ultima);
        }
        if ($ultima?->estaAbierta()) {
            // Dos pestañas, o reenviar el POST: se ve la que ya está en revisión.
            return $this->pantalla($ultima, null, estado: 409);
        }
        $corrigiendo = $ultima?->estado === EstadoSolicitud::Rechazada;
        $archivos = [];
        foreach (['imagen', ...array_column(FormularioDeDocumentos::CAMPOS, 0)] as $campo) {
            $archivos[$campo] = $peticion->archivo($campo);
        }
        $formulario = FormularioDeSolicitud::desdeCampos($peticion->cuerpo(), $archivos, imagenObligatoria: !$corrigiendo, yaMandados: $this->yaMandados($ultima));
        $datos = $formulario->datos();
        if ($datos === null) {
            // Sin mensaje general: la plantilla resume los errores de los campos (E4-10.5).
            return $this->pantalla($ultima, $formulario, estado: 422);
        }

        try {
            $this->servicio->solicitar($usuario, $datos, $formulario->sala->imagen, $formulario->documentos);
        } catch (SolicitudPendiente) {
            return $this->pantalla($this->servicio->ultimaDe($usuario), null, estado: 409);
        }

        // 303: recargar no la vuelve a mandar.
        return Respuesta::redireccion(self::URL . '?enviada=1');
    }

    /**
     * La devuelta (EF-22.10): sólo los documentos que le pidieron, todos obligatorios y con las
     * mismas reglas que en la solicitud. Pasada la fecha límite, 409, aunque la tarea todavía no la
     * haya cerrado.
     */
    private function completar(Peticion $peticion, Solicitud $devuelta): Respuesta
    {
        $archivos = [];
        foreach (array_column(FormularioDeDocumentos::CAMPOS, 0) as $campo) {
            $archivos[$campo] = $peticion->archivo($campo);
        }
        $formulario = FormularioDeDocumentos::desdeFaltantes($peticion->cuerpo(), $archivos, $devuelta->devolucion->faltantes ?? []);
        if ($formulario->errores !== []) {
            return $this->pantalla($devuelta, $formulario, estado: 422);
        }
        try {
            $this->servicio->completar(self::usuario($peticion), $formulario->documentos);
        } catch (NoSePuedeCompletar) {
            $ultima = $this->servicio->ultimaDe(self::usuario($peticion));

            return $this->pantalla($ultima, null, error: $ultima?->estado === EstadoSolicitud::Devuelta
                ? 'Venció el plazo para completarla: ya no se puede mandar la documentación.'
                : null, estado: 409);
        }

        return Respuesta::redireccion(self::URL . '?enviada=1');
    }

    /**
     * El pedido de cambio de dirección: los PDF del lugar nuevo son obligatorios, porque los que
     * tiene la sala son de otra dirección; la foto, no.
     */
    private function enviarCambio(Peticion $peticion, string $sala): Respuesta
    {
        $usuario = self::usuario($peticion);
        $actuales = $this->servicio->salaParaCambiar($usuario, $sala);
        $archivos = [];
        foreach (['imagen', ...array_column(FormularioDeDocumentos::CAMPOS, 0)] as $campo) {
            $archivos[$campo] = $peticion->archivo($campo);
        }
        $formulario = FormularioDeSolicitud::desdeCampos($peticion->cuerpo(), $archivos, imagenObligatoria: false);
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->pantallaDelCambio($sala, $actuales->nombre, $formulario, 422);
        }
        try {
            $this->servicio->pedirCambio($usuario, $sala, $datos, $formulario->sala->imagen, $formulario->documentos);
        } catch (SolicitudPendiente) {
            // Otro pedido de la misma sala llegó antes: se ve ése.
            return Respuesta::redireccion(self::URL);
        }

        return Respuesta::redireccion(self::URL . '?enviada=1');
    }

    private function pantallaDelCambio(string $sala, string $nombre, FormularioDeSolicitud $formulario, int $estado = 200): Respuesta
    {
        return $this->vista('solicitar_sala.html.twig', [
            'reglas' => FormularioDeSolicitud::reglas(imagenObligatoria: false),
            'valores' => $formulario->valores,
            'errores' => $formulario->errores,
            'corrigiendo' => false,
            'solicitud' => null,
            'cambio' => ['sala_id' => $sala, 'nombre' => $nombre],
        ], $estado);
    }

    /**
     * El id de ?sala=, si es un pedido de cambio de dirección. Con otra cosa que un texto, también
     * es un pedido: de una sala que no existe.
     */
    private static function salaDelCambio(Peticion $peticion): ?string
    {
        $sala = $peticion->consulta('sala');

        return $sala === null ? null : (is_string($sala) ? $sala : '');
    }

    private function pantalla(?Solicitud $ultima, FormularioDeSolicitud|FormularioDeDocumentos|null $formulario, ?string $error = null, ?string $mensaje = null, int $estado = 200): Respuesta
    {
        $corrigiendo = $ultima?->estado === EstadoSolicitud::Rechazada;
        $devolucion = $ultima?->devolucion;
        $datos = [
            'reglas' => FormularioDeSolicitud::reglas(imagenObligatoria: !$corrigiendo, yaMandados: $this->yaMandados($ultima)),
            'valores' => $formulario === null ? [] : $formulario->valores,
            'errores' => $formulario === null ? [] : $formulario->errores,
            'corrigiendo' => $corrigiendo,
            'cambio' => null,
            'solicitud' => $ultima === null ? null : [
                'estado' => $ultima->estado->value,
                'sala' => $ultima->datos->nombre,
                'imagen' => $this->foto($ultima),
                'enviada' => FechaParaMostrar::diaYHora($ultima->enviadaEn),
                'resuelta' => $ultima->resueltaEn === null ? null : FechaParaMostrar::diaYHora($ultima->resueltaEn),
                'motivo' => $ultima->motivo,
                // EF-07.6: por qué la pasaron a revisión manual, si pasó.
                'revision_motivo' => $ultima->revisionMotivo,
                // EF-22.10: mientras está devuelta, qué le pidieron y hasta cuándo tiene.
                'devolucion' => $ultima->estado !== EstadoSolicitud::Devuelta || $devolucion === null ? null : [
                    'motivo' => $devolucion->motivo,
                    'fecha' => FechaParaMostrar::diaYHora($devolucion->devueltaEn),
                    'vence' => $devolucion->vence->format('d/m/Y'),
                    'falta' => $devolucion->queFalta(),
                    // Los campos del formulario que tiene que mandar.
                    'campos' => array_map(static fn (TipoDocumento $tipo): string => FormularioDeDocumentos::CAMPOS[$tipo->value][0], $devolucion->faltantes),
                ],
                'cambio' => $ultima->cambio,
                'documentos' => array_map(fn (Documento $documento): array => [
                    'nombre' => $documento->tipo->nombre(),
                    // El campo del formulario, para mostrarlo junto al suyo al corregir.
                    'campo' => FormularioDeDocumentos::CAMPOS[$documento->tipo->value][0],
                    'vence' => $documento->venceEn->format('d/m/Y'),
                    'url' => $this->ruta("/documentos/{$documento->id}"),
                ], $this->servicio->documentosDe($ultima)),
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

    /**
     * Los documentos que mandó con la solicitud que le rechazaron: al corregirla, no los tiene que
     * volver a subir (EF-07.4).
     *
     * @return list<TipoDocumento>
     */
    private function yaMandados(?Solicitud $ultima): array
    {
        if ($ultima?->estado !== EstadoSolicitud::Rechazada) {
            return [];
        }

        return array_map(static fn (Documento $documento): TipoDocumento => $documento->tipo, $this->servicio->documentosDe($ultima));
    }

    /**
     * La foto de la solicitud, que entrega moderación; la de una que se mudó de programación sin
     * foto propia es la de su sala, que su organizador ve aunque no esté publicada.
     */
    private function foto(Solicitud $solicitud): string
    {
        return $solicitud->imagen === null
            ? "/salas/{$solicitud->salaId}/imagen"
            : $this->ruta("/solicitudes/{$solicitud->id}/foto");
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        // La ruta pide sesión: si llegó acá, hay usuario.
        return $peticion->usuario() ?? throw new LogicException('Ruta de espectador sin usuario.');
    }
}
