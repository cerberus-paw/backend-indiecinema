<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use InvalidArgumentException;

/**
 * Quién cambia el estado de una reserva y con qué rol, para el historial (E4-03): el espectador
 * que la cancela, el organizador que registra la asistencia o cancela la función, el
 * administrador que la anula, o el sistema solo (EF-18.6).
 */
final readonly class AutorDelCambio
{
    /**
     * El rol que figura en el historial cuando el cambio no lo hizo nadie: una tarea programada o
     * un aviso de otro subsistema (EF-18.6). Antes esos cambios quedaban a nombre del espectador o
     * del organizador, y el historial decía que ellos habían hecho algo que no hicieron.
     */
    public const SISTEMA = 'sistema';

    /**
     * @param ?string $id     el usuario, o null si el cambio lo hizo el sistema
     * @param ?Rol    $rol    con qué rol actuó, o null si fue el sistema
     * @param ?string $motivo por qué, si no es obvio por el cambio (la función se canceló)
     *
     * @throws InvalidArgumentException con un rol que no cambia reservas, o con usuario y sin rol
     */
    public function __construct(
        public ?string $id,
        public ?Rol $rol,
        public ?string $motivo = null,
    ) {
        if (($id === null) !== ($rol === null)) {
            throw new InvalidArgumentException('Un cambio lo hace un usuario con su rol, o el sistema sin ninguno de los dos.');
        }
        if ($rol !== null && !in_array($rol, [Rol::Espectador, Rol::Organizador, Rol::Administrador], true)) {
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

    /**
     * El sistema, cuando la reserva cambia sin que nadie la toque: la que vence sin pagar, la que
     * queda ausente al terminar la función, o la que se cancela porque el pago llegó tarde y ya no
     * había lugar. El motivo es lo que la explica.
     */
    public static function sistema(string $motivo): self
    {
        return new self(null, null, $motivo);
    }

    /**
     * Lo que se guarda en cambio_reserva.autor_rol.
     */
    public function rolEnElHistorial(): string
    {
        return $this->rol->value ?? self::SISTEMA;
    }
}
