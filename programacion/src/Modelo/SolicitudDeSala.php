<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * El trámite con el que un usuario pide abrir una sala (E2: SolicitudSala), con la sala tal como
 * está: si la rechazan, la corrige y la vuelve a mandar, y esa es una solicitud nueva.
 */
final readonly class SolicitudDeSala
{
    /**
     * @param ?string $solicitanteNombre el nombre al mandarla; null en las que se cargaron al crear
     *                                   la tabla, de salas que ya esperaban
     */
    public function __construct(
        public string $id,
        public Sala $sala,
        public string $usuarioId,
        public ?string $solicitanteNombre,
        public EstadoSolicitud $estado,
        public DateTimeImmutable $enviadaEn,
        public ?DateTimeImmutable $resueltaEn = null,
        public ?string $motivo = null,
    ) {
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoSolicitud::Pendiente;
    }
}
