<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Modelo\ResumenDeFuncion;
use IndieCinema\Programacion\Servicios\CalificacionesDeFunciones;
use IndieCinema\Programacion\Servicios\OcupacionDeFunciones;
use IndieCinema\Programacion\Servicios\ServicioDeCartelera;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;

/**
 * GET /salas: el listado público de salas habilitadas, para cualquiera, con o sin sesión, con un
 * buscador por nombre y localidad que viaja en la URL (un formulario GET), así funciona sin
 * JavaScript. GET /salas/{id}: la ficha de una, con sus próximas funciones, sus calificaciones y el
 * historial de lo que ya se proyectó (EF-04.3).
 */
final class Salas extends Controlador
{
    public function __construct(
        private readonly ServicioDeSalas $servicio,
        private readonly OcupacionDeFunciones $ocupacion,
        // Para el estado del acuerdo de las funciones de la sala, que vive en funciones (E3-13.F9).
        private readonly ServicioDeCartelera $cartelera,
        // Los promedios y las últimas calificaciones, que también viven en funciones (EF-04.3).
        private readonly CalificacionesDeFunciones $calificaciones,
    ) {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $parametros = ParametrosDeSalas::desdeConsulta(['q' => $peticion->consulta('q')]);

        return $this->vista('salas.html.twig', [
            'salas' => array_map(TarjetaDeSala::datos(...), $this->servicio->publicadas($parametros->texto)),
            'busqueda' => $parametros->texto,
            'avisos' => $parametros->avisos,
        ]);
    }

    /**
     * La plantilla recibe la sala (lo de la tarjeta, más la descripción, la dirección y los
     * parámetros con los que programa: películas por función, duración máxima de la función y
     * tiempo entre funciones, en minutos) y sus funciones, como las recibe parciales/fila_funcion.
     *
     * @throws ExcepcionHttp 404 si no existe o quien la pide no la puede ver
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $ficha = $this->servicio->ficha((string) $peticion->parametro('id'), $peticion->usuario());
        $salaId = $ficha->sala->id;
        // Las calificaciones (EF-04.3): el promedio de la sala y de cada función del historial, en
        // un pedido, y las últimas sólo si tiene alguna.
        $proyectadas = $this->servicio->proyectadas($ficha->sala);
        $resumen = $this->calificaciones->resumen([
            'salas' => [$salaId],
            'funciones' => array_map(static fn (ResumenDeFuncion $funcion): string => $funcion->id, $proyectadas),
        ]);
        $deLaSala = $resumen['salas'][$salaId] ?? null;
        $ultimas = $deLaSala === null ? [] : $this->calificaciones->ultimas('sala', $salaId);

        return $this->vista('sala.html.twig', [
            'sala' => TarjetaDeSala::datos($ficha->sala) + [
                'descripcion' => $ficha->sala->datos->descripcion,
                'direccion' => $ficha->sala->datos->direccion,
                'peliculas_por_funcion' => $ficha->sala->datos->peliculasPorFuncion,
                'duracion_funcion' => $ficha->sala->datos->duracionFuncion,
                'tiempo_entre_funciones' => $ficha->sala->datos->tiempoEntreFunciones,
            ],
            'funciones' => FilaDeFuncion::todas(
                $ficha->funciones,
                $this->cartelera->acuerdosDe($ficha->funciones),
                $this->ocupacion->deVarias(TarjetaDeFuncion::conLugares($ficha->funciones)),
            ),
            'calificaciones' => CalificacionesParaMostrar::datos($resumen !== null && $ultimas !== null, $deLaSala, $ultimas),
            'historial' => CalificacionesParaMostrar::historial($proyectadas, $resumen),
        ]);
    }
}
