<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Servicios\ServicioDeSesiones;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Identificacion;

/**
 * GET /interno/sesion, para el auth_request de nginx: recibe la cookie del pedido original y
 * responde quién es en cabeceras, que nginx le pasa al subsistema.
 *
 * Siempre 200: sin sesión válida, con las cabeceras vacías, así las páginas públicas siguen
 * andando. Las privadas las rechaza cada subsistema según el rol de la ruta.
 */
final class SesionInterna extends Controlador
{
    public function __construct(private readonly ServicioDeSesiones $sesiones)
    {
    }

    public function validar(Peticion $peticion): Respuesta
    {
        $deUsuario = $this->sesiones->validar($peticion->cookie(Sesion::COOKIE));

        return (new Respuesta())
            ->conCabecera(Identificacion::CABECERA_ID, $deUsuario->sesion->usuarioId ?? '')
            ->conCabecera(Identificacion::CABECERA_ROL, $deUsuario?->rol()->value ?? '')
            // Codificado: una cabecera no garantiza UTF-8, y así el nombre no puede meter un salto de línea.
            ->conCabecera(Identificacion::CABECERA_NOMBRE, $deUsuario === null ? '' : rawurlencode($deUsuario->nombre));
    }
}
