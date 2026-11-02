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

        // Las franjas que marcó quien mira la ficha: pueden ser varias (E3-13.F7).
        $usuarioId = $peticion->consulta('usuario_id');
        $datos['franjas_del_usuario'] = is_string($usuarioId) && $usuarioId !== ''
            ? $this->servicio->franjasMarcadasPor($acuerdo->id, $usuarioId)
            : [];

        return $this->json($datos);
    }

    /**
     * Marca «Me sirve» en una franja (POST /interno/acuerdos/{id}/votar).
     */
    public function votar(Peticion $peticion): Respuesta
    {
        return $this->cambiarMarca($peticion, true);
    }

    /**
     * Quita el «Me sirve» de una franja (POST /interno/acuerdos/{id}/quitar-voto, E3-13.F7).
     */
    public function quitarVoto(Peticion $peticion): Respuesta
    {
        return $this->cambiarMarca($peticion, false);
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

    private function cambiarMarca(Peticion $peticion, bool $marcar): Respuesta
    {
        $acuerdoId = (string) $peticion->parametro('id');
        $cuerpo = $peticion->cuerpo();
        $franjaId = (string) ($cuerpo['franja_id'] ?? '');
        $usuarioId = (string) ($cuerpo['usuario_id'] ?? '');

        if ($franjaId === '' || $usuarioId === '') {
            return $this->json(['error' => 'Faltan parámetros requeridos: franja_id y usuario_id.'], 422);
        }

        try {
            if ($marcar) {
                $this->servicio->marcarFranja($acuerdoId, $franjaId, $usuarioId);
            } else {
                $this->servicio->desmarcarFranja($acuerdoId, $franjaId, $usuarioId);
            }
        } catch (ReglaIncumplida $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        }

        return $this->json(['ok' => true, 'franja_id' => $franjaId, 'marcada' => $marcar]);
    }
}
