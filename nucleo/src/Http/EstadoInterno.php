<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Http;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Controlador;

/**
 * GET /interno/estado: dice qué subsistema responde y a quién reconoció como llamador. Sirve para
 * comprobar que dos subsistemas se alcanzan y que el token de uno está bien cargado en el otro,
 * sin depender de ninguna ruta de negocio. Cada subsistema la declara en su rutas.php.
 */
final class EstadoInterno extends Controlador
{
    public function __construct(private readonly Configuracion $configuracion)
    {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->json([
            'subsistema' => $this->configuracion->requerir('app.subsistema'),
            'llamador' => $peticion->llamador(),
        ]);
    }
}
