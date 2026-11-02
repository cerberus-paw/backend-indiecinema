<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Servicios\ServicioDeCartelera;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;

/**
 * GET /salas: el listado público de salas habilitadas, para cualquiera, con o sin sesión, con un
 * buscador por nombre y localidad que viaja en la URL (un formulario GET), así funciona sin
 * JavaScript. GET /salas/{id}: la ficha de una, con sus próximas funciones.
 */
final class Salas extends Controlador
{
    public function __construct(
        private readonly ServicioDeSalas $servicio,
        // Para el estado del acuerdo de las funciones de la sala, que vive en funciones (E3-13.F9).
        private readonly ServicioDeCartelera $cartelera,
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
     * La plantilla recibe la sala (lo de la tarjeta, más la descripción y la dirección) y sus
     * funciones, como las recibe parciales/tarjeta_funcion.
     *
     * @throws ExcepcionHttp 404 si no existe o quien la pide no la puede ver
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $ficha = $this->servicio->ficha((string) $peticion->parametro('id'), $peticion->usuario());

        return $this->vista('sala.html.twig', [
            'sala' => TarjetaDeSala::datos($ficha->sala) + [
                'descripcion' => $ficha->sala->datos->descripcion,
                'direccion' => $ficha->sala->datos->direccion,
            ],
            'funciones' => TarjetaDeFuncion::todas($ficha->funciones, $this->cartelera->acuerdosDe($ficha->funciones)),
        ]);
    }
}
