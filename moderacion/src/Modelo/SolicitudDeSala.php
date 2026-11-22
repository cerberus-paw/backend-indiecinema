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
     * @param bool    $cambio            si pide cambiar la dirección de una sala ya publicada
     *                                   (EF-07.6), en lugar de abrir una
     * @param ?Devolucion $devolucion    la última vez que se la devolvieron al solicitante
     *                                   (EF-22.10); null si nunca
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
        public bool $cambio = false,
        public ?Devolucion $devolucion = null,
    ) {
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoSolicitud::Pendiente;
    }

    /**
     * Pendiente, en revisión manual o devuelta: todavía no terminó.
     */
    public function estaAbierta(): bool
    {
        return in_array($this->estado, EstadoSolicitud::abiertas(), true);
    }

    /**
     * Pendiente o en revisión manual: espera que el moderador la resuelva.
     */
    public function estaPorResolver(): bool
    {
        return in_array($this->estado, EstadoSolicitud::porResolver(), true);
    }
}
