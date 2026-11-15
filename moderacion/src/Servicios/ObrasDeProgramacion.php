<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use IndieCinema\Moderacion\Modelo\ObraDeProgramacion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use RuntimeException;

/**
 * Las obras, que son de programación: moderación lee las pendientes (o una, por su id) y las
 * aprueba o las rechaza por su API interna (EF-07.7), sin tocar su esquema.
 */
final class ObrasDeProgramacion
{
    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * Las pendientes, primero la más vieja.
     *
     * @return list<ObraDeProgramacion>
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function pendientes(): array
    {
        $obras = $this->cliente->get('programacion', '/interno/obras/pendientes');
        if (!is_array($obras) || !array_is_list($obras)) {
            throw new RuntimeException('programación no respondió las obras pendientes a GET /interno/obras/pendientes.');
        }

        return array_map(ObraDeProgramacion::desdeJson(...), $obras);
    }

    /**
     * Una obra en cualquier estado, o null si programación no la tiene (o se dio de baja).
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function una(string $id): ?ObraDeProgramacion
    {
        $obra = $this->cliente->get('programacion', '/interno/obras/' . rawurlencode($id));

        return $obra === null ? null : ObraDeProgramacion::desdeJson($obra);
    }

    /**
     * La aprueba o la rechaza. Repetirlo no cambia nada: programación responde cambio = false.
     *
     * @return ?array{titulo: string, dueno_id: string, estado: string, cambio: bool} null si
     *                                                                                programación
     *                                                                                ya no la tiene
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function revisar(string $id, bool $aprobar, string $motivo, string $moderadorId): ?array
    {
        $respuesta = $this->cliente->post('programacion', "/interno/obras/{$id}/revision", [
            'resolucion' => $aprobar ? 'aprobar' : 'rechazar',
            'motivo' => $motivo,
            'moderador_id' => $moderadorId,
        ]);
        if ($respuesta === null) {
            return null;
        }
        if (
            !is_array($respuesta) || !is_string($respuesta['titulo'] ?? null) || !is_string($respuesta['dueno_id'] ?? null)
            || !is_string($respuesta['estado'] ?? null) || !is_bool($respuesta['cambio'] ?? null)
        ) {
            throw new RuntimeException("programación no respondió la revisión de la obra {$id}.");
        }

        return ['titulo' => $respuesta['titulo'], 'dueno_id' => $respuesta['dueno_id'], 'estado' => $respuesta['estado'], 'cambio' => $respuesta['cambio']];
    }
}
