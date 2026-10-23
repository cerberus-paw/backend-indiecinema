<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Servicios\ServicioDeAcuerdos;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use InvalidArgumentException;

/**
 * Endpoints internos para la gestión de acuerdos de fecha con los espectadores.
 * Atiende las llamadas autenticadas de programación por la API interna.
 */
final class AcuerdosInternos extends Controlador
{
    public function __construct(private readonly ServicioDeAcuerdos $servicio)
    {
    }

    /**
     * Da de alta un acuerdo de fecha con franjas propuestas (POST /interno/acuerdos).
     */
    public function proponer(Peticion $peticion): Respuesta
    {
        $cuerpo = $peticion->cuerpo();
        $funcionId = (string) ($cuerpo['funcion_id'] ?? '');
        $minimoAsistentes = (int) ($cuerpo['minimo_asistentes'] ?? 0);
        /** @var list<array{fecha_hora_inicio: string, fecha_hora_fin?: string|null}> $franjas */
        $franjas = is_array($cuerpo['franjas'] ?? null) ? $cuerpo['franjas'] : [];

        try {
            $acuerdo = $this->servicio->proponerAcuerdo($funcionId, $minimoAsistentes, $franjas);
        } catch (InvalidArgumentException $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }

        return $this->json($acuerdo->paraJson(), 201);
    }

    /**
     * Consulta el acuerdo de una función (GET /interno/acuerdos/por-funcion/{funcion_id}).
     */
    public function mostrarPorFuncion(Peticion $peticion): Respuesta
    {
        $funcionId = (string) $peticion->parametro('id');
        $acuerdo = $this->servicio->acuerdoPorFuncion($funcionId);

        if ($acuerdo === null) {
            return $this->json(['error' => 'No hay acuerdo de fecha para esta función.'], 404);
        }

        $datos = $acuerdo->paraJson();

        $usuarioId = $peticion->consulta('usuario_id');
        if (is_string($usuarioId) && $usuarioId !== '') {
            $datos['voto_usuario_franja_id'] = $this->servicio->franjaVotadaPorUsuario($acuerdo->id, $usuarioId);
        } else {
            $datos['voto_usuario_franja_id'] = null;
        }

        return $this->json($datos);
    }

    /**
     * Emite o cambia el voto por una franja (POST /interno/acuerdos/{id}/votar).
     */
    public function votar(Peticion $peticion): Respuesta
    {
        $acuerdoId = (string) $peticion->parametro('id');
        $cuerpo = $peticion->cuerpo();
        $franjaId = (string) ($cuerpo['franja_id'] ?? '');
        $usuarioId = (string) ($cuerpo['usuario_id'] ?? '');

        if ($franjaId === '' || $usuarioId === '') {
            return $this->json(['error' => 'Faltan parámetros requeridos: franja_id y usuario_id.'], 422);
        }

        try {
            $this->servicio->votarFranja($acuerdoId, $franjaId, $usuarioId);
        } catch (ReglaIncumplida $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }

        return $this->json(['ok' => true, 'franja_id' => $franjaId]);
    }

    /**
     * Confirma la franja ganadora si alcanzó el mínimo (POST /interno/acuerdos/{id}/confirmar).
     */
    public function confirmar(Peticion $peticion): Respuesta
    {
        $acuerdoId = (string) $peticion->parametro('id');

        try {
            $acuerdo = $this->servicio->confirmarFranjaGanadora($acuerdoId);
        } catch (ReglaIncumplida $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }

        return $this->json($acuerdo->paraJson());
    }

    /**
     * Cierra el acuerdo sin alcanzar el mínimo (POST /interno/acuerdos/{id}/cerrar-sin-minimo).
     */
    public function cerrarSinMinimo(Peticion $peticion): Respuesta
    {
        $acuerdoId = (string) $peticion->parametro('id');

        try {
            $acuerdo = $this->servicio->cerrarSinMinimo($acuerdoId);
        } catch (ReglaIncumplida $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }

        return $this->json($acuerdo->paraJson());
    }
}
