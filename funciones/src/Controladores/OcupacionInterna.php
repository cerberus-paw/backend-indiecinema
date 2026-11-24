<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\PoliticaDeCancelacion;
use IndieCinema\Funciones\Servicios\ServicioDeConducta;
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

    public function __construct(
        private readonly RepositorioDeReservas $reservas,
        private readonly ?ServicioDeConducta $conducta = null,
        private readonly ?PoliticaDeCancelacion $politica = null,
    ) {
    }

    /**
     * GET /interno/funciones/{id}/ocupacion, para la ficha. Trae también el tope de lugares por
     * persona y la política de cancelación (EF-22.9), para que programación no tenga su propia
     * copia de los números, y si viene el usuario, cuántos lugares ya tiene reservados él (IC-463).
     *
     * @throws ExcepcionHttp 404 si el id no es un UUID
     */
    public function deUna(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $datos = [
            'funcion_id' => $id,
            'lugares_ocupados' => $this->reservas->lugaresOcupados($id),
            'maximo_por_persona' => Reserva::MAXIMO_POR_PERSONA,
        ];
        // EF-22.9: la política de cancelación escrita, para que la ficha la muestre al reservar sin
        // tener su propia copia del número de horas.
        if ($this->politica !== null) {
            $datos['politica_cancelacion'] = $this->politica->texto();
        }
        $usuario = $peticion->consulta('usuario');
        if (is_string($usuario) && Uuid::esValido($usuario)) {
            $datos['lugares_del_usuario'] = $this->reservas->lugaresOcupadosPorUsuario($id, $usuario);
            $topeConducta = $this->conducta?->topeSimultaneoPara($usuario);
            if ($topeConducta !== null) {
                $datos['tope_reservas_conducta'] = $topeConducta;
                $datos['reservas_activas_usuario'] = $this->reservas->activasDelUsuario($usuario);
            }
            if ($this->conducta !== null) {
                $datos['tiene_beneficio_conducta'] = $this->conducta->tieneBeneficio($usuario);
                $datos['umbral_beneficio_conducta'] = $this->conducta->umbralBeneficio();
                $datos['dias_preventa_conducta'] = $this->conducta->diasPreventa();
            }
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
