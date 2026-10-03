<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Servicios\CorreoYaRegistrado;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Seguridad\Rol;
use PDOException;

/**
 * Los usuarios y sus roles en el esquema de cuentas.
 *
 * El rol y el estado se cambian sólo por acá, y cada cambio borra las sesiones del usuario en la
 * misma transacción: nginx manda el rol en cada pedido, y una sesión abierta con el rol anterior
 * o de una cuenta suspendida no tiene que seguir sirviendo. Al volver a entrar, la sesión nueva
 * ya sale con los datos al día.
 */
final class RepositorioDeUsuarios
{
    /** ER_DUP_ENTRY: la única clave que puede repetirse al agregar es la del correo. */
    private const CLAVE_DUPLICADA = 1062;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * El usuario con ese correo, sin distinguir mayúsculas (lo hace la columna), o null.
     */
    public function buscarPorCorreo(string $correo): ?Usuario
    {
        $fila = $this->base->fila(
            'SELECT id, nombre, correo, contrasena_hash, estado FROM usuario WHERE correo = ?',
            [$correo],
        );
        if ($fila === null) {
            return null;
        }
        $roles = $this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ?', [$fila['id']]);

        return new Usuario(
            (string) $fila['id'],
            (string) $fila['nombre'],
            (string) $fila['correo'],
            (string) $fila['contrasena_hash'],
            EstadoCuenta::from((string) $fila['estado']),
            array_map(static fn (array $rol): Rol => Rol::from((string) $rol['tipo']), $roles),
        );
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

    public function agregarRol(string $usuarioId, Rol $rol): void
    {
        $this->cerrandoSesiones($usuarioId, fn (BaseDeDatos $base) => $base->ejecutar(
            'INSERT INTO rol (usuario_id, tipo) VALUES (?, ?)',
            [$usuarioId, $rol->value],
        ));
    }

    public function quitarRol(string $usuarioId, Rol $rol): void
    {
        $this->cerrandoSesiones($usuarioId, fn (BaseDeDatos $base) => $base->ejecutar(
            'DELETE FROM rol WHERE usuario_id = ? AND tipo = ?',
            [$usuarioId, $rol->value],
        ));
    }

    /**
     * Suspender, reactivar o, con la verificación por correo, dejar pendiente.
     */
    public function cambiarEstado(string $usuarioId, EstadoCuenta $estado): void
    {
        $this->cerrandoSesiones($usuarioId, fn (BaseDeDatos $base) => $base->ejecutar(
            'UPDATE usuario SET estado = ? WHERE id = ?',
            [$estado->value, $usuarioId],
        ));
    }

    /**
     * @param callable(BaseDeDatos): mixed $cambio
     */
    private function cerrandoSesiones(string $usuarioId, callable $cambio): void
    {
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($usuarioId, $cambio): void {
            $cambio($base);
            $base->ejecutar('DELETE FROM sesion WHERE usuario_id = ?', [$usuarioId]);
        });
    }
}
