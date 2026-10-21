<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\Uuid;

/**
 * La reserva de lugares de un espectador en una función (E2, modelo de objetos de funciones:
 * Reserva). Que no supere el cupo no lo decide la reserva sola, que no ve las demás: lo controla
 * ServicioDeReservas con la función bloqueada.
 */
final readonly class Reserva
{
    /**
     * Sin 0/O ni 1/I/L, que se confunden al dictar el código en la puerta o al tipearlo.
     */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const LARGO_DEL_CODIGO = 8;

    /**
     * @param string $total con dos decimales, como lo guarda la base: sin pasar por float
     */
    public function __construct(
        public string $id,
        public string $funcionId,
        public string $usuarioId,
        public string $codigo,
        public int $cantidad,
        public string $total,
        public EstadoReserva $estado,
        public DateTimeImmutable $creadaEn,
    ) {
    }

    /**
     * Una reserva confirmada, si la función todavía admite reservas. El total queda fijo con el
     * precio de ahora: si el organizador lo cambia después, la reserva no cambia.
     *
     * @throws ReglaIncumplida si la cantidad no es positiva, o la función se canceló o ya empezó
     */
    public static function nueva(
        FuncionDeProgramacion $funcion,
        string $usuarioId,
        int $cantidad,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): self {
        if ($cantidad < 1) {
            throw new ReglaIncumplida('Reservá al menos un lugar.');
        }
        if ($funcion->estado === 'cancelada') {
            throw new ReglaIncumplida('La función se canceló: ya no se puede reservar.');
        }
        // Una programada cuya hora ya pasó ya empezó, aunque nada la haya pasado a finalizada.
        if (!$funcion->estaEnCurso() || ($funcion->fechaHora !== null && $funcion->fechaHora <= $ahora)) {
            throw new ReglaIncumplida('La función ya empezó: ya no se puede reservar.');
        }

        return new self(
            Uuid::nuevo(),
            $funcion->id,
            $usuarioId,
            self::nuevoCodigo(),
            $cantidad,
            number_format(round($funcion->precioBase * $cantidad, 2), 2, '.', ''),
            EstadoReserva::Confirmada,
            // La base guarda las fechas en UTC (ver la migración).
            $ahora->setTimezone(new DateTimeZone('UTC')),
        );
    }

    /**
     * La misma reserva con otro código, por si el sorteado ya era de otra.
     */
    public function conOtroCodigo(): self
    {
        return new self($this->id, $this->funcionId, $this->usuarioId, self::nuevoCodigo(), $this->cantidad, $this->total, $this->estado, $this->creadaEn);
    }

    /**
     * 31 caracteres en 8 posiciones son casi 10¹² códigos: que se repita es muy raro, y si pasa,
     * la base lo rechaza y se sortea otro.
     */
    private static function nuevoCodigo(): string
    {
        $codigo = '';
        for ($i = 0; $i < self::LARGO_DEL_CODIGO; ++$i) {
            $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $codigo;
    }
}
