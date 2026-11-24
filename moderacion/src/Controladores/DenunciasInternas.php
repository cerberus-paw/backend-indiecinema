<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use IndieCinema\Moderacion\Servicios\ReglasDeDenuncias;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * GET /interno/denuncias/castigadas (EF-22.11): las salas que hoy bajan al final de la cartelera
 * por las reglas de las denuncias, {salas: [id, …]}. El castigo no se guarda: se calcula con el
 * mismo conteo de las reglas, y se levanta solo cuando las denuncias salen del período. Sólo la
 * llama programación, con su token: lo controla la ruta.
 */
final class DenunciasInternas extends Controlador
{
    public function __construct(private readonly ReglasDeDenuncias $reglas)
    {
    }

    public function castigadas(Peticion $peticion): Respuesta
    {
        return $this->json(['salas' => array_map(strval(...), array_keys($this->reglas->castigadas()))]);
    }
}
