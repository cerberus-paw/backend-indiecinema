<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Una función como la ve su organizador en «Mis funciones»: en qué sala es, qué se proyecta, en
 * qué parte de su ciclo de vida está y cuándo, si ya tiene fecha.
 *
 * No es la de la cartelera (FuncionEnCartelera), que sólo muestra las programadas y a futuro: acá
 * están todas, también las canceladas y las que todavía no tienen fecha, porque el panel es desde
 * donde el organizador las sigue.
 */
final readonly class FuncionDelOrganizador
{
    /**
     * @param ?DateTimeImmutable     $fechaHora       null mientras está en votación o en acuerdo
     *                                                de fecha
     * @param bool                   $salaHabilitada  si la sala está publicada: hasta que lo esté,
     *                                                la función no tiene ficha que mostrar
     * @param non-empty-list<string> $titulos         las elegidas o, mientras está en votación,
     *                                                las candidatas, por título
     * @param bool                   $titulosElegidos si $titulos son las que se proyectan
     */
    public function __construct(
        public string $id,
        public EstadoFuncion $estado,
        public ?DateTimeImmutable $fechaHora,
        public float $precioBase,
        public int $cupo,
        public string $salaId,
        public string $salaNombre,
        public bool $salaHabilitada,
        public array $titulos,
        public bool $titulosElegidos,
    ) {
    }
}
