<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * El embudo de conversión de una sala, o de todas (EF-22.13): de los que votaron títulos en una
 * función, cuántos la reservaron, la pagaron, fueron y la calificaron, y la plata de cada paso. El
 * profe, el 18/11: «¿cuántos de esos se convirtieron en plata?». La votación es lo que nos
 * diferencia de un cine común, y esto mide si trae gente.
 *
 * Se cuentan personas por función: quien votó en dos funciones cuenta dos veces, porque son dos
 * decisiones de ir. Los montos van en centavos.
 */
final readonly class Embudo
{
    /** Los pasos, en orden; cada uno es un subconjunto del anterior. */
    public const PASOS = ['votaron', 'reservaron', 'pagaron', 'fueron', 'calificaron'];

    /**
     * @param ?string            $salaId     null para todas las salas juntas
     * @param array<string, int> $personas   por paso (las claves de PASOS)
     * @param array<string, int> $centavos   por paso: en «votaron», lo que habría entrado si cada
     *                                       uno compraba una entrada; en «reservaron», lo que
     *                                       quisieron comprar; desde «pagaron», lo que pagaron
     * @param int                $sinVotar   personas que pagaron una función sin haberla votado
     * @param int                $sinVotarCentavos lo que pagaron
     */
    public function __construct(
        public ?string $salaId,
        public string $salaNombre,
        public array $personas,
        public array $centavos,
        public int $sinVotar = 0,
        public int $sinVotarCentavos = 0,
    ) {
    }

    /**
     * El paso donde se pierde la mayor parte de los que llegaron al anterior: ahí conviene actuar.
     * Null si nadie votó.
     *
     * @return ?array{desde: string, hasta: string, conversion: int}
     */
    public function dondeSePierdeMas(): ?array
    {
        $peor = null;
        for ($i = 1; $i < count(self::PASOS); ++$i) {
            $antes = $this->personas[self::PASOS[$i - 1]];
            if ($antes === 0) {
                break;
            }
            $conversion = intdiv(200 * $this->personas[self::PASOS[$i]] + $antes, 2 * $antes);
            if ($peor === null || $conversion < $peor['conversion']) {
                $peor = ['desde' => self::PASOS[$i - 1], 'hasta' => self::PASOS[$i], 'conversion' => $conversion];
            }
        }

        return $peor;
    }
}
