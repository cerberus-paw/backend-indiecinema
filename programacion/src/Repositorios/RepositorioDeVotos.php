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
     * Las obras que ganan la votación entre las candidatas: las $cuantas más votadas (E3-12.4,
     * EF-05.1), de la más votada a la menos. Una candidata sin votos cuenta cero, y el empate lo
     * gana la que va primero entre las candidatas, así que el resultado es siempre el mismo. Si hay
     * menos candidatas que $cuantas, están todas.
     *
     * @param list<string> $candidatasIds en el orden de la votación
     * @param positive-int $cuantas       las películas por función de la sala
     *
     * @return non-empty-list<string>
     *
     * @throws ReglaIncumplida si no hay obras candidatas
     */
    public function masVotadas(string $funcionId, array $candidatasIds, int $cuantas): array
    {
        if ($candidatasIds === []) {
            throw new ReglaIncumplida('No hay obras candidatas.');
        }

        $conteo = $this->conteoPorObra($funcionId);
        $posiciones = array_flip($candidatasIds);
        $ordenadas = $candidatasIds;
        usort($ordenadas, static fn (string $una, string $otra): int => ($conteo[$otra] ?? 0) <=> ($conteo[$una] ?? 0)
            ?: $posiciones[$una] <=> $posiciones[$otra]);

        return array_slice($ordenadas, 0, $cuantas);
    }
}
