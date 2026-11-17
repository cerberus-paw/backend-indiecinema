<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use RuntimeException;

/**
 * Las funciones, que son de programación: moderación lee la denunciada por su API interna
 * (EF-07.8), para mostrarla y actuar sobre su sala o sus obras.
 */
final class FuncionesDeProgramacion
{
    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * @return ?array{estado: string, fecha_hora: ?string, sala_id: string, sala: string, sala_habilitada: bool, organizador_id: string, obras: list<array{id: string, titulo: string}>} null si no existe
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function una(string $id): ?array
    {
        $funcion = $this->cliente->get('programacion', '/interno/funciones/' . rawurlencode($id));
        if ($funcion === null) {
            return null;
        }
        if (
            !is_array($funcion) || !is_string($funcion['estado'] ?? null) || !is_array($funcion['sala'] ?? null)
            || !is_string($funcion['sala']['id'] ?? null) || !is_string($funcion['sala']['nombre'] ?? null)
            || !is_string($funcion['organizador_id'] ?? null) || !is_array($funcion['titulos'] ?? null) || !is_array($funcion['obras_ids'] ?? null)
        ) {
            throw new RuntimeException("programación no respondió la función {$id} a su API interna.");
        }
        $titulos = array_values(array_map(strval(...), $funcion['titulos']));
        $obrasIds = array_values(array_map(strval(...), $funcion['obras_ids']));

        return [
            'estado' => $funcion['estado'],
            'fecha_hora' => is_string($funcion['fecha_hora'] ?? null) ? $funcion['fecha_hora'] : null,
            'sala_id' => $funcion['sala']['id'],
            'sala' => $funcion['sala']['nombre'],
            'sala_habilitada' => ($funcion['sala']['habilitada'] ?? false) === true,
            'organizador_id' => $funcion['organizador_id'],
            // Los títulos van en el mismo orden que obras_ids.
            'obras' => array_map(static fn (string $obraId, int $i): array => ['id' => $obraId, 'titulo' => $titulos[$i] ?? ''], $obrasIds, array_keys($obrasIds)),
        ];
    }
}
