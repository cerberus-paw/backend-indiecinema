<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Repositorios\RepositorioDeConducta;
use IndieCinema\Funciones\Servicios\ServicioDeConducta;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;

/**
 * Puntaje de conducta en lote para el panel «Usuarios y roles» del administrador en cuentas
 * (EF-13.3), y para los reportes del administrador en recaudación, con las ausencias y las
 * negativas de cada función (EF-22.4). Cada ruta dice quién la llama.
 */
final class ConductaInterna extends Controlador
{
    public const MAXIMO_POR_PEDIDO = 100;

    public function __construct(
        private readonly ServicioDeConducta $conducta,
        private readonly RepositorioDeConducta $repositorio,
    ) {
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

    /**
     * GET /interno/conducta/funciones?funciones=id1,id2,…: las ausencias y las calificaciones de
     * conducta negativas de cada función, {funciones: {id: {ausentes, negativas}}}, para los
     * reportes del administrador en recaudación (EF-22.4). Las que no tienen ninguna no vienen.
     * Hasta 100 por pedido, como /interno/cobros.
     */
    public function deFunciones(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('funciones');
        $ids = is_string($lista) && $lista !== '' ? array_values(array_unique(explode(',', $lista))) : [];
        if ($ids === [] || count($ids) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden entre 1 y ' . self::MAXIMO_POR_PEDIDO . ' funciones por vez.'], 400);
        }
        if (array_filter($ids, static fn (string $id): bool => !Uuid::esValido($id)) !== []) {
            return $this->json(['error' => 'Los ids de las funciones son UUID.'], 400);
        }

        return $this->json(['funciones' => (object) $this->repositorio->deLasFunciones($ids)]);
    }
}
