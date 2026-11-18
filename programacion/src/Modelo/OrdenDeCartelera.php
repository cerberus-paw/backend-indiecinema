<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Cómo se ordenan las funciones en la cartelera (E4-06.1): por fecha, precio o título, de forma
 * ascendente o descendente, o por la reputación de la sala (EF-04.4). El orden por defecto es por
 * fecha ascendente (de la más próxima a la más lejana).
 */
enum OrdenDeCartelera: string
{
    case FechaAscendente = 'fecha_asc';
    case FechaDescendente = 'fecha_desc';
    case PrecioAscendente = 'precio_asc';
    case PrecioDescendente = 'precio_desc';
    case TituloAscendente = 'titulo_asc';
    case TituloDescendente = 'titulo_desc';
    // Las salas con mejor promedio de calificaciones primero; las que no tienen, al final (EF-04.4).
    case MejorCalificadas = 'calificacion_desc';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FechaAscendente => 'Fecha: más próxima',
            self::FechaDescendente => 'Fecha: más lejana',
            self::PrecioAscendente => 'Precio: menor a mayor',
            self::PrecioDescendente => 'Precio: mayor a menor',
            self::TituloAscendente => 'Título: A - Z',
            self::TituloDescendente => 'Título: Z - A',
            self::MejorCalificadas => 'Mejor calificadas',
        };
    }

    /**
     * Las opciones para el desplegable de la cartelera. El orden por defecto lleva valor vacío
     * para que no viaje en la URL cuando no se eligió otro.
     *
     * @return list<array{valor: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return array_map(
            static fn (self $orden): array => [
                'valor' => $orden === self::FechaAscendente ? '' : $orden->value,
                'etiqueta' => $orden->etiqueta(),
            ],
            self::cases(),
        );
    }
}

