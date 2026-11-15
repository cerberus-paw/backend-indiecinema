<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Moderacion\Servicios\ServicioDeRenovaciones;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * La pestaña «Documentos» de la bandeja de moderación, en /moderacion/documentos (EF-07.5): las
 * renovaciones del seguro o de la habilitación que suben los organizadores, de la más vieja a la
 * más nueva, cada una con su sala, su vencimiento, el PDF y el formulario para aceptarla o
 * rechazarla con un motivo, que le llega al organizador.
 */
final class BandejaDeDocumentos extends Controlador
{
    /** Como la columna documento.motivo. */
    private const MAXIMO_MOTIVO = 1000;
    private const RESOLUCIONES = ['aceptar', 'rechazar'];

    public function __construct(private readonly ServicioDeRenovaciones $servicio)
    {
    }

    /**
     * @throws ExcepcionHttp 503 si programación no responde
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $mensaje = null;
        foreach (['aceptado' => 'Aceptaste el documento: le avisamos al organizador.', 'rechazado' => 'Rechazaste el documento: le avisamos al organizador con el motivo.'] as $clave => $texto) {
            if (is_string($peticion->consulta($clave))) {
                $mensaje = $texto;
            }
        }

        return $this->bandeja(null, [], [], $mensaje);
    }

    /**
     * @throws ExcepcionHttp 404 si no es una renovación pendiente; 403 si es de su sala; 503 si
     *                       programación no responde
     */
    public function resolver(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $resolucion = $peticion->campo('resolucion');
        $motivo = $peticion->campo('motivo');
        $motivo = is_string($motivo) && mb_check_encoding($motivo, 'UTF-8') ? trim($motivo) : '';
        $errores = [];
        if (!in_array($resolucion, self::RESOLUCIONES, true)) {
            $errores['resolucion'] = 'Elegí si lo aceptás o lo rechazás.';
        }
        if ($motivo === '') {
            $errores['motivo'] = 'Escribí el motivo: se lo mandamos al organizador.';
        } elseif (mb_strlen($motivo) > self::MAXIMO_MOTIVO) {
            $errores['motivo'] = 'El motivo puede tener hasta ' . self::MAXIMO_MOTIVO . ' caracteres.';
        }
        if ($errores !== []) {
            return $this->bandeja($id, ['resolucion' => is_string($resolucion) ? $resolucion : '', 'motivo' => $motivo], $errores, estado: 422);
        }

        $aceptar = $resolucion === 'aceptar';
        $this->servicio->resolver($id, $aceptar, $motivo, self::moderador($peticion));

        // 303: recargar no la vuelve a resolver.
        return $this->redirigir('/documentos?' . http_build_query([$aceptar ? 'aceptado' : 'rechazado' => $id]));
    }

    /**
     * @param array<string, string> $valores
     * @param array<string, string> $errores
     */
    private function bandeja(?string $conErrores, array $valores, array $errores, ?string $mensaje = null, int $estado = 200): Respuesta
    {
        $hoy = new DateTimeImmutable('today');
        $datos = [
            'documentos' => array_map(fn (array $pendiente): array => [
                'id' => $pendiente['documento']->id,
                'nombre' => $pendiente['documento']->tipo->nombre(),
                'sala' => $pendiente['sala']['nombre'] ?? 'Una sala que ya no está en programación',
                'vence' => $pendiente['documento']->venceEn->format('d/m/Y'),
                'vencido' => $pendiente['documento']->venceEn <= $hoy,
                'subido' => FechaParaMostrar::diaYHora($pendiente['documento']->subidoEn),
                'url' => $this->ruta("/documentos/{$pendiente['documento']->id}"),
                // Los errores y lo que mandó, sólo en el que volvió con errores.
                'valores' => $pendiente['documento']->id === $conErrores ? $valores : [],
                'errores' => $pendiente['documento']->id === $conErrores ? $errores : [],
            ], $this->servicio->pendientes()),
            'maximo_motivo' => self::MAXIMO_MOTIVO,
        ];
        if ($mensaje !== null) {
            $datos['mensaje_exito'] = $mensaje;
        }

        return $this->vista('administracion_documentos.html.twig', $datos, $estado);
    }

    private static function moderador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de moderador: sin sesión no llega acá.');
    }
}
