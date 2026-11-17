<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Servicios\ServicioDeConducta;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;

/**
 * Puntaje de conducta en lote para el panel «Usuarios y roles» del administrador en cuentas
 * (EF-13.3). Sólo lo llama cuentas con su token: lo controla la ruta.
 */
final class ConductaInterna extends Controlador
{
    public const MAXIMO_POR_PEDIDO = 100;

    public function __construct(private readonly ServicioDeConducta $conducta)
    {
    }

    /**
     * GET /interno/usuarios/conducta?ids=id1,id2,…: devuelve {conductas: {id: {puntaje,
     * bajo_umbral, negativas, ausencias}}}. Hasta 100 usuarios por pedido.
     */
    public function deVarios(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('ids');
        if (!is_string($lista) || trim($lista) === '') {
            return $this->json(['conductas' => (object) []]);
        }

        $partes = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $lista)),
            static fn (string $id): bool => $id !== '',
        )));
        if (count($partes) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden hasta ' . self::MAXIMO_POR_PEDIDO . ' usuarios por vez.'], 400);
        }

        $ids = array_values(array_filter($partes, Uuid::esValido(...)));
        if ($ids === []) {
            return $this->json(['conductas' => (object) []]);
        }

        return $this->json(['conductas' => (object) $this->conducta->resumenDeVarios($ids)]);
    }
}
