<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Servicios\ServicioDeFunciones;

/**
 * GET /interno/funciones/{id}: una función para el subsistema funciones, que no tiene acceso al
 * esquema de programación (E2, sección 4). Sólo la llama funciones, con su token: lo controla la
 * ruta. Desde afuera nginx ni siquiera la deja llegar.
 */
final class FuncionesInternas extends Controlador
{
    public function __construct(private readonly ServicioDeFunciones $servicio)
    {
    }

    /**
     * El JSON lleva los nombres de las columnas, en castellano como el resto del código. La fecha
     * va en ISO 8601 sin zona: es la hora de la sala, como la guarda programación.
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $funcion = $this->servicio->resumen((string) $peticion->parametro('id'));

        return $this->json([
            'id' => $funcion->id,
            'estado' => $funcion->estado->value,
            'fecha_hora' => $funcion->fechaHora?->format('Y-m-d\TH:i:s'),
            'precio_base' => $funcion->precioBase,
            'cupo' => $funcion->cupo,
            'titulos' => $funcion->titulos,
            'titulos_elegidos' => $funcion->titulosElegidos,
            'sala' => [
                'id' => $funcion->salaId,
                'nombre' => $funcion->salaNombre,
                'localidad' => $funcion->salaLocalidad,
                'habilitada' => $funcion->salaHabilitada,
            ],
            'organizador_id' => $funcion->organizadorId,
        ]);
    }
}
