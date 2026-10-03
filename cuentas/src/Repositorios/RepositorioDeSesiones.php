<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Modelo\SesionDeUsuario;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Seguridad\Rol;

/**
 * Las sesiones en el esquema de cuentas. Las fechas van y vienen en UTC, como las escribe la
 * aplicación.
 */
final class RepositorioDeSesiones
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function agregar(Sesion $sesion): void
    {
        $this->base->ejecutar(
            'INSERT INTO sesion (huella, usuario_id, creada_en, usada_en, vence_en) VALUES (?, ?, ?, ?, ?)',
            [$sesion->huella, $sesion->usuarioId, $sesion->creadaEn, $sesion->usadaEn, $sesion->venceEn],
        );
    }

    /**
     * La sesión con el nombre, el estado y los roles de su usuario, en una sola consulta: se hace
     * en cada pedido del sitio. Va por la clave primaria de sesion y de usuario y por la de rol,
     * que empieza por usuario_id.
     */
    public function buscar(string $huella): ?SesionDeUsuario
    {
        $fila = $this->base->fila(
            "SELECT sesion.huella, sesion.usuario_id, sesion.creada_en, sesion.usada_en, sesion.vence_en,
                usuario.nombre, usuario.estado, GROUP_CONCAT(rol.tipo SEPARATOR ',') AS roles
             FROM sesion
             JOIN usuario ON usuario.id = sesion.usuario_id
             LEFT JOIN rol ON rol.usuario_id = sesion.usuario_id
             WHERE sesion.huella = ?
             GROUP BY sesion.huella",
            [$huella],
        );
        if ($fila === null) {
            return null;
        }

        return new SesionDeUsuario(
            new Sesion(
                (string) $fila['huella'],
                (string) $fila['usuario_id'],
                self::fecha($fila['creada_en']),
                self::fecha($fila['usada_en']),
                self::fecha($fila['vence_en']),
            ),
            (string) $fila['nombre'],
            EstadoCuenta::from((string) $fila['estado']),
            array_map(Rol::from(...), array_values(array_filter(explode(',', (string) $fila['roles'])))),
        );
    }

    public function marcarUso(string $huella, DateTimeImmutable $ahora): void
    {
        $this->base->ejecutar('UPDATE sesion SET usada_en = ? WHERE huella = ?', [$ahora, $huella]);
    }

    public function borrar(string $huella): void
    {
        $this->base->ejecutar('DELETE FROM sesion WHERE huella = ?', [$huella]);
    }

    private static function fecha(mixed $valor): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $valor, new DateTimeZone('UTC'));
    }
}
