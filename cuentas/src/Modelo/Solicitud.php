<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use DateTimeImmutable;

/**
 * Una solicitud para ser organizador, con su resolución si ya la tiene. Las fechas, en UTC.
 */
final readonly class Solicitud
{
    /**
     * @param ?string $motivo lo que escribió el administrador al resolverla; se le muestra al
     *                        solicitante
     */
    public function __construct(
        public string $id,
        public Solicitante $solicitante,
        public DatosDeSolicitud $datos,
        public EstadoSolicitud $estado,
        public DateTimeImmutable $enviadaEn,
        public ?string $motivo = null,
        public ?DateTimeImmutable $resueltaEn = null,
    ) {
    }
}
