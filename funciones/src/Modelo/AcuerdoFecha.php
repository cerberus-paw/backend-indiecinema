<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DateTimeImmutable;

/**
 * El acuerdo de fecha con los espectadores para una función (E2 / E3-13).
 */
final class AcuerdoFecha
{
    /**
     * Cuánto tiene el organizador para proponer otras franjas después de cerrar el acuerdo sin
     * mínimo antes de la fecha límite: pasado, la tarea de programación cancela la función (F-03.4).
     */
    public const PLAZO_PARA_PROPONER_OTRAS = '+7 days';

    /**
     * @param list<FranjaHoraria> $franjas
     */
    public function __construct(
        public readonly string $id,
        public readonly string $funcionId,
        public readonly int $minimoAsistentes,
        public readonly EstadoAcuerdo $estado = EstadoAcuerdo::Abierto,
        public readonly ?string $franjaGanadoraId = null,
        public readonly ?DateTimeImmutable $creadoEn = null,
        public readonly ?DateTimeImmutable $cerradoEn = null,
        public readonly array $franjas = [],
    ) {
    }

    public function estaAbierto(): bool
    {
        return $this->estado->estaAbierto();
    }

    /**
     * Devuelve la franja con más votos acumulados, o null si no hay franjas.
     */
    public function franjaMasVotada(): ?FranjaHoraria
    {
        if ($this->franjas === []) {
            return null;
        }

        $max = null;
        foreach ($this->franjas as $franja) {
            if ($max === null || $franja->votos > $max->votos) {
                $max = $franja;
            }
        }

        return $max;
    }

    /**
     * Indica si alguna franja alcanzó o superó el mínimo de asistentes requerido.
     */
    public function alcanzoMinimo(): bool
    {
        $lider = $this->franjaMasVotada();

        return $lider !== null && $lider->votos >= $this->minimoAsistentes;
    }

    /**
     * Hasta cuándo se marca «Me sirve»: las 23:59 del día anterior a la primera franja, como en
     * el wireframe (franjas del 18 al 20 de septiembre, fecha límite el 17 a las 23:59). Se
     * calcula y no se guarda: depende sólo de las franjas, que no cambian (E3-13.F8).
     */
    public function fechaLimite(): ?DateTimeImmutable
    {
        $primera = null;
        foreach ($this->franjas as $franja) {
            if ($primera === null || $franja->inicio < $primera) {
                $primera = $franja->inicio;
            }
        }

        return $primera?->modify('-1 day')->setTime(23, 59, 59);
    }

    /**
     * Si todavía se puede marcar «Me sirve»: el acuerdo está abierto y no pasó la fecha límite.
     */
    public function votacionAbierta(DateTimeImmutable $ahora): bool
    {
        $limite = $this->fechaLimite();

        return $this->estaAbierto() && ($limite === null || $ahora <= $limite);
    }

    /**
     * Si pasó la fecha límite sin que ninguna franja llegara al mínimo: el acuerdo se tiene que
     * cerrar sin mínimo. Con el mínimo alcanzado sigue abierto, para que el organizador confirme.
     */
    public function vencioSinMinimo(DateTimeImmutable $ahora): bool
    {
        return $this->estaAbierto() && !$this->votacionAbierta($ahora) && !$this->alcanzoMinimo();
    }

    /**
     * Cuándo se cancela la función si el organizador no propone otras franjas: a los 7 días de
     * cerrarlo sin mínimo, sólo si lo cerró a mano antes de la fecha límite (F-03.4). El que se
     * cerró al vencer (al leerlo pasada la fecha límite) no tiene plazo: lo cancela la tarea en la
     * próxima pasada. Null también si está abierto, confirmado o cancelado.
     */
    public function seCancelaEl(): ?DateTimeImmutable
    {
        $limite = $this->fechaLimite();
        if ($this->estado !== EstadoAcuerdo::SinMinimo || $this->cerradoEn === null || $limite === null || $this->cerradoEn > $limite) {
            return null;
        }

        return $this->cerradoEn->modify(self::PLAZO_PARA_PROPONER_OTRAS);
    }

    public function franjaGanadora(): ?FranjaHoraria
    {
        if ($this->franjaGanadoraId === null) {
            return null;
        }

        foreach ($this->franjas as $franja) {
            if ($franja->id === $this->franjaGanadoraId) {
                return $franja;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function paraJson(): array
    {
        return [
            'id' => $this->id,
            'funcion_id' => $this->funcionId,
            'minimo_asistentes' => $this->minimoAsistentes,
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'franja_ganadora_id' => $this->franjaGanadoraId,
            'franja_ganadora' => $this->franjaGanadora()?->paraJson(),
            'alcanzo_minimo' => $this->alcanzoMinimo(),
            'fecha_limite' => $this->fechaLimite()?->format('Y-m-d H:i:s'),
            'votacion_abierta' => $this->votacionAbierta(new DateTimeImmutable()),
            'creado_en' => $this->creadoEn?->format('Y-m-d H:i:s'),
            'cerrado_en' => $this->cerradoEn?->format('Y-m-d H:i:s'),
            'se_cancela_el' => $this->seCancelaEl()?->format('Y-m-d H:i:s'),
            'franjas' => array_map(static fn (FranjaHoraria $f): array => $f->paraJson(), $this->franjas),
        ];
    }
}
