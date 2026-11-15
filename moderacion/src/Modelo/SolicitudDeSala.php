<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

use DateTimeImmutable;

/**
 * El trámite con el que un usuario pide abrir una sala (E2: SolicitudSala), con la sala que
 * propone: si la rechazan, la corrige y la vuelve a mandar, y esa es una solicitud nueva de la
 * misma sala (el mismo salaId). La sala recién existe en programación cuando se aprueba (EF-07.2).
 */
final readonly class SolicitudDeSala
{
    /**
     * @param ?string $solicitanteNombre el nombre al mandarla; null en las que se cargaron al crear
     *                                   la tabla en programación, de salas que ya esperaban
     * @param ?string $imagen            la foto en el volumen de moderación; null en las que se
     *                                   mudaron de programación, cuya foto quedó en su sala
     * @param ?string $revisionMotivo    por qué pasó a revisión manual (EF-07.6), aparte del motivo
     *                                   de la resolución; null si no pasó
     */
    public function __construct(
        public string $id,
        public string $salaId,
        public string $usuarioId,
        public ?string $solicitanteNombre,
        public DatosDeSala $datos,
        public ?string $imagen,
        public EstadoSolicitud $estado,
        public DateTimeImmutable $enviadaEn,
        public ?DateTimeImmutable $resueltaEn = null,
        public ?string $motivo = null,
        public ?string $revisionMotivo = null,
        public ?DateTimeImmutable $revisionEn = null,
    ) {
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoSolicitud::Pendiente;
    }

    /**
     * Pendiente o en revisión manual: espera que la aprueben o la rechacen.
     */
    public function estaAbierta(): bool
    {
        return in_array($this->estado, EstadoSolicitud::abiertas(), true);
    }
}
