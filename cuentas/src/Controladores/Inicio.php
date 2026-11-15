<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Servicios\ServicioDeUsuarios;
use IndieCinema\Cuentas\Servicios\SolicitudesDeModeracion;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use LogicException;

/**
 * GET /cuenta/: «Mi cuenta», como el wireframe mi-cuenta-mis-datos (E4-10.13): la tarjeta con el
 * nombre, desde cuándo tiene la cuenta y los roles, y «Mis datos» con el nombre y el correo, sólo
 * de lectura (editarlos y cambiar la contraseña todavía no tienen ruta). Los datos salen de la
 * cuenta y no de las cabeceras de nginx, que traen sólo el nombre y el mayor rol.
 *
 * Si el usuario mandó alguna solicitud de sala, «Mi solicitud de sala» muestra en qué quedó la
 * última (EF-07.6), según moderación.
 */
final class Inicio extends Controlador
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private readonly ServicioDeUsuarios $usuarios,
        private readonly SolicitudesDeModeracion $solicitudes,
    ) {
    }

    /**
     * @throws ExcepcionHttp 404 si la cuenta de la sesión ya no existe
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $usuario = $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');
        $cuenta = $this->usuarios->buscarEnListado($usuario->id) ?? throw ExcepcionHttp::noEncontrada();

        $datos = [
            'cuenta' => [
                'nombre' => $cuenta->nombre,
                'correo' => $cuenta->correo,
                'roles' => array_map(static fn (Rol $rol): string => $rol->value, $cuenta->roles),
                // «Desde marzo de 2025», como el wireframe.
                'desde' => self::MESES[(int) $cuenta->creadoEn->format('n') - 1] . ' de ' . $cuenta->creadoEn->format('Y'),
            ],
        ];
        // La plantilla muestra el bloque si la variable existe: sin solicitud, o si moderación no
        // responde, no se pasa.
        $solicitud = $this->solicitudes->ultimaDe($cuenta->id);
        if ($solicitud !== null) {
            $datos['solicitud_de_sala'] = [
                'estado' => $solicitud['estado'],
                'sala' => $solicitud['sala'],
                'motivo' => $solicitud['motivo'],
                'fecha' => $solicitud['fecha']->format('d/m/Y'),
            ];
        }

        return $this->vista('mi_cuenta.html.twig', $datos);
    }
}
