<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;
use LogicException;

/**
 * Las salas para el administrador, en /administracion/salas: todas, con las que esperan arriba, y
 * un botón para habilitar o deshabilitar cada una. Las rutas piden el rol de administrador y el
 * núcleo ya controló el token CSRF de los POST antes de llegar acá.
 */
final class AdministracionDeSalas extends Controlador
{
    public function __construct(private readonly ServicioDeSalas $servicio)
    {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $salas = $this->servicio->paraAdministrar();

        return $this->vista('administracion_salas.html.twig', [
            'salas' => array_map(TarjetaDeSala::datos(...), $salas),
            'mensaje_exito' => self::mensaje($salas, $peticion),
        ]);
    }

    /**
     * @throws ExcepcionHttp 404 si la sala no existe
     */
    public function habilitar(Peticion $peticion): Respuesta
    {
        return $this->cambiar($peticion, true);
    }

    /**
     * @throws ExcepcionHttp 404 si la sala no existe
     */
    public function deshabilitar(Peticion $peticion): Respuesta
    {
        return $this->cambiar($peticion, false);
    }

    private function cambiar(Peticion $peticion, bool $habilitada): Respuesta
    {
        $usuario = $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de administrador: sin sesión no llega acá.');
        $sala = $this->servicio->cambiarHabilitacion((string) $peticion->parametro('id'), $habilitada, $usuario);

        // 303 al listado con el aviso: recargar no vuelve a mandar el cambio.
        return $this->redirigir('/administracion/salas?' . ($habilitada ? 'habilitada' : 'deshabilitada') . "={$sala->id}");
    }

    /**
     * El aviso del cambio que se acaba de hacer, con el nombre de la sala, o null.
     *
     * @param list<Sala> $salas
     */
    private static function mensaje(array $salas, Peticion $peticion): ?string
    {
        foreach (['habilitada' => 'Habilitaste «%s»: ya se ve en el sitio.', 'deshabilitada' => 'Deshabilitaste «%s»: ya no se ve en el sitio.'] as $clave => $texto) {
            $id = $peticion->consulta($clave);
            foreach ($salas as $sala) {
                if (is_string($id) && $sala->id === $id) {
                    return sprintf($texto, $sala->datos->nombre);
                }
            }
        }

        return null;
    }
}
