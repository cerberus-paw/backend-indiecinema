<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

/**
 * Un texto de búsqueda que llega en la URL, que cualquiera puede escribir a mano. Lo comparten la
 * cartelera y el listado de salas, que con un texto que no sirve se muestran sin ese filtro y
 * avisan por qué.
 */
final class TextoDeLaUrl
{
    /**
     * Por qué el texto no sirve, para completar el aviso, o null si sirve. Se llama con un valor
     * que vino: uno vacío es no filtrar, y lo decide quien llama.
     */
    public static function problema(mixed $valor, int $maximo): ?string
    {
        // Un «q[]=…» llega como arreglo, y un texto que no es UTF-8 no se puede comparar.
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return 'no es un texto válido';
        }
        if (mb_strlen(trim($valor)) > $maximo) {
            return "puede tener hasta {$maximo} caracteres";
        }

        return null;
    }
}
