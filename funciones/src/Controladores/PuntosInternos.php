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
 * Lo que recaudación necesita para los puntos y los cupones (EF-17.1). Sólo la llama
 * recaudación, con su token: lo controla la ruta.
 */
final class PuntosInternos extends Controlador
{
    private const MAXIMO_POR_PEDIDO = 100;

    public function __construct(private readonly RepositorioDeReservas $reservas)
    {
    }

    /**
     * GET /interno/usuarios/{id}/asistencias: cada reserva a la que fue suma puntos.
     *
     * @throws ExcepcionHttp 404 si el id no es un UUID
     */
    public function asistencias(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->json(['asistencias' => $this->reservas->asistenciasDe($id)]);
    }

    /**
     * GET /interno/cupones/en-uso?codigos=…: de esos, los que están en una reserva que no se
     * canceló, para que «Mis puntos» diga cuáles ya se usaron.
     */
    public function cuponesEnUso(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('codigos');
        $codigos = is_string($lista) && $lista !== '' ? array_values(array_unique(explode(',', $lista))) : [];
        if ($codigos === [] || count($codigos) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden entre 1 y ' . self::MAXIMO_POR_PEDIDO . ' cupones por vez.'], 400);
        }
        if (array_filter($codigos, static fn (string $codigo): bool => preg_match('/^[A-Z0-9]{8}$/', $codigo) !== 1) !== []) {
            return $this->json(['error' => 'Los cupones son 8 letras mayúsculas y números.'], 400);
        }

        return $this->json(['en_uso' => $this->reservas->cuponesEnUso($codigos)]);
    }
}
