<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use PDOException;

final class RepositorioDeReservas
{
    private const CLAVE_DUPLICADA = 1062;

    /**
     * Cuánto espera una reserva a que termine otra de la misma función. Cada una tarda
     * milisegundos: si pasan estos segundos, algo anda mal y es mejor un 503 que colgar la página.
     */
    private const ESPERA_DEL_BLOQUEO = 5;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Corre $trabajo en una transacción, con las reservas de la función bloqueadas: dos reservas
     * de la misma función van una después de la otra, así ninguna cuenta los lugares mientras la
     * otra está por ocupar el último.
     *
     * Es un bloqueo con nombre (GET_LOCK) y no un SELECT … FOR UPDATE: el cupo vive en la función,
     * que es de programación, y en este esquema no hay una fila por función que bloquear. Funciones
     * de distinto id no se esperan entre sí.
     *
     * @template T
     *
     * @param callable(BaseDeDatos): T $trabajo
     *
     * @return T
     *
     * @throws ExcepcionHttp 503 si otra reserva no suelta la función a tiempo
     */
    public function conLaFuncionBloqueada(string $funcionId, callable $trabajo): mixed
    {
        // Hasta 64 caracteres: con el prefijo y el UUID son 54.
        $bloqueo = "funciones.reserva.{$funcionId}";
        if ($this->base->valor('SELECT GET_LOCK(?, ?)', [$bloqueo, self::ESPERA_DEL_BLOQUEO]) !== 1) {
            throw ExcepcionHttp::servicioNoDisponible();
        }
        try {
            // La transacción se confirma antes de soltar el bloqueo: la reserva que espera ya ve
            // los lugares que ocupó esta.
            return $this->base->enTransaccion($trabajo);
        } finally {
            $this->base->valor('SELECT RELEASE_LOCK(?)', [$bloqueo]);
        }
    }

    /**
     * Los lugares que ocupan las reservas de la función: las confirmadas y las de quienes ya
     * entraron (EstadoReserva::ocupaLugares()).
     */
    public function lugaresOcupados(string $funcionId): int
    {
        $ocupan = array_values(array_filter(EstadoReserva::cases(), static fn (EstadoReserva $estado): bool => $estado->ocupaLugares()));

        return (int) $this->base->valor(
            'SELECT COALESCE(SUM(cantidad), 0) FROM reserva WHERE funcion_id = ? AND estado IN (' . implode(', ', array_fill(0, count($ocupan), '?')) . ')',
            [$funcionId, ...$ocupan],
        );
    }

    public function buscar(string $id): ?Reserva
    {
        $fila = $this->base->fila('SELECT * FROM reserva WHERE id = ?', [$id]);

        return $fila === null ? null : new Reserva(
            $fila['id'],
            $fila['funcion_id'],
            $fila['usuario_id'],
            $fila['codigo'],
            $fila['cantidad'],
            $fila['total'],
            EstadoReserva::from($fila['estado']),
            new DateTimeImmutable($fila['creada_en'], new DateTimeZone('UTC')),
        );
    }

    /**
     * Guarda el estado nuevo de la reserva, si todavía está en $anterior. Así, si el espectador
     * cancela mientras el organizador registra su asistencia, gana el primero y el otro se
     * entera, en lugar de pisarse.
     *
     * @return bool false si otro la cambió antes, y entonces no guardó nada
     */
    public function guardarEstado(Reserva $reserva, EstadoReserva $anterior): bool
    {
        return $this->base->ejecutar(
            'UPDATE reserva SET estado = ? WHERE id = ? AND estado = ?',
            [$reserva->estado, $reserva->id, $anterior],
        ) === 1;
    }

    /**
     * @return bool false si el código ya es de otra reserva, y entonces no guardó nada
     */
    public function agregar(Reserva $reserva): bool
    {
        try {
            $this->base->ejecutar(
                'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$reserva->id, $reserva->funcionId, $reserva->usuarioId, $reserva->codigo, $reserva->cantidad, $reserva->total, $reserva->estado, $reserva->creadaEn],
            );

            return true;
        } catch (PDOException $error) {
            // El id es un UUID nuevo: lo único que se puede repetir es el código.
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }
            throw $error;
        }
    }
}
