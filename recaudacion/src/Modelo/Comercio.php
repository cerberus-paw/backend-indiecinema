<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Un comercio asociado con su beneficio para los espectadores (EF-17.3).
 */
final readonly class Comercio
{
    public const MAXIMO_NOMBRE = 120;
    public const MAXIMO_BENEFICIO = 300;
    public const MAXIMO_DIRECCION = 160;
    public const MAXIMO_LOCALIDAD = 80;

    public function __construct(
        public string $id,
        public string $nombre,
        public string $beneficio,
        public string $direccion,
        public string $localidad,
        public bool $activo,
    ) {
    }

    /**
     * Los errores de cada campo, para el formulario del administrador. Vacío si se puede guardar.
     *
     * @param array{nombre: string, beneficio: string, direccion: string, localidad: string} $valores ya recortados
     *
     * @return array<string, string>
     */
    public static function errores(array $valores): array
    {
        $errores = [];
        foreach ([
            ['nombre', 'el nombre del comercio', self::MAXIMO_NOMBRE],
            ['beneficio', 'el beneficio', self::MAXIMO_BENEFICIO],
            ['direccion', 'la dirección', self::MAXIMO_DIRECCION],
            ['localidad', 'la localidad', self::MAXIMO_LOCALIDAD],
        ] as [$campo, $que, $maximo]) {
            if ($valores[$campo] === '') {
                $errores[$campo] = "Escribí {$que}.";
            } elseif (mb_strlen($valores[$campo]) > $maximo) {
                $errores[$campo] = "Hasta {$maximo} caracteres.";
            }
        }

        return $errores;
    }
}
