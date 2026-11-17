<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use RuntimeException;

/**
 * Las calificaciones, que son de funciones: moderación lee el comentario denunciado y lo oculta
 * por su API interna (EF-07.8).
 */
final class CalificacionesDeFunciones
{
    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * @return ?array{sala_id: string, usuario_id: string, espectador: ?string, puntaje: int, comentario: ?string, fecha: string, oculto: bool} null si no existe
     *
     * @throws ExcepcionHttp    503 si funciones no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function una(string $id): ?array
    {
        $calificacion = $this->cliente->get('funciones', '/interno/calificaciones/' . rawurlencode($id));
        if ($calificacion === null) {
            return null;
        }
        if (
            !is_array($calificacion) || !is_string($calificacion['sala_id'] ?? null) || !is_string($calificacion['usuario_id'] ?? null)
            || !is_int($calificacion['puntaje'] ?? null) || !is_string($calificacion['fecha'] ?? null) || !is_bool($calificacion['oculto'] ?? null)
        ) {
            throw new RuntimeException("funciones no respondió la calificación {$id} a su API interna.");
        }

        return [
            'sala_id' => $calificacion['sala_id'],
            'usuario_id' => $calificacion['usuario_id'],
            'espectador' => is_string($calificacion['espectador'] ?? null) ? $calificacion['espectador'] : null,
            'puntaje' => $calificacion['puntaje'],
            'comentario' => is_string($calificacion['comentario'] ?? null) ? $calificacion['comentario'] : null,
            'fecha' => $calificacion['fecha'],
            'oculto' => $calificacion['oculto'],
        ];
    }

    /**
     * Saca el comentario de las fichas. Repetirlo no cambia nada.
     *
     * @throws ExcepcionHttp    503 si funciones no responde; 404 si ya no la tiene
     * @throws RuntimeException si responde otra cosa
     */
    public function ocultar(string $id, string $moderadorId): void
    {
        if ($this->cliente->post('funciones', '/interno/calificaciones/' . rawurlencode($id) . '/ocultar', ['moderador_id' => $moderadorId]) === null) {
            throw ExcepcionHttp::noEncontrada();
        }
    }
}
