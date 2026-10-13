<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use DateTimeImmutable;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\PaginaDeUsuarios;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Modelo\UsuarioEnListado;
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
     * Una página del listado del administrador, por nombre y después por correo, que es único y
     * deja el orden fijo entre dos usuarios con el mismo nombre.
     *
     * @param ?string      $texto  si viene, sólo los que lo tienen en el nombre o en el correo. El
     *                             nombre se compara sin distinguir mayúsculas ni tildes (la
     *                             intercalación de la columna); el correo, sólo si el texto es
     *                             ASCII, como la columna: un texto con tildes no puede estar en un
     *                             correo. Va como parámetro, con los comodines de LIKE escapados.
     * @param positive-int $numero
     * @param positive-int $tamano
     */
    public function pagina(?string $texto, int $numero, int $tamano): PaginaDeUsuarios
    {
        $texto = trim((string) $texto);
        $condicion = 'TRUE';
        $parametros = [];
        if ($texto !== '') {
            $condicion = "u.nombre LIKE ? ESCAPE '!'";
            $parametros = [self::contiene($texto)];
            if (mb_check_encoding($texto, 'ASCII')) {
                $condicion = "({$condicion} OR u.correo LIKE ? ESCAPE '!')";
                $parametros[] = self::contiene($texto);
            }
        }

        $total = (int) $this->base->valor("SELECT COUNT(*) FROM usuario u WHERE {$condicion}", $parametros);
        $pagina = new PaginaDeUsuarios([], $numero, $tamano, $total);
        // Una página que no existe no se consulta: así el OFFSET no se pasa de un entero.
        if ($total === 0 || !$pagina->existe()) {
            return $pagina;
        }

        // Los roles en la misma consulta: ordenar un ENUM sigue el orden en que se declaró, que es
        // el de Rol, de menor a mayor.
        $filas = $this->base->filas(
            "SELECT u.id, u.nombre, u.correo, u.estado, u.creado_en,
                    GROUP_CONCAT(r.tipo ORDER BY r.tipo SEPARATOR ',') AS roles
             FROM usuario u
             LEFT JOIN rol r ON r.usuario_id = u.id
             WHERE {$condicion}
             GROUP BY u.id
             ORDER BY u.nombre, u.correo
             LIMIT ? OFFSET ?",
            [...$parametros, $tamano, ($numero - 1) * $tamano],
        );

        return new PaginaDeUsuarios(array_map(self::usuarioEnListado(...), $filas), $numero, $tamano, $total);
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
     * El patrón de «contiene» para un texto del administrador, tal cual: sin esto, un «%» o un «_»
     * serían comodines. Es el mismo escape que usa programación (Like): «!» y no la barra, que
     * cambia de significado con el modo NO_BACKSLASH_ESCAPES de MySQL.
     */
    private static function contiene(string $texto): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto) . '%';
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function usuarioEnListado(array $fila): UsuarioEnListado
    {
        $roles = $fila['roles'] === null ? [] : explode(',', (string) $fila['roles']);

        return new UsuarioEnListado(
            (string) $fila['id'],
            (string) $fila['nombre'],
            (string) $fila['correo'],
            EstadoCuenta::from((string) $fila['estado']),
            array_map(static fn (string $rol): Rol => Rol::from($rol), $roles),
            new DateTimeImmutable((string) $fila['creado_en']),
        );
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
