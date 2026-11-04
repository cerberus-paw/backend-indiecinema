<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Programacion\Modelo\Obra;

/**
 * Formatea los datos de una obra para las vistas (plantillas Twig y respuestas JSON).
 */
final class TarjetaDeObra
{
    /**
     * @param array<string, string> $mapaGeneros [genero_id => nombre]
     *
     * @return array{
     *     id: string,
     *     titulo: string,
     *     sinopsis: string,
     *     anio: int,
     *     director: string,
     *     duracion: int,
     *     tipo: string,
     *     tipo_etiqueta: string,
     *     origen_copia: string,
     *     origen_etiqueta: string,
     *     generos: list<string>,
     *     afiche: ?string,
     *     url: string
     * }
     */
    public static function datos(Obra $obra, array $mapaGeneros = []): array
    {
        $nombresGeneros = [];
        foreach ($obra->generosIds as $generoId) {
            if (isset($mapaGeneros[$generoId])) {
                $nombresGeneros[] = $mapaGeneros[$generoId];
            }
        }

        return [
            'id' => $obra->id,
            'titulo' => $obra->titulo,
            'sinopsis' => $obra->sinopsis,
            'anio' => $obra->anio,
            'director' => $obra->director,
            'duracion' => $obra->duracionMinutos,
            'tipo' => $obra->tipo->value,
            'tipo_etiqueta' => $obra->tipo->etiqueta(),
            'origen_copia' => $obra->origenCopia->value,
            'origen_etiqueta' => $obra->origenCopia->etiqueta(),
            'generos' => $nombresGeneros,
            'afiche' => $obra->afiche !== null ? "/obras/{$obra->id}/afiche" : null,
            'url' => "/obras/{$obra->id}",
        ];
    }
}
