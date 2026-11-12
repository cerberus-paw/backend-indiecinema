<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;
use IndieCinema\Nucleo\Uuid;

/**
 * Una función de una sala: las obras que proyecta, el precio, el cupo y en qué parte de su ciclo
 * de vida está (EstadoFuncion).
 *
 * Nace con varias obras candidatas, que los espectadores votan hasta la fecha de cierre que puso
 * el organizador; al cerrar la votación, a mano o sola al vencer (EF-03.1), se queda con las
 * elegidas. La fecha es null hasta que se cierra el acuerdo de fecha, que corre en el
 * subsistema funciones. Cada cambio de estado es un método, y el que no corresponde al estado
 * actual lanza ReglaIncumplida.
 *
 * Las obras se guardan por id: para las reglas de la función no hace falta más.
 */
final class Funcion
{
    /** Lo más que entra en la columna funcion.precio_base, DECIMAL(10, 2). */
    public const PRECIO_MAXIMO = 99_999_999.99;

    /**
     * @param non-empty-list<string> $obrasIds las candidatas mientras está en votación; después,
     *                                         las que se proyectan
     */
    public function __construct(
        public readonly string $id,
        public private(set) string $salaId,
        public private(set) array $obrasIds,
        public private(set) float $precioBase,
        public private(set) int $cupo,
        public private(set) EstadoFuncion $estado = EstadoFuncion::EnVotacionTitulos,
        public private(set) ?DateTimeImmutable $fechaHora = null,
        public readonly ?DateTimeImmutable $votacionCierraEn = null,
    ) {
    }

    /**
     * Se crea desde Sala::crearFuncion(), que además controla que la sala esté habilitada y que el
     * cupo entre en ella.
     *
     * @param list<string>           $obrasIds        las candidatas
     * @param DateTimeImmutable|null $votacionCierraEn hasta cuándo se vota (EF-03.1); null es que
     *                                                 sólo la cierra el organizador, como las que
     *                                                 se abrieron antes de que existiera
     *
     * @throws ReglaIncumplida sin obras, con obras repetidas, con un cupo que no es positivo, con
     *                         un precio negativo o con un cierre que no es futuro
     */
    public static function nueva(
        string $salaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        ?DateTimeImmutable $votacionCierraEn = null,
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
        if ($votacionCierraEn !== null && $votacionCierraEn <= $ahora) {
            throw new ReglaIncumplida('El cierre de la votación tiene que ser futuro.');
        }

        return new self(Uuid::nuevo(), $salaId, $obrasIds, $precioBase, $cupo, votacionCierraEn: $votacionCierraEn);
    }

    /**
     * Si la votación de títulos ya pasó su fecha de cierre (EF-03.1): desde ahí no se vota más, y
     * la tarea la cierra en la próxima pasada. Es estático para que lo use también la ficha, que
     * no lee la entidad, como seEdita().
     */
    public static function votacionVencida(
        EstadoFuncion $estado,
        ?DateTimeImmutable $votacionCierraEn,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): bool {
        return $estado === EstadoFuncion::EnVotacionTitulos && $votacionCierraEn !== null && $votacionCierraEn <= $ahora;
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
        self::controlarDatosDeProgramada($obrasIds, $cupo, $precioBase, $fechaHora, $ahora);

        return new self(Uuid::nuevo(), $salaId, $obrasIds, $precioBase, $cupo, EstadoFuncion::Programada, $fechaHora);
    }

    /**
     * Si el organizador puede editar la función (E3-11.3): sólo una programada que todavía no se
     * dio. En votación las obras son las candidatas que se están votando y la fecha sale del
     * acuerdo; cancelada y finalizada ya terminaron. Y una programada cuya fecha ya pasó ya se
     * proyectó, aunque la tarea todavía no la haya finalizado (EF-04.1): con otra fecha volvería
     * a la cartelera.
     *
     * Es estático para que lo usen también la ficha y el panel, que no leen la entidad.
     */
    public static function seEdita(
        EstadoFuncion $estado,
        ?DateTimeImmutable $fechaHora,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): bool {
        return $estado === EstadoFuncion::Programada && $fechaHora !== null && $fechaHora > $ahora;
    }

    /**
     * Si la ficha ofrece reservar: sólo una programada que todavía no empezó, la misma regla que
     * seEdita() (F-01.2). Hasta la E4-01 también en votación o en acuerdo de fecha, aunque no
     * tuviera fecha, y se reservaba algo que quizás no se daba. La que decide es la reserva, en
     * funciones, con la misma regla (FuncionDeProgramacion::motivoParaNoReservar()); esto sólo
     * evita mostrar un formulario que no va a andar. Es estático por lo mismo que seEdita().
     */
    public static function seReserva(
        EstadoFuncion $estado,
        ?DateTimeImmutable $fechaHora,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): bool {
        return $estado === EstadoFuncion::Programada && $fechaHora !== null && $fechaHora > $ahora;
    }

    /**
     * Si el organizador puede cancelar la función (E3-11.4): en votación o programada, mientras
     * todavía no se dio. Una programada cuya fecha ya pasó ya se proyectó, aunque la tarea todavía
     * no la haya finalizado (EF-04.1).
     *
     * cancelar() controla sólo el ciclo de vida; la fecha la mira quien ofrece cancelar. Es
     * estático por lo mismo que seEdita().
     */
    public static function seCancela(
        EstadoFuncion $estado,
        ?DateTimeImmutable $fechaHora,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): bool {
        if (!$estado->puedePasarA(EstadoFuncion::Cancelada)) {
            return false;
        }

        return $estado !== EstadoFuncion::Programada || ($fechaHora !== null && $fechaHora > $ahora);
    }

    /**
     * Modifica los datos de la función (E3-11.3), si se puede editar (seEdita()).
     *
     * @param list<string> $obrasIds
     *
     * @throws ReglaIncumplida si no está programada o ya se dio, sin obras, con obras repetidas, cupo no positivo, precio negativo o fecha no futura
     */
    public function actualizar(
        string $salaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): void {
        if (!self::seEdita($this->estado, $this->fechaHora, $ahora)) {
            throw new ReglaIncumplida($this->estado === EstadoFuncion::Programada
                ? 'Una función que ya se dio no se puede modificar.'
                : "Una función {$this->estado->etiqueta()} no se puede modificar.");
        }
        self::controlarDatosDeProgramada($obrasIds, $cupo, $precioBase, $fechaHora, $ahora);

        $this->salaId = $salaId;
        $this->obrasIds = array_values($obrasIds);
        $this->cupo = $cupo;
        $this->precioBase = $precioBase;
        $this->fechaHora = $fechaHora;
    }

    /**
     * Las reglas de los datos de una función programada, al crearla y al editarla.
     *
     * @param list<string> $obrasIds
     *
     * @throws ReglaIncumplida sin obras, con obras repetidas, cupo no positivo, precio negativo o fecha no futura
     */
    private static function controlarDatosDeProgramada(
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
        DateTimeImmutable $ahora,
    ): void {
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
        if ($precioBase > self::PRECIO_MAXIMO) {
            throw new ReglaIncumplida('El precio es demasiado alto.');
        }
        if ($fechaHora <= $ahora) {
            throw new ReglaIncumplida('La fecha de la función tiene que ser futura.');
        }
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
     * Una función programada termina cuando ya se proyectó: no antes de su fecha. La finaliza
     * FinalizacionDeFunciones, cuando pasó también la duración de las funciones de la sala.
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
