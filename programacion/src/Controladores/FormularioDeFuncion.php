<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\Sala;

/**
 * Valida lo que manda el formulario de alta para programar una función (E3-11.2).
 *
 * Los nombres de los campos coinciden con la plantilla programacion/alta_funcion del paquete front.
 */
final class FormularioDeFuncion
{
    /** @var array<string, mixed> lo que mandó, para volver a mostrarlo si hay errores */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => mensaje de error */
    public private(set) array $errores = [];

    public private(set) ?string $salaId = null;

    /** @var list<string> */
    public private(set) array $obrasIds = [];

    public private(set) ?int $cupo = null;

    public private(set) ?float $precio = null;

    public private(set) ?DateTimeImmutable $fechaHora = null;

    /**
     * @param array<string, mixed> $campos            el cuerpo del POST
     * @param list<Sala>           $salasHabilitadas  las salas del organizador habilitadas
     * @param list<Obra>           $obrasDisponibles  las obras disponibles en el catálogo
     */
    public static function desdeCampos(
        array $campos,
        array $salasHabilitadas,
        array $obrasDisponibles,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): self {
        $formulario = new self();
        $formulario->validar($campos, $salasHabilitadas, $obrasDisponibles, $ahora);

        return $formulario;
    }

    public function esValido(): bool
    {
        return $this->errores === [];
    }

    /**
     * @param array<string, mixed> $campos
     * @param list<Sala>           $salasHabilitadas
     * @param list<Obra>           $obrasDisponibles
     */
    private function validar(
        array $campos,
        array $salasHabilitadas,
        array $obrasDisponibles,
        DateTimeImmutable $ahora,
    ): void {
        // 1. Sala propia
        $salaIdRaw = self::texto($campos['sala_id'] ?? null);
        $this->valores['sala_id'] = $salaIdRaw;
        $salaElegida = null;
        if ($salaIdRaw === '') {
            $this->errores['sala_id'] = 'Falta seleccionar la sala.';
        } else {
            foreach ($salasHabilitadas as $s) {
                if ($s->id === $salaIdRaw) {
                    $salaElegida = $s;
                    break;
                }
            }
            if ($salaElegida === null) {
                $this->errores['sala_id'] = 'La sala seleccionada no es válida o no está habilitada.';
            } else {
                $this->salaId = $salaElegida->id;
            }
        }

        // 2. Obras del catálogo
        $rawObras = $campos['obras'] ?? ($campos['obras_ids'] ?? ($campos['obra_id'] ?? []));
        $obrasSeleccionadas = is_array($rawObras) ? $rawObras : [$rawObras];
        $obrasIds = array_values(array_filter(
            array_map(static fn (mixed $id): string => self::texto($id), $obrasSeleccionadas),
            static fn (string $id): bool => $id !== '',
        ));
        $this->valores['obras'] = $obrasIds;

        if ($obrasIds === []) {
            $this->errores['obras'] = 'Tenés que elegir al menos una obra del catálogo.';
        } elseif (count(array_unique($obrasIds)) !== count($obrasIds)) {
            $this->errores['obras'] = 'Una obra no puede estar dos veces en la misma función.';
        } else {
            $idsCatalogo = array_column($obrasDisponibles, 'id');
            if (array_diff($obrasIds, $idsCatalogo) !== []) {
                $this->errores['obras'] = 'Una de las obras seleccionadas no existe en el catálogo.';
            } else {
                $this->obrasIds = $obrasIds;
            }
        }

        // 3. Fecha y horario
        $fecha = self::texto($campos['fecha'] ?? null);
        $hora = self::texto($campos['hora'] ?? null);
        $this->valores['fecha'] = $fecha;
        $this->valores['hora'] = $hora;

        if ($fecha === '') {
            $this->errores['fecha'] = 'Falta la fecha de la función.';
        }
        if ($hora === '') {
            $this->errores['hora'] = 'Falta el horario de la función.';
        }

        if ($fecha !== '' && $hora !== '') {
            // Asegurar hora en formato HH:MM
            $horaNormalizada = strlen($hora) === 5 ? $hora : (strlen($hora) === 8 ? substr($hora, 0, 5) : $hora);
            $fechaHora = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$fecha} {$horaNormalizada}");
            if ($fechaHora === false || $fechaHora->format('Y-m-d H:i') !== "{$fecha} {$horaNormalizada}") {
                $this->errores['fecha'] = 'La fecha y el horario no tienen un formato válido.';
            } elseif ($fechaHora <= $ahora) {
                $this->errores['fecha'] = 'La fecha de la función tiene que ser futura.';
            } else {
                $this->fechaHora = $fechaHora;
            }
        }

        // 4. Precio de la entrada
        $precioRaw = self::texto($campos['precio'] ?? null);
        $this->valores['precio'] = $precioRaw;
        if ($precioRaw === '') {
            $this->errores['precio'] = 'Falta el precio de la entrada.';
        } elseif (!is_numeric($precioRaw) || (float) $precioRaw < 0) {
            $this->errores['precio'] = 'El precio no puede ser negativo.';
        } else {
            $this->precio = (float) $precioRaw;
        }

        // 5. Cupo disponible
        $cupoRaw = self::texto($campos['cupo'] ?? null);
        $this->valores['cupo'] = $cupoRaw;
        $cupoInt = filter_var($cupoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($cupoRaw === '' || $cupoInt === false) {
            $this->errores['cupo'] = 'El cupo tiene que ser de al menos un lugar.';
        } else {
            $cupo = (int) $cupoInt;
            if ($salaElegida !== null && $cupo > $salaElegida->datos->capacidad) {
                $this->errores['cupo'] = "El cupo no puede ser mayor a la capacidad de la sala ({$salaElegida->datos->capacidad}).";
            } else {
                $this->cupo = $cupo;
            }
        }
    }

    private static function texto(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $valor));
    }
}
