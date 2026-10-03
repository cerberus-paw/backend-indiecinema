<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Modelo\SesionAbierta;
use IndieCinema\Cuentas\Modelo\SesionDeUsuario;
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
     * Una sesión nueva, con un identificador nuevo: nunca se reusa uno que ya tenía el navegador.
     * En la base queda sólo su huella.
     */
    public function abrir(string $usuarioId): SesionAbierta
    {
        $identificador = Sesion::nuevoIdentificador();
        $sesion = Sesion::nueva((string) Sesion::huellaDe($identificador), $usuarioId, self::ahora());
        $this->sesiones->agregar($sesion);

        return new SesionAbierta($identificador, $sesion);
    }

    /**
     * La sesión del identificador de la cookie con su usuario, si existe, sigue viva y la cuenta
     * está activa; si no, null. Una vencida se borra.
     *
     * Se llama en cada pedido del sitio: además de la consulta, sólo escribe para anotar el uso,
     * y como mucho una vez cada pocos minutos.
     */
    public function validar(?string $identificador): ?SesionDeUsuario
    {
        $huella = $identificador === null ? null : Sesion::huellaDe($identificador);
        $deUsuario = $huella === null ? null : $this->sesiones->buscar($huella);
        if ($deUsuario === null) {
            return null;
        }

        $sesion = $deUsuario->sesion;
        $ahora = self::ahora();
        if (!$sesion->sigueViva($ahora)) {
            $this->sesiones->borrar($sesion->huella);

            return null;
        }
        // Al suspender se borran las sesiones; esto cubre un cambio que no haya pasado por el
        // repositorio de usuarios.
        if ($deUsuario->estado !== EstadoCuenta::Activa || $deUsuario->rol() === null) {
            return null;
        }
        if ($sesion->hayQueAnotarUso($ahora)) {
            $this->sesiones->marcarUso($sesion->huella, $ahora);
        }

        return $deUsuario;
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
