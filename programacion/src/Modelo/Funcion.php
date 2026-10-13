<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;
use IndieCinema\Nucleo\Uuid;

/**
 * Una función de una sala: las obras que proyecta, el precio, el cupo y en qué parte de su ciclo
 * de vida está (EstadoFuncion).
 *
 * Nace con varias obras candidatas, que los espectadores votan; al cerrar la votación se queda
 * con las elegidas. La fecha es null hasta que se cierra el acuerdo de fecha, que corre en el
 * subsistema funciones. Cada cambio de estado es un método, y el que no corresponde al estado
 * actual lanza ReglaIncumplida.
 *
 * Las obras se guardan por id: para las reglas de la función no hace falta más.
 */
final class Funcion
{
    /**
     * @param non-empty-list<string> $obrasIds las candidatas mientras está en votación; después,
     *                                         las que se proyectan
     */
    public function __construct(
        public readonly string $id,
        public readonly string $salaId,
        public private(set) array $obrasIds,
        public readonly float $precioBase,
        public readonly int $cupo,
        public private(set) EstadoFuncion $estado = EstadoFuncion::EnVotacionTitulos,
        public private(set) ?DateTimeImmutable $fechaHora = null,
    ) {
    }

    /**
     * Se crea desde Sala::crearFuncion(), que además controla que la sala esté habilitada y que el
     * cupo entre en ella.
     *
     * @param list<string> $obrasIds las candidatas
     *
     * @throws ReglaIncumplida sin obras, con obras repetidas, con un cupo que no es positivo o con
     *                         un precio negativo
     */
    public static function nueva(string $salaId, array $obrasIds, int $cupo, float $precioBase): self
    {
        if ($obrasIds === []) {
            throw new ReglaIncumplida('La función necesita al menos una obra.');
        }
        if (count(array_unique($obrasIds)) !== count($obrasIds)) {
            throw new ReglaIncumplida('Una obra no puede estar dos veces en la misma función.');
        }
        if ($cupo <= 0) {
            throw new ReglaIncumplida('El cupo tiene que ser de al menos un lugar.');
        }
        if ($precioBase < 0) {
            throw new ReglaIncumplida('El precio no puede ser negativo.');
        }

        return new self(Uuid::nuevo(), $salaId, $obrasIds, $precioBase, $cupo);
    }

    /**
     * Se crea ya con su fecha fijada y sus obras definitivas, en estado Programada (E3-11.2).
     *
     * @param list<string> $obrasIds las obras a proyectar
     *
     * @throws ReglaIncumplida sin obras, con obras repetidas, cupo no positivo, precio negativo o fecha no futura
     */
    public static function programada(
        string $salaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): self {
        if ($obrasIds === []) {
            throw new ReglaIncumplida('La función necesita al menos una obra.');
        }
        if (count(array_unique($obrasIds)) !== count($obrasIds)) {
            throw new ReglaIncumplida('Una obra no puede estar dos veces en la misma función.');
        }
        if ($cupo <= 0) {
            throw new ReglaIncumplida('El cupo tiene que ser de al menos un lugar.');
        }
        if ($precioBase < 0) {
            throw new ReglaIncumplida('El precio no puede ser negativo.');
        }
        if ($fechaHora <= $ahora) {
            throw new ReglaIncumplida('La fecha de la función tiene que ser futura.');
        }

        return new self(Uuid::nuevo(), $salaId, $obrasIds, $precioBase, $cupo, EstadoFuncion::Programada, $fechaHora);
    }

    /**
     * Termina la votación y la función se queda con las obras elegidas, que tienen que estar entre
     * las candidatas. Sigue el acuerdo de fecha.
     *
     * @param list<string> $obrasElegidas
     *
     * @throws ReglaIncumplida si no está en votación, o si las elegidas no son candidatas o se repiten
     */
    public function cerrarVotacionTitulos(array $obrasElegidas): void
    {
        $this->controlarPaso(EstadoFuncion::EnAcuerdoFecha);
        if ($obrasElegidas === []) {
            throw new ReglaIncumplida('Hay que elegir al menos una obra.');
        }
        if (count(array_unique($obrasElegidas)) !== count($obrasElegidas)) {
            throw new ReglaIncumplida('Una obra no puede estar dos veces en la misma función.');
        }
        if (array_diff($obrasElegidas, $this->obrasIds) !== []) {
            throw new ReglaIncumplida('Sólo se pueden elegir obras que estaban en la votación.');
        }

        $this->obrasIds = array_values($obrasElegidas);
        $this->estado = EstadoFuncion::EnAcuerdoFecha;
    }

    /**
     * La fecha que salió del acuerdo, que tiene que ser futura: la función queda programada.
     *
     * @throws ReglaIncumplida si no está en acuerdo de fecha, o si la fecha no es posterior a $ahora
     */
    public function fijarFechaHora(DateTimeImmutable $fechaHora, DateTimeImmutable $ahora = new DateTimeImmutable()): void
    {
        $this->controlarPaso(EstadoFuncion::Programada);
        if ($fechaHora <= $ahora) {
            throw new ReglaIncumplida('La fecha de la función tiene que ser futura.');
        }

        $this->fechaHora = $fechaHora;
        $this->estado = EstadoFuncion::Programada;
    }

    /**
     * @throws ReglaIncumplida si ya estaba cancelada o finalizada
     */
    public function cancelar(): void
    {
        $this->controlarPaso(EstadoFuncion::Cancelada);
        $this->estado = EstadoFuncion::Cancelada;
    }

    /**
     * Una función programada termina cuando ya se proyectó: no antes de su fecha.
     *
     * @throws ReglaIncumplida si no está programada, o si su fecha todavía no llegó
     */
    public function finalizar(DateTimeImmutable $ahora = new DateTimeImmutable()): void
    {
        $this->controlarPaso(EstadoFuncion::Finalizada);
        if ($this->fechaHora === null || $this->fechaHora > $ahora) {
            throw new ReglaIncumplida('La función no puede finalizar antes de su fecha.');
        }

        $this->estado = EstadoFuncion::Finalizada;
    }

    /**
     * @throws ReglaIncumplida si el ciclo de vida no permite pasar del estado actual a $siguiente
     */
    private function controlarPaso(EstadoFuncion $siguiente): void
    {
        if (!$this->estado->puedePasarA($siguiente)) {
            throw new ReglaIncumplida("Una función {$this->estado->etiqueta()} no puede pasar a {$siguiente->etiqueta()}.");
        }
    }
}
