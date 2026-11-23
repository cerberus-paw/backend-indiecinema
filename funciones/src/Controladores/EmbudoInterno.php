<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Repositorios\RepositorioDelEmbudo;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;

/**
 * GET /interno/embudo?funciones=id1,id2,…: hasta qué paso llegó cada espectador en cada función,
 * {funciones: {id: [{usuario_id, llego_a, total}]}}, para el embudo de conversión de los reportes
 * de recaudación (EF-22.13). llego_a es reservo, pago, fue o califico; total, lo que pagó o, si no
 * llegó a pagar, lo que quiso comprar. Hasta 100 funciones por pedido, como /interno/cobros. Sólo
 * la llama recaudación: lo controla la ruta.
 */
final class EmbudoInterno extends Controlador
{
    public const MAXIMO_POR_PEDIDO = 100;

    public function __construct(private readonly RepositorioDelEmbudo $embudo)
    {
    }

    public function deVarias(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('funciones');
        $ids = is_string($lista) && $lista !== '' ? array_values(array_unique(explode(',', $lista))) : [];
        if ($ids === [] || count($ids) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden entre 1 y ' . self::MAXIMO_POR_PEDIDO . ' funciones por vez.'], 400);
        }
        if (array_filter($ids, static fn (string $id): bool => !Uuid::esValido($id)) !== []) {
            return $this->json(['error' => 'Los ids de las funciones son UUID.'], 400);
        }

        return $this->json(['funciones' => (object) $this->embudo->deLasFunciones($ids)]);
    }
}
