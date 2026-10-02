<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Seguridad;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;

/**
 * Compara el rol del usuario con el mínimo que declara la ruta, antes de llegar al controlador.
 * La autorización es local a cada subsistema (E2, sección 3): nginx sólo dice quién es.
 *
 * Que el recurso sea del usuario (un organizador no edita la sala de otro) no se ve desde acá:
 * lo verifica el servicio.
 */
final class ControlDeAcceso
{
    /**
     * @throws ExcepcionHttp 401 si la ruta pide sesión y no hay, 403 si el rol no alcanza
     */
    public function verificar(Peticion $peticion): void
    {
        $minimo = $peticion->rutaResuelta()?->rolMinimo ?? Rol::Visitante;
        if ($minimo === Rol::Visitante) {
            return;
        }

        $usuario = $peticion->usuario();
        if ($usuario === null) {
            throw ExcepcionHttp::sinSesion();
        }
        if (!$usuario->alcanza($minimo)) {
            throw ExcepcionHttp::prohibida();
        }
    }
}
