<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;

/**
 * Los lugares ocupados de una función, para programación (E4-02): el cupo es suyo y las reservas
 * de funciones, así que los lugares libres los calcula ella con este número. Sólo la llama
 * programación, con su token: lo controla la ruta.
 *
 * Funciones no sabe qué funciones existen (viven en programación): una sin reservas, o que no
 * existe, tiene 0 lugares ocupados. Programación ya sabe si existe.
 */
final class OcupacionInterna extends Controlador
{
    /** Más que una página de la cartelera (hasta 100): un pedido más largo es un error de quien llama. */
    private const MAXIMO_POR_PEDIDO = 100;

    public function __construct(private readonly RepositorioDeReservas $reservas)
    {
    }

    /**
     * GET /interno/funciones/{id}/ocupacion, para la ficha. Si viene el usuario, devuelve además
     * cuántos lugares ya tiene reservados él (IC-463).
     *
     * @throws ExcepcionHttp 404 si el id no es un UUID
     */
    public function deUna(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $datos = ['funcion_id' => $id, 'lugares_ocupados' => $this->reservas->lugaresOcupados($id)];
        $usuario = $peticion->consulta('usuario');
        if (is_string($usuario) && Uuid::esValido($usuario)) {
            $datos['lugares_del_usuario'] = $this->reservas->lugaresOcupadosPorUsuario($id, $usuario);
        }

        return $this->json($datos);
    }

    /**
     * GET /interno/ocupacion?funciones=id1,id2,…, para una página de tarjetas en un solo pedido.
     * Los ids que no son UUID se ignoran; más de cien es un 400.
     */
    public function deVarias(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('funciones');
        $ids = is_string($lista) ? array_values(array_unique(array_filter(explode(',', $lista), Uuid::esValido(...)))) : [];
        if (count($ids) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden hasta ' . self::MAXIMO_POR_PEDIDO . ' funciones por vez.'], 400);
        }

        // Un objeto aunque esté vacío: json_encode() escribiría [] como lista.
        return $this->json(['lugares_ocupados' => (object) $this->reservas->lugaresOcupadosDe($ids)]);
    }
}
