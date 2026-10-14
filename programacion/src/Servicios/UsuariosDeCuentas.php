<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\UsuarioDeCuentas;
use RuntimeException;

/**
 * Los usuarios de cuentas, por su API interna (E2): quién mandó una solicitud de sala y darle el
 * rol de organizador al aprobarla. Programación no tiene usuarios: sólo guarda sus ids.
 */
final class UsuariosDeCuentas
{
    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * @return ?UsuarioDeCuentas null si no existe
     *
     * @throws ExcepcionHttp    503 si cuentas no responde a tiempo
     * @throws RuntimeException si responde algo que no es un usuario: el contrato se rompió
     */
    public function buscar(string $id): ?UsuarioDeCuentas
    {
        if (!Uuid::esValido($id)) {
            return null;
        }
        $datos = $this->cliente->get('cuentas', "/interno/usuarios/{$id}");

        return $datos === null ? null : self::usuario($datos);
    }

    /** Los roles que ya pueden publicar salas: se acumulan, como Rol::alcanza(). */
    private const ALCANZAN_ORGANIZADOR = ['organizador', 'moderador', 'administrador'];

    /**
     * Le da el rol de organizador. Si ya lo tiene, o uno mayor, cuentas no cambia nada, así que se
     * puede reintentar.
     *
     * @param string $administradorId quién aprobó la solicitud, para el registro de cuentas
     *
     * @throws ExcepcionHttp    503 si cuentas no responde a tiempo
     * @throws RuntimeException si el usuario no existe o responde otra cosa
     */
    public function darOrganizador(string $id, string $administradorId): void
    {
        $datos = $this->cliente->post('cuentas', "/interno/usuarios/{$id}/roles", [
            'rol' => 'organizador',
            'otorgado_por' => $administradorId,
        ]);
        if ($datos === null || array_intersect(self::ALCANZAN_ORGANIZADOR, self::usuario($datos)->roles) === []) {
            throw new RuntimeException("cuentas no le dio el rol de organizador al usuario {$id}.");
        }
    }

    /**
     * Lee la respuesta sin suponer nada: si falta un campo o tiene otro tipo, es que cuentas
     * cambió lo que manda, y mejor un error claro que un usuario a medias.
     */
    private static function usuario(mixed $datos): UsuarioDeCuentas
    {
        if (
            !is_array($datos)
            || !is_string($datos['id'] ?? null) || !is_string($datos['nombre'] ?? null)
            || !is_string($datos['correo'] ?? null) || !is_string($datos['estado'] ?? null)
            || !is_array($datos['roles'] ?? null) || !array_is_list($datos['roles'])
            || !is_string($datos['creado_en'] ?? null)
        ) {
            throw new RuntimeException('cuentas no respondió un usuario a /interno/usuarios/{id}.');
        }

        return new UsuarioDeCuentas(
            $datos['id'],
            $datos['nombre'],
            $datos['correo'],
            $datos['estado'],
            array_map(strval(...), $datos['roles']),
            new DateTimeImmutable($datos['creado_en'], new DateTimeZone('UTC')),
        );
    }
}
