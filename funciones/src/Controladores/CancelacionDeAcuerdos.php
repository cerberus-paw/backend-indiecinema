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
 * acuerdo de fecha venció sin que ninguna franja llegara al mínimo (EF-03.2), y funciones les
 * avisa por correo a los que marcaron «Me sirve». Sólo la llama programación, con su token.
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

    /**
     * @throws ExcepcionHttp 400 sin el título
     */
    public function avisar(Peticion $peticion): Respuesta
    {
        $titulo = $peticion->campo('titulo');
        if (!is_string($titulo) || trim($titulo) === '') {
            throw ExcepcionHttp::solicitudInvalida('Falta el título de la función, en «titulo».');
        }

        $acuerdoId = (string) $peticion->parametro('id');
        try {
            $comprometidos = $this->servicio->comprometidosParaAvisarCancelacion($acuerdoId);
        } catch (ReglaIncumplida $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }
        $minimo = $this->servicio->acuerdoPorId($acuerdoId)->minimoAsistentes ?? 0;

        return $this->json(['avisados' => count($comprometidos)])
            ->alTerminar(fn (): int => $this->avisos->acuerdoSinMinimo($comprometidos, $titulo, $minimo));
    }
}
