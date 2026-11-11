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
     * Lo más que puede tener reservado una misma persona en una función, sumando todas sus
     * reservas confirmadas: alcanza para ir en grupo o en familia a una sala independiente sin
     * que una sola cuenta ocupe todo el cupo y después cancele. Si algún día lo fija el
     * organizador por función, pasa a ser un campo de programación.
     */
    public const MAXIMO_POR_PERSONA = 6;

    /**
     * Sin 0/O ni 1/I/L, que se confunden al dictar el código en la puerta o al tipearlo.
     */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const LARGO_DEL_CODIGO = 8;

    /**
     * @param string             $total         con dos decimales, como lo guarda la base: sin pasar por float
     * @param ?DateTimeImmutable $venceEn       hasta cuándo se puede pagar, en UTC; sólo las pendientes de pago
     * @param ?string            $preferenciaId la preferencia de Checkout Pro, una vez creada
     * @param ?string            $enlaceDePago  adónde va el espectador a pagarla (su init_point)
     * @param ?string            $pagoId        el pago de Mercado Pago que la confirmó
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
        public ?DateTimeImmutable $venceEn = null,
        public ?string $preferenciaId = null,
        public ?string $enlaceDePago = null,
        public ?string $pagoId = null,
    ) {
    }

    /**
     * Una reserva nueva, si la función todavía admite reservas. El total queda fijo con el precio
     * de ahora: si el organizador lo cambia después, la reserva no cambia.
     *
     * Con $minutosParaPagar (el cobro con Mercado Pago prendido), la de una función paga nace
     * pendiente de pago y vence en esos minutos; la de una a la gorra no tiene nada que pagar y
     * nace confirmada, como todas con el cobro apagado.
     *
     * @throws ReglaIncumplida si la cantidad no es positiva, o la función se canceló o ya empezó
     */
    public static function nueva(
        FuncionDeProgramacion $funcion,
        string $usuarioId,
        int $cantidad,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
        ?int $minutosParaPagar = null,
    ): self {
        if ($cantidad < 1) {
            throw new ReglaIncumplida('Reservá al menos un lugar.');
        }
        $motivo = $funcion->motivoParaNoReservar($ahora);
        if ($motivo !== null) {
            throw new ReglaIncumplida($motivo);
        }

        $total = number_format(round($funcion->precioBase * $cantidad, 2), 2, '.', '');
        // La base guarda las fechas en UTC (ver la migración).
        $creadaEn = $ahora->setTimezone(new DateTimeZone('UTC'));
        $sePaga = $minutosParaPagar !== null && $total !== '0.00';

        return new self(
            Uuid::nuevo(),
            $funcion->id,
            $usuarioId,
            self::nuevoCodigo(),
            $cantidad,
            $total,
            $sePaga ? EstadoReserva::PendientePago : EstadoReserva::Confirmada,
            $creadaEn,
            $sePaga ? $creadaEn->modify("+{$minutosParaPagar} minutes") : null,
        );
    }

    /**
     * Si el plazo para pagarla ya pasó: entonces ya no retiene sus lugares, aunque todavía no se
     * haya marcado como cancelada.
     */
    public function vencida(DateTimeImmutable $ahora = new DateTimeImmutable()): bool
    {
        return $this->estado === EstadoReserva::PendientePago && $this->venceEn !== null && $this->venceEn <= $ahora;
    }

    /**
     * La misma reserva con la preferencia de Checkout Pro que la cobra.
     */
    public function conPreferencia(string $preferenciaId, string $enlaceDePago): self
    {
        return new self($this->id, $this->funcionId, $this->usuarioId, $this->codigo, $this->cantidad, $this->total, $this->estado, $this->creadaEn, $this->venceEn, $preferenciaId, $enlaceDePago, $this->pagoId);
    }

    /**
     * La reserva cancelada, que suelta sus lugares. La entidad no cambia: el repositorio guarda el
     * cambio sólo si nadie la cambió antes (RepositorioDeReservas::guardarEstado()).
     *
     * @throws ReglaIncumplida si no está confirmada ni pendiente de pago
     */
    public function cancelar(): self
    {
        return $this->pasarA(EstadoReserva::Cancelada);
    }

    /**
     * La reserva pagada (X-01.4): la confirma el pago aprobado que avisó Mercado Pago, y queda
     * anotado cuál, para no procesarlo dos veces.
     *
     * @throws ReglaIncumplida si no está pendiente de pago
     */
    public function confirmarPago(string $pagoId): self
    {
        if ($this->estado !== EstadoReserva::PendientePago) {
            throw new ReglaIncumplida("Una reserva {$this->estado->etiqueta()} no espera ningún pago.");
        }
        $confirmada = $this->pasarA(EstadoReserva::Confirmada);

        return new self($confirmada->id, $confirmada->funcionId, $confirmada->usuarioId, $confirmada->codigo, $confirmada->cantidad, $confirmada->total, $confirmada->estado, $confirmada->creadaEn, $confirmada->venceEn, $confirmada->preferenciaId, $confirmada->enlaceDePago, $pagoId);
    }

    /**
     * La reserva de quien ya entró: el organizador la registra con el código en la puerta, o
     * después, si la función ya terminó y la reserva quedó ausente.
     *
     * @throws ReglaIncumplida si no está confirmada ni ausente
     */
    public function registrarAsistencia(): self
    {
        return $this->pasarA(EstadoReserva::Asistio);
    }

    /**
     * La reserva de quien no fue: la función terminó y nadie registró su asistencia (EF-04.1).
     *
     * @throws ReglaIncumplida si no está confirmada
     */
    public function marcarAusente(): self
    {
        return $this->pasarA(EstadoReserva::Ausente);
    }

    /**
     * La misma reserva con otro código, por si el sorteado ya era de otra.
     */
    public function conOtroCodigo(): self
    {
        return new self($this->id, $this->funcionId, $this->usuarioId, self::nuevoCodigo(), $this->cantidad, $this->total, $this->estado, $this->creadaEn, $this->venceEn, $this->preferenciaId, $this->enlaceDePago, $this->pagoId);
    }

    /**
     * @throws ReglaIncumplida si el ciclo de vida no lo permite
     */
    private function pasarA(EstadoReserva $siguiente): self
    {
        if (!$this->estado->puedePasarA($siguiente)) {
            throw new ReglaIncumplida("Una reserva {$this->estado->etiqueta()} no puede quedar {$siguiente->etiqueta()}.");
        }

        return new self($this->id, $this->funcionId, $this->usuarioId, $this->codigo, $this->cantidad, $this->total, $siguiente, $this->creadaEn, $this->venceEn, $this->preferenciaId, $this->enlaceDePago, $this->pagoId);
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
