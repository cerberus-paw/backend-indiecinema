<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Datos validados para registrar el pedido de una película o cortometraje ausente en el catálogo (EF-11.1).
 */
final readonly class DatosDePedido
{
    public string $titulo;
    public int $anio;
    public string $director;

    /**
     * @throws ReglaIncumplida si el título o la dirección faltan o superan el largo, o el año es inválido
     */
    public function __construct(string $titulo, int $anio, string $director)
    {
        $tituloLimpio = trim($titulo);
        if ($tituloLimpio === '' || mb_strlen($tituloLimpio) > 150) {
            throw new ReglaIncumplida('El título del pedido es obligatorio (hasta 150 caracteres).');
        }
        $anioActual = (int) date('Y');
        if ($anio < Obra::PRIMER_ANIO || $anio > $anioActual) {
            throw new ReglaIncumplida('El año tiene que estar entre ' . Obra::PRIMER_ANIO . ' y el actual.');
        }
        $directorLimpio = trim($director);
        if ($directorLimpio === '' || mb_strlen($directorLimpio) > 100) {
            throw new ReglaIncumplida('La dirección del pedido es obligatoria (hasta 100 caracteres).');
        }

        $this->titulo = $tituloLimpio;
        $this->anio = $anio;
        $this->director = $directorLimpio;
    }
}
