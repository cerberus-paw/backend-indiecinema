<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;

/**
 * Una función que ya terminó, como la cuenta programación (GET /interno/funciones/finalizadas):
 * lo que la reportería cruza con las ventas y las calificaciones de funciones (EF-09) y lo que el
 * reparto necesita para saber a quién le toca cada parte (EF-08.2).
 */
final readonly class FuncionFinalizada
{
    /**
     * @param DateTimeImmutable $fechaHora    la hora de la sala; es la que decide qué parámetro de
     *                                        reparto rige (EF-08.1)
     * @param DateTimeImmutable $finalizadaEn cuándo pasó a finalizada, que no es cuándo se dio: es
     *                                        por donde la tarea del reparto sigue (EF-08.2)
     * @param string            $precioBase   con dos decimales, como la base; en 0 es a la gorra
     * @param list<ObraVotada>  $obras        las elegidas y las candidatas que perdieron
     * @param list<string>      $votantesIds  quienes votaron títulos; vacía sin votación
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $fechaHora,
        public DateTimeImmutable $finalizadaEn,
        public int $cupo,
        public string $precioBase,
        public string $salaId,
        public string $salaNombre,
        public string $organizadorId,
        public array $obras,
        public array $votantesIds,
    ) {
    }

    /** Los votos de la votación de títulos, entre todas las obras: 0 si no hubo. */
    public function votos(): int
    {
        return array_sum(array_map(static fn (ObraVotada $obra): int => $obra->votos, $this->obras));
    }

    /**
     * Las que se proyectaron.
     *
     * @return list<ObraVotada>
     */
    public function elegidas(): array
    {
        return array_values(array_filter($this->obras, static fn (ObraVotada $obra): bool => $obra->elegida));
    }

    /**
     * «A la gorra»: la entrada no se cobra, así que no hay nada que repartir, aunque alguien haya
     * pagado por otro lado. Es el único modo sin precio que tiene el sitio.
     */
    public function esALaGorra(): bool
    {
        return Monto::centavos($this->precioBase) === 0;
    }

    /**
     * El dueño de cada obra que se proyectó, repetido si proyectó más de una: así se divide la
     * parte del dueño en partes iguales entre las obras (EF-08.2).
     *
     * @return list<string>
     */
    public function duenosDeLasObras(): array
    {
        return array_map(static fn (ObraVotada $obra): string => $obra->duenoId, $this->elegidas());
    }
}
