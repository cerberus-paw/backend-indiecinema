<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Servicios\CorreoYaRegistrado;
use IndieCinema\Nucleo\BaseDeDatos;
use PDOException;

/**
 * Los usuarios y sus roles en el esquema de cuentas.
 */
final class RepositorioDeUsuarios
{
    /** ER_DUP_ENTRY: la única clave que puede repetirse al agregar es la del correo. */
    private const CLAVE_DUPLICADA = 1062;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * El usuario y sus roles juntos: un usuario sin rol no tiene que quedar en la base.
     *
     * @throws CorreoYaRegistrado
     */
    public function agregar(Usuario $usuario): void
    {
        try {
            $this->base->enTransaccion(function (BaseDeDatos $base) use ($usuario): void {
                $base->ejecutar(
                    'INSERT INTO usuario (id, nombre, correo, contrasena_hash, estado) VALUES (?, ?, ?, ?, ?)',
                    [$usuario->id, $usuario->nombre, $usuario->correo, $usuario->contrasenaHash, $usuario->estado->value],
                );
                foreach ($usuario->roles as $rol) {
                    $base->ejecutar('INSERT INTO rol (usuario_id, tipo) VALUES (?, ?)', [$usuario->id, $rol->value]);
                }
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                throw new CorreoYaRegistrado('El correo ya está registrado.', previous: $error);
            }
            throw $error;
        }
    }
}
