<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Datos validados para registrar el pedido de una película o cortometraje ausente en el catálogo (EF-11.1).
 */
final readonly class DatosDePedido
{
    public string $titulo;
    public string $tituloNormalizado;
    public ?int $anio;
    public string $director;

    /**
     * @throws ReglaIncumplida si el título o la dirección faltan o superan el largo, o el año es inválido
     */
    public function __construct(string $titulo, ?int $anio, string $director)
    {
        $tituloLimpio = trim((string) preg_replace('/\s+/u', ' ', $titulo));
        if ($tituloLimpio === '' || mb_strlen($tituloLimpio) > 150) {
            throw new ReglaIncumplida('El título del pedido es obligatorio (hasta 150 caracteres).');
        }
        $anioActual = (int) date('Y');
        if ($anio !== null && ($anio < Obra::PRIMER_ANIO || $anio > $anioActual)) {
            throw new ReglaIncumplida('El año tiene que estar entre ' . Obra::PRIMER_ANIO . ' y el actual.');
        }
        $directorLimpio = trim((string) preg_replace('/\s+/u', ' ', $director));
        if ($directorLimpio === '' || mb_strlen($directorLimpio) > 100) {
            throw new ReglaIncumplida('La dirección del pedido es obligatoria (hasta 100 caracteres).');
        }

        $this->titulo = $tituloLimpio;
        $this->tituloNormalizado = self::normalizarTitulo($tituloLimpio);
        $this->anio = $anio;
        $this->director = $directorLimpio;
    }

    /**
     * Forma canónica para comparar títulos sin mayúsculas, tildes ni signos o espacios de más
     * (EF-22.6). Si un título tuviera sólo símbolos, conserva el texto en minúscula sin espacios
     * repetidos para no colapsar títulos distintos en cadena vacía.
     */
    public static function normalizarTitulo(string $titulo): string
    {
        $sinTildes = strtr($titulo, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Å' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o', 'Õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
            'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
        ]);
        $minuscula = mb_strtolower($sinTildes, 'UTF-8');
        $compacto = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $minuscula));
        if ($compacto === '') {
            $compacto = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($titulo, 'UTF-8')));
        }

        return mb_substr($compacto, 0, 150, 'UTF-8');
    }
}
