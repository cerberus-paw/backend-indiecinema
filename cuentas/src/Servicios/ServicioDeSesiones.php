<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;

/**
 * Abrir, validar y cerrar sesiones. La sesión vive en la base: borrar la fila la cierra en el
 * pedido siguiente, sin esperar al vencimiento.
 */
final class ServicioDeSesiones
{
    public function __construct(private readonly RepositorioDeSesiones $sesiones)
    {
    }

    /**
     * @return string el identificador para la cookie; en la base queda sólo su huella
     */
    public function abrir(string $usuarioId): string
    {
        $identificador = Sesion::nuevoIdentificador();
        $this->sesiones->agregar(Sesion::nueva((string) Sesion::huellaDe($identificador), $usuarioId, self::ahora()));

        return $identificador;
    }

    /**
     * La sesión del identificador de la cookie, si existe y sigue viva; si no, null. Una vencida
     * se borra, y cada uso queda anotado.
     */
    public function validar(?string $identificador): ?Sesion
    {
        $huella = $identificador === null ? null : Sesion::huellaDe($identificador);
        $sesion = $huella === null ? null : $this->sesiones->buscar($huella);
        if ($sesion === null) {
            return null;
        }

        $ahora = self::ahora();
        if (!$sesion->sigueViva($ahora)) {
            $this->sesiones->borrar($sesion->huella);

            return null;
        }
        $this->sesiones->marcarUso($sesion->huella, $ahora);

        return $sesion;
    }

    public function cerrar(string $identificador): void
    {
        $huella = Sesion::huellaDe($identificador);
        if ($huella !== null) {
            $this->sesiones->borrar($huella);
        }
    }

    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
