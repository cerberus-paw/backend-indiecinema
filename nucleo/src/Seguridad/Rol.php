<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Seguridad;

/**
 * Los roles de IndieCinema, de menor a mayor acceso.
 *
 * El sitemap de la E1 los ordena así: cada rol ve lo que ve el anterior y algo más. Por eso cada
 * ruta declara un rol mínimo y no una lista de roles.
 */
enum Rol: string
{
    case Visitante = 'visitante';
    case Espectador = 'espectador';
    case Organizador = 'organizador';
    case Moderador = 'moderador';
    case Administrador = 'administrador';

    public function alcanza(self $minimo): bool
    {
        return $this->nivel() >= $minimo->nivel();
    }

    private function nivel(): int
    {
        return match ($this) {
            self::Visitante => 0,
            self::Espectador => 1,
            self::Organizador => 2,
            self::Moderador => 3,
            self::Administrador => 4,
        };
    }
}
