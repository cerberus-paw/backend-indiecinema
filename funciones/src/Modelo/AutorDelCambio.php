<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use InvalidArgumentException;

/**
 * Quién cambia el estado de una reserva y con qué rol, para el historial (E4-03): el espectador
 * que la cancela, el organizador que registra la asistencia o cancela la función, o el
 * administrador que la anula.
 */
final readonly class AutorDelCambio
{
    /**
     * @param ?string $motivo por qué, si no es obvio por el cambio (la función se canceló)
     *
     * @throws InvalidArgumentException con un rol que no cambia reservas
     */
    public function __construct(
        public string $id,
        public Rol $rol,
        public ?string $motivo = null,
    ) {
        if (!in_array($rol, [Rol::Espectador, Rol::Organizador, Rol::Administrador], true)) {
            throw new InvalidArgumentException("Un {$rol->value} no cambia el estado de una reserva.");
        }
    }

    /**
     * El usuario en sesión, con el rol con el que actúa en esta pantalla: el administrador también
     * alcanza el de organizador, pero en el historial queda como lo que hizo.
     */
    public static function de(Usuario $usuario, Rol $comoRol, ?string $motivo = null): self
    {
        return new self($usuario->id, $comoRol, $motivo);
    }
}
