<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

enum TipoObra: string
{
    case Pelicula = 'pelicula';
    case Cortometraje = 'cortometraje';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pelicula => 'Película',
            self::Cortometraje => 'Cortometraje',
        };
    }

    /**
     * @return list<array{valor: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return [
            ['valor' => self::Pelicula->value, 'etiqueta' => 'Película'],
            ['valor' => self::Cortometraje->value, 'etiqueta' => 'Cortometraje'],
        ];
    }
}
