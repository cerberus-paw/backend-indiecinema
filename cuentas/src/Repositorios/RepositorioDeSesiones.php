<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Nucleo\BaseDeDatos;

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

    public function buscar(string $huella): ?Sesion
    {
        $fila = $this->base->fila(
            'SELECT huella, usuario_id, creada_en, usada_en, vence_en FROM sesion WHERE huella = ?',
            [$huella],
        );

        return $fila === null ? null : new Sesion(
            (string) $fila['huella'],
            (string) $fila['usuario_id'],
            self::fecha($fila['creada_en']),
            self::fecha($fila['usada_en']),
            self::fecha($fila['vence_en']),
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
