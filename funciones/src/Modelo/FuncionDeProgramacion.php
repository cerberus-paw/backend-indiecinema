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
    /** Los estados de programación en los que la función sigue abierta: en votación, en acuerdo o programada. */
    private const EN_CURSO = ['en_votacion_titulos', 'en_acuerdo_fecha', 'programada'];

    /**
     * @param string             $estado          el de programación (en_votacion_titulos,
     *                                            en_acuerdo_fecha, programada, cancelada o
     *                                            finalizada)
     * @param ?DateTimeImmutable $fechaHora       la hora de la sala; null mientras no tiene fecha
     * @param list<string>       $titulos         las elegidas o, en votación, las candidatas
     * @param bool               $titulosElegidos si $titulos ya son las que se proyectan
     * @param list<string>       $obrasIds        las mismas obras, por id: la calificación las copia
     *                                            (EF-04.2)
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
        public array $obrasIds = [],
    ) {
    }

    /**
     * Si sigue abierta: no se canceló ni terminó, y su sala está publicada.
     */
    public function estaEnCurso(): bool
    {
        return $this->salaHabilitada && in_array($this->estado, self::EN_CURSO, true);
    }

    /**
     * Por qué no se puede reservar, para decírselo al espectador; null si se puede. Se puede sólo una
     * programada que todavía no empezó (F-01.2). Hasta la E4-01 también se podía en votación de
     * títulos o en acuerdo de fecha, «aunque todavía no tenga fecha»: se reservaba una función que
     * quizás nunca se daba, o se daba con otra película o en otro horario, y por la API quedaba
     * abierto aunque la ficha no lo ofreciera.
     */
    public function motivoParaNoReservar(DateTimeImmutable $ahora = new DateTimeImmutable()): ?string
    {
        if ($this->estado === 'cancelada') {
            return 'La función se canceló: ya no se puede reservar.';
        }
        if ($this->salaHabilitada && in_array($this->estado, ['en_votacion_titulos', 'en_acuerdo_fecha'], true)) {
            return 'La función todavía no tiene fecha confirmada: se va a poder reservar cuando se programe.';
        }
        // Una programada cuya hora ya pasó ya empezó, aunque nada la haya pasado a finalizada.
        if ($this->estado !== 'programada' || !$this->salaHabilitada || $this->fechaHora === null || $this->fechaHora <= $ahora) {
            return 'La función ya empezó: ya no se puede reservar.';
        }

        return null;
    }

    /**
     * Las obras juntas, como las nombra la cartelera.
     */
    public function titulo(): string
    {
        return implode(' + ', $this->titulos);
    }

    public function esDe(string $usuarioId): bool
    {
        return hash_equals($this->organizadorId, $usuarioId);
    }
}
