<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\FiltrosDeUsuarios;
use IndieCinema\Cuentas\Modelo\PaginaDeUsuarios;
use IndieCinema\Cuentas\Modelo\ResumenDeUsuarios;
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
            'SELECT id, nombre, correo, contrasena_hash, estado, correo_verificado_en, verificacion_reenviada_en FROM usuario WHERE correo = ?',
            [$correo],
        );

        return $fila === null ? null : $this->usuarioDesdeFila($fila);
    }

    public function buscarPorId(string $id, bool $bloquear = false): ?Usuario
    {
        $bloqueo = $bloquear ? ' FOR UPDATE' : '';
        $fila = $this->base->fila(
            "SELECT id, nombre, correo, contrasena_hash, estado, correo_verificado_en, verificacion_reenviada_en FROM usuario WHERE id = ?{$bloqueo}",
            [$id],
        );

        return $fila === null ? null : $this->usuarioDesdeFila($fila);
    }

    /**
     * @param array<string, mixed> $fila
     */
    private function usuarioDesdeFila(array $fila): Usuario
    {
        $roles = $this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ?', [$fila['id']]);

        return new Usuario(
            (string) $fila['id'],
            (string) $fila['nombre'],
            (string) $fila['correo'],
            (string) $fila['contrasena_hash'],
            EstadoCuenta::from((string) $fila['estado']),
            array_map(static fn (array $rol): Rol => Rol::from((string) $rol['tipo']), $roles),
            $fila['correo_verificado_en'] === null
                ? null
                : new DateTimeImmutable((string) $fila['correo_verificado_en'], new DateTimeZone('UTC')),
            $fila['verificacion_reenviada_en'] === null
                ? null
                : new DateTimeImmutable((string) $fila['verificacion_reenviada_en'], new DateTimeZone('UTC')),
        );
    }

    /**
     * Un usuario como lo ve el administrador, con sus roles, o null si no existe.
     */
    public function buscarEnListado(string $id): ?UsuarioEnListado
    {
        $fila = $this->base->fila(
            "SELECT u.id, u.nombre, u.correo, u.estado, u.creado_en,
                    GROUP_CONCAT(r.tipo ORDER BY r.tipo SEPARATOR ',') AS roles
             FROM usuario u
             LEFT JOIN rol r ON r.usuario_id = u.id
             WHERE u.id = ?
             GROUP BY u.id",
            [$id],
        );

        return $fila === null ? null : self::usuarioEnListado($fila);
    }

    /**
     * Varios usuarios por su id, en una sola consulta, indexados por id. Los ids que no existen no
     * vienen en el arreglo.
     *
     * @param list<string> $ids UUID ya validados
     *
     * @return array<string, UsuarioEnListado>
     */
    public function buscarVariosEnListado(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marcadores = implode(', ', array_fill(0, count($ids), '?'));
        $filas = $this->base->filas(
            "SELECT u.id, u.nombre, u.correo, u.estado, u.creado_en,
                    GROUP_CONCAT(r.tipo ORDER BY r.tipo SEPARATOR ',') AS roles
             FROM usuario u
             LEFT JOIN rol r ON r.usuario_id = u.id
             WHERE u.id IN ({$marcadores})
             GROUP BY u.id",
            $ids,
        );
        $usuarios = [];
        foreach ($filas as $fila) {
            $usuario = self::usuarioEnListado($fila);
            $usuarios[$usuario->id] = $usuario;
        }

        return $usuarios;
    }

    /**
     * Una página del listado del administrador, por nombre y después por correo, que es único y
     * deja el orden fijo entre dos usuarios con el mismo nombre.
     *
     * - El texto, en el nombre o en el correo. El nombre se compara sin distinguir mayúsculas ni
     *   tildes (la intercalación de la columna); el correo, sólo si el texto es ASCII, como la
     *   columna: un texto con tildes no puede estar en un correo. Va como parámetro, con los
     *   comodines de LIKE escapados.
     * - El rol es el mayor de cada usuario, el que muestra la tabla: los roles se acumulan, y un
     *   organizador también es espectador, pero filtrar por espectador trae a los que son sólo eso.
     *
     * @param positive-int $numero
     * @param positive-int $tamano
     */
    public function pagina(FiltrosDeUsuarios $filtros, int $numero, int $tamano): PaginaDeUsuarios
    {
        $condiciones = ['TRUE'];
        $parametros = [];
        $texto = trim((string) $filtros->texto);
        if ($texto !== '') {
            $ascii = mb_check_encoding($texto, 'ASCII');
            $condiciones[] = $ascii ? "(u.nombre LIKE ? ESCAPE '!' OR u.correo LIKE ? ESCAPE '!')" : "u.nombre LIKE ? ESCAPE '!'";
            array_push($parametros, ...array_fill(0, $ascii ? 2 : 1, self::contiene($texto)));
        }
        if ($filtros->rol !== null) {
            $condiciones[] = 'EXISTS (SELECT 1 FROM rol WHERE rol.usuario_id = u.id AND rol.tipo = ?)';
            $parametros[] = $filtros->rol->value;
            $mayores = array_filter(Rol::cases(), static fn (Rol $rol): bool => $rol->alcanza($filtros->rol) && $rol !== $filtros->rol);
            if ($mayores !== []) {
                $condiciones[] = 'NOT EXISTS (SELECT 1 FROM rol WHERE rol.usuario_id = u.id AND rol.tipo IN ('
                    . implode(', ', array_fill(0, count($mayores), '?')) . '))';
                array_push($parametros, ...array_map(static fn (Rol $rol): string => $rol->value, array_values($mayores)));
            }
        }
        if ($filtros->estado !== null) {
            $condiciones[] = 'u.estado = ?';
            $parametros[] = $filtros->estado->value;
        }
        if ($filtros->alta !== null) {
            // Un rango y no YEAR(creado_en): así la condición puede usar un índice de la columna.
            $condiciones[] = 'u.creado_en >= ? AND u.creado_en < ?';
            array_push($parametros, sprintf('%04d-01-01', $filtros->alta), sprintf('%04d-01-01', $filtros->alta + 1));
        }
        $condicion = implode(' AND ', $condiciones);

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
     * Los años en que se creó alguna cuenta, del más nuevo al más viejo: las opciones del filtro
     * de alta.
     *
     * @return list<int>
     */
    public function aniosDeAlta(): array
    {
        return array_map(
            static fn (array $fila): int => (int) $fila['anio'],
            $this->base->filas('SELECT DISTINCT YEAR(creado_en) AS anio FROM usuario ORDER BY anio DESC'),
        );
    }

    /**
     * Los números de las tarjetas de «Usuarios y roles». «Este mes» sale del reloj de la base, el
     * mismo que pone usuario.creado_en.
     */
    public function resumen(): ResumenDeUsuarios
    {
        $cuentas = $this->base->fila(
            "SELECT COUNT(*) AS usuarios,
                    COUNT(CASE WHEN creado_en >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN 1 END) AS nuevos
             FROM usuario",
        );
        $organizadores = $this->base->valor(
            "SELECT COUNT(*) FROM usuario u
             WHERE u.estado = 'activa'
               AND EXISTS (SELECT 1 FROM rol WHERE rol.usuario_id = u.id AND rol.tipo = 'organizador')",
        );

        return new ResumenDeUsuarios((int) ($cuentas['usuarios'] ?? 0), (int) ($cuentas['nuevos'] ?? 0), (int) $organizadores);
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
                    'INSERT INTO usuario (id, nombre, correo, contrasena_hash, estado, correo_verificado_en) VALUES (?, ?, ?, ?, ?, ?)',
                    [$usuario->id, $usuario->nombre, $usuario->correo, $usuario->contrasenaHash, $usuario->estado->value, $usuario->correoVerificadoEn],
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

    public function marcarCorreoVerificado(string $usuarioId, DateTimeImmutable $cuando): void
    {
        $this->base->ejecutar(
            'UPDATE usuario SET correo_verificado_en = COALESCE(correo_verificado_en, ?) WHERE id = ?',
            [$cuando, $usuarioId],
        );
    }

    public function marcarVerificacionReenviada(string $usuarioId, DateTimeImmutable $cuando): void
    {
        $this->base->ejecutar(
            'UPDATE usuario SET verificacion_reenviada_en = ? WHERE id = ?',
            [$cuando, $usuarioId],
        );
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
     * Le da y le quita varios roles juntos, en la misma transacción que cierra sus sesiones: así
     * pasar de moderador a organizador no deja un momento sin ninguno de los dos.
     *
     * @param list<Rol> $agregar
     * @param list<Rol> $quitar
     */
    public function cambiarRoles(string $usuarioId, array $agregar, array $quitar): void
    {
        $this->cerrandoSesiones($usuarioId, function (BaseDeDatos $base) use ($usuarioId, $agregar, $quitar): void {
            foreach ($quitar as $rol) {
                $base->ejecutar('DELETE FROM rol WHERE usuario_id = ? AND tipo = ?', [$usuarioId, $rol->value]);
            }
            foreach ($agregar as $rol) {
                $base->ejecutar('INSERT INTO rol (usuario_id, tipo) VALUES (?, ?)', [$usuarioId, $rol->value]);
            }
        });
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
     * Suspende o reactiva desde «Usuarios y roles» (EF-14.3): cambia el estado sólo si sigue siendo
     * $anterior, cierra sus sesiones y deja quién, cuándo y por qué, todo en una transacción. Si
     * otro administrador ya lo cambió (dos pestañas, o reenviar el POST), no hace nada.
     *
     * @return bool si lo cambió
     */
    public function cambiarEstadoRegistrado(
        string $usuarioId,
        EstadoCuenta $anterior,
        EstadoCuenta $nuevo,
        ?string $motivo,
        string $autorId,
        DateTimeImmutable $cuando,
    ): bool {
        return $this->base->enTransaccion(function (BaseDeDatos $base) use ($usuarioId, $anterior, $nuevo, $motivo, $autorId, $cuando): bool {
            if ($base->ejecutar('UPDATE usuario SET estado = ? WHERE id = ? AND estado = ?', [$nuevo->value, $usuarioId, $anterior->value]) === 0) {
                return false;
            }
            $base->ejecutar(
                'INSERT INTO cambio_estado_cuenta (usuario_id, estado_anterior, estado_nuevo, motivo, autor_id, creado_en) VALUES (?, ?, ?, ?, ?, ?)',
                [$usuarioId, $anterior->value, $nuevo->value, $motivo, $autorId, $cuando],
            );
            $base->ejecutar('DELETE FROM sesion WHERE usuario_id = ?', [$usuarioId]);

            return true;
        });
    }

    /**
     * La última suspensión de cada cuenta suspendida de la lista: el motivo y el estado al que
     * vuelve si se reactiva. Una cuenta suspendida sin registro (la de los datos de prueba, o la
     * que se suspendió antes de EF-14.3) no viene.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{motivo: ?string, anterior: EstadoCuenta, desde: DateTimeImmutable}>
     */
    public function suspensiones(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        // La última fila de cada uno: la que no tiene otra más nueva (de id mayor) del mismo usuario.
        $filas = $this->base->filas(
            "SELECT c.usuario_id, c.motivo, c.estado_anterior, c.creado_en
             FROM cambio_estado_cuenta c
             JOIN usuario u ON u.id = c.usuario_id AND u.estado = 'suspendida'
             WHERE c.usuario_id IN ({$marcas}) AND c.estado_nuevo = 'suspendida'
               AND NOT EXISTS (
                   SELECT 1 FROM cambio_estado_cuenta n
                   WHERE n.usuario_id = c.usuario_id AND n.id > c.id
               )",
            $ids,
        );
        $suspensiones = [];
        foreach ($filas as $fila) {
            $suspensiones[(string) $fila['usuario_id']] = [
                'motivo' => $fila['motivo'] === null ? null : (string) $fila['motivo'],
                'anterior' => EstadoCuenta::from((string) $fila['estado_anterior']),
                'desde' => new DateTimeImmutable((string) $fila['creado_en'], new DateTimeZone('UTC')),
            ];
        }

        return $suspensiones;
    }

    public function cambiarContrasena(string $usuarioId, string $contrasenaHash): void
    {
        $this->cerrandoSesiones($usuarioId, fn (BaseDeDatos $base) => $base->ejecutar(
            'UPDATE usuario SET contrasena_hash = ? WHERE id = ?',
            [$contrasenaHash, $usuarioId],
        ));
    }

    /**
     * Actualiza la contraseña dejando abierta sólo la sesión indicada en $exceptoHuella, o
     * ninguna si no se informa.
     */
    public function actualizarContrasena(string $usuarioId, string $contrasenaHash): void
    {
        $this->base->ejecutar(
            'UPDATE usuario SET contrasena_hash = ? WHERE id = ?',
            [$contrasenaHash, $usuarioId],
        );
    }

    /**
     * @throws CorreoYaRegistrado si otro usuario ya usa ese correo
     */
    public function actualizarPerfil(string $usuarioId, string $nombre, string $correo, bool $reiniciarVerificacion): void
    {
        try {
            if ($reiniciarVerificacion) {
                $this->base->ejecutar(
                    'UPDATE usuario SET nombre = ?, correo = ?, correo_verificado_en = NULL, verificacion_reenviada_en = NULL WHERE id = ?',
                    [$nombre, $correo, $usuarioId],
                );
            } else {
                $this->base->ejecutar(
                    'UPDATE usuario SET nombre = ?, correo = ? WHERE id = ?',
                    [$nombre, $correo, $usuarioId],
                );
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                throw new CorreoYaRegistrado('Ya hay una cuenta con este correo.', previous: $error);
            }
            throw $error;
        }
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
