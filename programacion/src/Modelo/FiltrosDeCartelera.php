<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Lo que el visitante elige para recortar la cartelera, y la sala, para la ficha de una sala con
 * sus funciones. Cada filtro es opcional, y los que vienen se cumplen todos a la vez. Un texto en
 * blanco es lo mismo que no buscar nada.
 *
 * Son los valores tal como los mandó el visitante: que la fecha y el género tengan forma lo
 * controla quien los lee de la URL, y la consulta los pasa siempre aparte del SQL.
 */
final readonly class FiltrosDeCartelera
{
    /** Se busca en el título y el director de las obras, y en el nombre de la sala. */
    public ?string $texto;

    public ?string $localidad;

    /** El día, sin la hora: entran todas las funciones de ese día. */
    public ?DateTimeImmutable $fecha;

    public ?string $generoId;

    /** No viene de la URL de la cartelera: lo pone la ficha de la sala. */
    public ?string $salaId;

    public function __construct(
        ?string $texto = null,
        ?string $localidad = null,
        ?DateTimeImmutable $fecha = null,
        ?string $generoId = null,
        ?string $salaId = null,
    ) {
        $this->texto = self::sinBlancos($texto);
        $this->localidad = self::sinBlancos($localidad);
        $this->fecha = $fecha?->setTime(0, 0);
        $this->generoId = self::sinBlancos($generoId);
        $this->salaId = self::sinBlancos($salaId);
    }

    private static function sinBlancos(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
