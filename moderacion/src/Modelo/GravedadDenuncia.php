<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * Qué tan grave es una denuncia (EF-22.11): la da su motivo al crearla y el moderador no la cambia.
 * La bandeja pone primero lo más grave.
 */
enum GravedadDenuncia: string
{
    case Baja = 'baja';
    case Media = 'media';
    case Alta = 'alta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Baja => 'Baja',
            self::Media => 'Media',
            self::Alta => 'Alta',
        };
    }

    /**
     * Para ordenar: más grave, más alto. Es la posición en el ENUM de la columna (gravedad + 0).
     */
    public function orden(): int
    {
        return match ($this) {
            self::Baja => 1,
            self::Media => 2,
            self::Alta => 3,
        };
    }

    public static function deOrden(int $orden): self
    {
        foreach (self::cases() as $gravedad) {
            if ($gravedad->orden() === $orden) {
                return $gravedad;
            }
        }

        throw new \ValueError("No hay una gravedad de orden {$orden}.");
    }
}
