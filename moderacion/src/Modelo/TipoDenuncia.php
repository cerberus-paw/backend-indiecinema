<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * Lo que se denuncia (EF-07.8). Cada uno es de otro subsistema: la sala, la función y la obra, de
 * programación; el comentario de una calificación, de funciones.
 */
enum TipoDenuncia: string
{
    case Sala = 'sala';
    case Funcion = 'funcion';
    case Obra = 'obra';
    case Calificacion = 'calificacion';

    /**
     * Las categorías que se ofrecen: «Seguridad del lugar» sólo tiene sentido donde hay un lugar.
     *
     * @return list<CategoriaDenuncia>
     */
    public function categorias(): array
    {
        return array_values(array_filter(
            CategoriaDenuncia::cases(),
            fn (CategoriaDenuncia $categoria): bool => $categoria !== CategoriaDenuncia::Seguridad || in_array($this, [self::Sala, self::Funcion], true),
        ));
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sala => 'Sala',
            self::Funcion => 'Función',
            self::Obra => 'Obra',
            self::Calificacion => 'Comentario',
        };
    }
}
