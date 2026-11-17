<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Servicios\AvisosDeReservas;
use IndieCinema\Funciones\Servicios\ServicioDeAcuerdos;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * POST /interno/acuerdos/{id}/avisar-cancelacion: programación canceló la función porque el
 * acuerdo de fecha venció sin que ninguna franja llegara al mínimo (EF-03.2), o porque la franja
 * ganadora se pisaba con otra función de la sala (EF-05.5, motivo «horario_ocupado»), y funciones
 * les avisa por correo a los que marcaron «Me sirve», con el motivo que corresponde. Sólo la llama
 * programación, con su token.
 *
 * Va aparte de AcuerdosInternos porque necesita el correo: así los demás pedidos de los acuerdos
 * no dependen de que esté configurado. Responde apenas sabe a quiénes, y los correos salen
 * después (Respuesta::alTerminar), como en ReservasInternas: programación espera 2 s. Pedirlo
 * otra vez no repite los correos (ServicioDeAcuerdos::comprometidosParaAvisarCancelacion()).
 */
final class CancelacionDeAcuerdos extends Controlador
{
    public function __construct(
        private readonly ServicioDeAcuerdos $servicio,
        private readonly AvisosDeReservas $avisos,
    ) {
    }

    /** Por qué se canceló: sin_minimo si no se dice, como antes de EF-05.5. */
    private const MOTIVOS = ['sin_minimo', 'horario_ocupado'];

    /**
     * @throws ExcepcionHttp 400 sin el título o con un motivo que no se conoce
     */
    public function avisar(Peticion $peticion): Respuesta
    {
        $titulo = $peticion->campo('titulo');
        if (!is_string($titulo) || trim($titulo) === '') {
            throw ExcepcionHttp::solicitudInvalida('Falta el título de la función, en «titulo».');
        }
        $motivo = $peticion->campo('motivo') ?? 'sin_minimo';
        if (!in_array($motivo, self::MOTIVOS, true)) {
            throw ExcepcionHttp::solicitudInvalida('El motivo, en «motivo», es sin_minimo u horario_ocupado.');
        }

        $acuerdoId = (string) $peticion->parametro('id');
        try {
            $comprometidos = $this->servicio->comprometidosParaAvisarCancelacion($acuerdoId, $motivo === 'horario_ocupado');
        } catch (ReglaIncumplida $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }
        $minimo = $this->servicio->acuerdoPorId($acuerdoId)->minimoAsistentes ?? 0;

        return $this->json(['avisados' => count($comprometidos)])
            ->alTerminar(fn (): int => $motivo === 'horario_ocupado'
                ? $this->avisos->acuerdoConHorarioOcupado($comprometidos, $titulo)
                : $this->avisos->acuerdoSinMinimo($comprometidos, $titulo, $minimo));
    }
}
