<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\EstadoDocumento;
use IndieCinema\Moderacion\Modelo\Suspension;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Moderacion\Servicios\ServicioDeDocumentacion;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * GET y POST /moderacion/salas/{id}/documentacion: la documentación de una sala para su
 * organizador (EF-07.5), enlazada desde «Mis salas». Por qué está suspendida (en una denuncia, sólo
 * el motivo del moderador: ni quién denunció ni el detalle), el seguro y la habilitación vigentes y
 * los pendientes, y el formulario para subir uno nuevo, que revisa la moderación.
 */
final class DocumentacionDeSala extends Controlador
{
    public function __construct(private readonly ServicioDeDocumentacion $servicio)
    {
    }

    /**
     * @throws ExcepcionHttp 404 si la sala no es suya; 503 si programación no responde
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $salaId = (string) $peticion->parametro('id');
        $sala = $this->servicio->salaDe($salaId, self::organizador($peticion));

        return $this->pantalla($salaId, $sala['nombre'], null, $peticion->consulta('subido') === '1');
    }

    /**
     * @throws ExcepcionHttp 404 si la sala no es suya; 503 si programación no responde
     */
    public function subir(Peticion $peticion): Respuesta
    {
        $salaId = (string) $peticion->parametro('id');
        $sala = $this->servicio->salaDe($salaId, self::organizador($peticion));
        $formulario = FormularioDeDocumentos::desdeRenovacion($peticion->cuerpo(), ['documento' => $peticion->archivo('documento')]);
        if ($formulario->errores !== [] || $formulario->documentos === []) {
            return $this->pantalla($salaId, $sala['nombre'], $formulario, estado: 422);
        }
        $this->servicio->subir($salaId, $formulario->documentos[0]);

        // 303: recargar no lo vuelve a subir.
        return $this->redirigir("/salas/{$salaId}/documentacion?subido=1");
    }

    private function pantalla(string $salaId, string $nombre, ?FormularioDeDocumentos $formulario, bool $subido = false, int $estado = 200): Respuesta
    {
        $documentos = $this->servicio->documentosDe($salaId);
        $vigentes = [];
        $pendientes = [];
        foreach ($documentos as $documento) {
            if ($documento->estado === EstadoDocumento::Aceptado && !isset($vigentes[$documento->tipo->value])) {
                // Vienen del que vence más lejos al que vence antes: el primero es el vigente.
                $vigentes[$documento->tipo->value] = $documento;
            }
            if ($documento->estado === EstadoDocumento::Pendiente && $documento->solicitudId === null) {
                $pendientes[] = $documento;
            }
        }
        $datos = [
            'sala' => ['id' => $salaId, 'nombre' => $nombre],
            'suspensiones' => array_map(static fn (Suspension $suspension): array => [
                'causa' => match ($suspension->causa) {
                    CausaSuspension::Retiro => 'Retirada por la moderación',
                    CausaSuspension::Vencimiento => 'Por la documentación',
                    CausaSuspension::Denuncia => 'Por una denuncia',
                },
                'motivo' => $suspension->motivo,
                'desde' => FechaParaMostrar::dia($suspension->desde),
            ], $this->servicio->suspensionesDe($salaId)),
            'vigentes' => array_map(fn (TipoDocumento $tipo): array => [
                'nombre' => $tipo->nombre(),
                'falta' => 'Todavía no tiene ' . $tipo->conArticulo() . ' aceptado.',
                'documento' => isset($vigentes[$tipo->value]) ? $this->documento($vigentes[$tipo->value]) : null,
            ], TipoDocumento::cases()),
            'pendientes' => array_map($this->documento(...), $pendientes),
            'reglas' => FormularioDeDocumentos::reglasDeRenovacion(),
            'valores' => $formulario === null ? [] : $formulario->valores,
            'errores' => $formulario === null ? [] : $formulario->errores,
        ];
        if ($subido) {
            $datos['mensaje_exito'] = 'Lo va a revisar la moderación. Te avisamos por correo cuando lo acepte o lo rechace.';
        }

        return $this->vista('documentacion_sala.html.twig', $datos, $estado);
    }

    /**
     * @return array{nombre: string, vence: string, vencido: bool, url: string}
     */
    private function documento(Documento $documento): array
    {
        return [
            'nombre' => $documento->tipo->nombre(),
            'vence' => $documento->venceEn->format('d/m/Y'),
            'vencido' => $documento->venceEn <= new DateTimeImmutable('today'),
            'url' => $this->ruta("/documentos/{$documento->id}"),
        ];
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de organizador: sin sesión no llega acá.');
    }
}
