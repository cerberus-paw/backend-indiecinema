<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;

/**
 * Los votos de los espectadores en las funciones en votación de títulos (E3-12.2).
 */
final class RepositorioDeVotos
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Registra o cambia el voto de un espectador por una obra en una función.
     * Hay un único voto por usuario y función (E3-12.2).
     */
    public function votar(string $funcionId, string $usuarioId, string $obraId): void
    {
        $this->base->ejecutar(
            'INSERT INTO voto (funcion_id, usuario_id, obra_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE obra_id = VALUES(obra_id), votado_en = CURRENT_TIMESTAMP',
            [$funcionId, $usuarioId, $obraId],
        );
    }

    /**
     * La obra votada por el usuario en la función, o null si todavía no votó.
     */
    public function votoDe(string $funcionId, string $usuarioId): ?string
    {
        $obraId = $this->base->valor(
            'SELECT obra_id FROM voto WHERE funcion_id = ? AND usuario_id = ?',
            [$funcionId, $usuarioId],
        );

        return $obraId !== null ? (string) $obraId : null;
    }

    /**
     * Cantidad de votos por obra en una función.
     *
     * @return array<string, int> mapa de obra_id a cantidad de votos
     */
    public function conteoPorObra(string $funcionId): array
    {
        $filas = $this->base->filas(
            'SELECT obra_id, COUNT(*) AS votos FROM voto WHERE funcion_id = ? GROUP BY obra_id',
            [$funcionId],
        );

        $conteo = [];
        foreach ($filas as $fila) {
            $conteo[(string) $fila['obra_id']] = (int) $fila['votos'];
        }

        return $conteo;
    }

    /**
     * Total de votos acumulados en la función.
     */
    public function totalDeVotos(string $funcionId): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM voto WHERE funcion_id = ?', [$funcionId]);
    }

    /**
     * Determina la obra ganadora de la votación entre las candidatas (E3-12.4).
     * Es la obra con mayor cantidad de votos. Si hay empate o no hay votos,
     * se resuelve de forma determinista eligiendo la primera candidata con la cantidad máxima de votos.
     *
     * @param list<string> $candidatasIds
     *
     * @throws ReglaIncumplida si no hay obras candidatas
     */
    public function obraMasVotada(string $funcionId, array $candidatasIds): string
    {
        if ($candidatasIds === []) {
            throw new ReglaIncumplida('No hay obras candidatas.');
        }

        $conteo = $this->conteoPorObra($funcionId);

        $ganadoraId = $candidatasIds[0];
        $maxVotos = $conteo[$ganadoraId] ?? 0;

        foreach ($candidatasIds as $obraId) {
            $votos = $conteo[$obraId] ?? 0;
            if ($votos > $maxVotos) {
                $maxVotos = $votos;
                $ganadoraId = $obraId;
            }
        }

        return $ganadoraId;
    }
}
