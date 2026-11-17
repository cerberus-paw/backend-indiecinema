<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\UsuarioDeCuentas;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Los usuarios de cuentas, por su API interna (E2): quién mandó una solicitud de sala, darle el
 * rol de organizador al aprobarla y resolver nombres en lote para los bloqueos por sala (EF-12.1).
 * Programación no tiene usuarios: sólo guarda sus ids.
 */
final class UsuariosDeCuentas
{
    public function __construct(
        private readonly ClienteInterno $cliente,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * Nombres visibles de varios usuarios, en un solo pedido cada 100 ids (EF-12.1). Si cuentas
     * no responde o devuelve algo inesperado, devuelve los que pudo juntar y deja el aviso en el
     * log: la lista se muestra igual con el id cuando falta el nombre.
     *
     * @param list<string> $usuariosIds
     *
     * @return array<string, string> mapa de usuario_id => nombre
     */
    public function nombresDe(array $usuariosIds): array
    {
        $unicos = array_values(array_unique(array_filter($usuariosIds, Uuid::esValido(...))));
        if ($unicos === []) {
            return [];
        }

        $nombres = [];
        foreach (array_chunk($unicos, 100) as $lote) {
            try {
                $datos = $this->cliente->get('cuentas', '/interno/usuarios', ['ids' => implode(',', $lote)]);
            } catch (Throwable $error) {
                $this->log->warning('No se pudieron pedir los nombres de los usuarios bloqueados a cuentas.', ['error' => $error->getMessage()]);
                continue;
            }
            if (!is_array($datos)) {
                $this->log->warning('cuentas respondió algo distinto de una lista a /interno/usuarios.');
                continue;
            }
            foreach ($datos as $id => $usuario) {
                if (is_string($id) && is_array($usuario) && is_string($usuario['nombre'] ?? null)) {
                    $nombres[$id] = $usuario['nombre'];
                }
            }
        }

        return $nombres;
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
