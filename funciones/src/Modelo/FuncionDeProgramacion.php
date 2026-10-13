<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DateTimeImmutable;

/**
 * Una función como la cuenta programación por la API interna: funciones no tiene acceso a ese
 * esquema (E2, sección 4), así que la pide cada vez que la necesita, para el acuerdo de fecha o
 * para una reserva.
 */
final readonly class FuncionDeProgramacion
{
    /** Los estados de programación en los que todavía se puede reservar o acordar la fecha. */
    private const EN_CURSO = ['en_votacion_titulos', 'en_acuerdo_fecha', 'programada'];

    /**
     * @param string             $estado          el de programación (en_votacion_titulos,
     *                                            en_acuerdo_fecha, programada, cancelada o
     *                                            finalizada)
     * @param ?DateTimeImmutable $fechaHora       la hora de la sala; null mientras no tiene fecha
     * @param list<string>       $titulos         las elegidas o, en votación, las candidatas
     * @param bool               $titulosElegidos si $titulos ya son las que se proyectan
     */
    public function __construct(
        public string $id,
        public string $estado,
        public ?DateTimeImmutable $fechaHora,
        public float $precioBase,
        public int $cupo,
        public array $titulos,
        public bool $titulosElegidos,
        public string $salaId,
        public string $salaNombre,
        public string $salaLocalidad,
        public bool $salaHabilitada,
        public string $organizadorId,
    ) {
    }

    /**
     * Si sigue abierta: no se canceló ni terminó, y su sala está publicada.
     */
    public function estaEnCurso(): bool
    {
        return $this->salaHabilitada && in_array($this->estado, self::EN_CURSO, true);
    }

    public function esDe(string $usuarioId): bool
    {
        return hash_equals($this->organizadorId, $usuarioId);
    }
}
