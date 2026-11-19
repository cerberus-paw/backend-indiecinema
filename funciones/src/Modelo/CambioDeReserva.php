<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DateTimeImmutable;

/**
 * Una línea del historial de una reserva (cambio_reserva): de qué estado a cuál pasó, quién lo
 * hizo y con qué rol, por qué y cuándo (EF-18.6). Un cambio del sistema no tiene autor.
 */
final readonly class CambioDeReserva
{
    /**
     * @param string  $autorRol el de AutorDelCambio::rolEnElHistorial(): «espectador»,
     *                          «organizador», «administrador» o «sistema»
     * @param ?string $autorId  null si lo hizo el sistema
     */
    public function __construct(
        public EstadoReserva $anterior,
        public EstadoReserva $nuevo,
        public string $autorRol,
        public ?string $autorId,
        public ?string $motivo,
        public DateTimeImmutable $hechoEn,
    ) {
    }

    public function loHizoElSistema(): bool
    {
        return $this->autorRol === AutorDelCambio::SISTEMA;
    }
}
