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
    public const MODO_PROGRAMADA = 'programada';
    public const MODO_EN_VOTACION_TITULOS = 'en_votacion_titulos';

    /** Hasta ocho cifras y dos decimales, lo que entra en funcion.precio_base (Funcion::PRECIO_MAXIMO). */
    private const PRECIO = '/^[0-9]{1,8}([.,][0-9]{1,2})?$/D';

    /**
     * La misma regla del precio para el atributo pattern, que ancla solo y no lleva barras. El campo
     * del precio es de texto y no numérico (E4-09.4), así que el navegador sí lo aplica.
     */
    private const PRECIO_PARA_EL_NAVEGADOR = '[0-9]{1,8}([.,][0-9]{1,2})?';

    /**
     * Los mensajes que el navegador también puede dar, acá para que reglas() y validar() digan lo
     * mismo. Los que dependen de la sala elegida —cuántas películas entran, la capacidad, que la
     * fecha sea futura— no están: ésos sólo los sabe el servidor y llegan al mandar.
     */
    private const FALTA_SALA = 'Falta seleccionar la sala.';
    private const FALTA_FECHA = 'Falta la fecha de la función.';
    private const FALTA_HORA = 'Falta el horario de la función.';
    private const FALTA_PRECIO = 'Falta el precio de la entrada.';
    private const PRECIO_NEGATIVO = 'El precio no puede ser negativo.';
    private const PRECIO_INVALIDO = 'El precio tiene que ser un número de hasta 99.999.999,99, sin puntos de miles.';
    private const CUPO_INVALIDO = 'El cupo tiene que ser de al menos un lugar.';
    private const FALTA_CIERRE_FECHA = 'Falta el día en que cierra la votación.';
    private const FALTA_CIERRE_HORA = 'Falta la hora en que cierra la votación.';
    private const MODO_DESCONOCIDO = 'Elegí si la función se programa con fecha o se abre a votación de títulos.';
    /** Con una sola candidata no hay nada que votar (F-01.6). */
    private const CANDIDATAS_MINIMAS = 2;
    private const POCAS_CANDIDATAS = 'Para abrir una votación elegí al menos dos obras candidatas.';

    /** @var array<string, mixed> lo que mandó, para volver a mostrarlo si hay errores */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => mensaje de error */
    public private(set) array $errores = [];

    public private(set) ?string $salaId = null;

    /** @var list<string> */
    public private(set) array $obrasIds = [];

    public private(set) string $modo = self::MODO_PROGRAMADA;

    public private(set) ?int $cupo = null;

    public private(set) ?float $precio = null;

    public private(set) ?DateTimeImmutable $fechaHora = null;

    /** Hasta cuándo se vota, si abre una votación (EF-03.1). */
    public private(set) ?DateTimeImmutable $votacionCierraEn = null;

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

    public function esProgramada(): bool
    {
        return $this->modo === self::MODO_PROGRAMADA;
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
        // 0. Modo de alta. Sin modo es programada: la edición no lo manda, porque sólo se edita una
        // programada. Uno que no es ninguno de los dos es un error y no una programada callada: si
        // no, un pedido armado a mano publicaba sin avisar algo que no pidió (F-01.6).
        $modo = self::texto($campos['modo'] ?? null);
        if ($modo === '') {
            $modo = self::MODO_PROGRAMADA;
        } elseif (!in_array($modo, [self::MODO_PROGRAMADA, self::MODO_EN_VOTACION_TITULOS], true)) {
            $this->errores['modo'] = self::MODO_DESCONOCIDO;
            $modo = self::MODO_PROGRAMADA;
        }
        $this->modo = $modo;
        $this->valores['modo'] = $modo;

        // 1. Sala propia
        $salaIdRaw = self::texto($campos['sala_id'] ?? null);
        $this->valores['sala_id'] = $salaIdRaw;
        $salaElegida = null;
        if ($salaIdRaw === '') {
            $this->errores['sala_id'] = self::FALTA_SALA;
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
        $rawObras = $campos['obras'] ?? [];
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
            $duraciones = array_column($obrasDisponibles, 'duracionMinutos', 'id');
            $duracion = array_sum(array_intersect_key($duraciones, array_flip($obrasIds)));
            if (array_diff($obrasIds, $idsCatalogo) !== []) {
                $this->errores['obras'] = 'Una de las obras seleccionadas no existe en el catálogo.';
            } elseif (!$this->esProgramada() && count($obrasIds) < self::CANDIDATAS_MINIMAS) {
                $this->errores['obras'] = self::POCAS_CANDIDATAS;
            } elseif ($this->esProgramada() && $salaElegida !== null && count($obrasIds) > $salaElegida->datos->peliculasPorFuncion) {
                // Los parámetros que la función hereda de la sala, como dice el panel del costado.
                $this->errores['obras'] = "En esta sala entran hasta {$salaElegida->datos->peliculasPorFuncion} películas por función.";
            } elseif ($this->esProgramada() && $salaElegida !== null && $duracion > $salaElegida->datos->duracionFuncion) {
                $this->errores['obras'] = "Las obras duran {$duracion} minutos y en esta sala una función puede durar hasta {$salaElegida->datos->duracionFuncion}.";
            } elseif (!$this->esProgramada() && $salaElegida !== null && ($larga = self::primeraQueNoEntra($obrasDisponibles, $obrasIds, $salaElegida->datos->duracionFuncion)) !== null) {
                // En una votación cada candidata tiene que entrar sola (EF-05.4); juntas pueden pasarse.
                $this->errores['obras'] = Sala::noEntra($larga, $salaElegida->datos->duracionFuncion);
            } else {
                $this->obrasIds = $obrasIds;
            }
        }

        // 3. Fecha y horario
        $fecha = self::texto($campos['fecha'] ?? null);
        $hora = self::texto($campos['hora'] ?? null);
        $this->valores['fecha'] = $fecha;
        $this->valores['hora'] = $hora;

        if ($this->esProgramada()) {
            if ($fecha === '') {
                $this->errores['fecha'] = self::FALTA_FECHA;
            }
            if ($hora === '') {
                $this->errores['hora'] = self::FALTA_HORA;
            }

            if ($fecha !== '' && $hora !== '') {
                $fechaHora = self::fechaYHora($fecha, $hora);
                if ($fechaHora === null) {
                    $this->errores['fecha'] = 'La fecha y el horario no tienen un formato válido.';
                } elseif ($fechaHora <= $ahora) {
                    $this->errores['fecha'] = 'La fecha de la función tiene que ser futura.';
                } else {
                    $this->fechaHora = $fechaHora;
                }
            }
        }

        // 3 bis. Cierre de la votación (EF-03.1): la propuesta dice que la votación «cierra en la
        // fecha límite definida (o manualmente)». Es obligatorio, para que ninguna quede abierta
        // para siempre si el organizador se olvida; cerrarla antes a mano sigue andando.
        $cierreFecha = self::texto($campos['cierre_fecha'] ?? null);
        $cierreHora = self::texto($campos['cierre_hora'] ?? null);
        $this->valores['cierre_fecha'] = $cierreFecha;
        $this->valores['cierre_hora'] = $cierreHora;

        if (!$this->esProgramada()) {
            if ($cierreFecha === '') {
                $this->errores['cierre_fecha'] = self::FALTA_CIERRE_FECHA;
            }
            if ($cierreHora === '') {
                $this->errores['cierre_hora'] = self::FALTA_CIERRE_HORA;
            }

            if ($cierreFecha !== '' && $cierreHora !== '') {
                $cierre = self::fechaYHora($cierreFecha, $cierreHora);
                if ($cierre === null) {
                    $this->errores['cierre_fecha'] = 'El día y la hora del cierre no tienen un formato válido.';
                } elseif ($cierre <= $ahora) {
                    $this->errores['cierre_fecha'] = 'El cierre de la votación tiene que ser futuro.';
                } else {
                    $this->votacionCierraEn = $cierre;
                }
            }
        }

        // 4. Precio de la entrada
        $precioRaw = self::texto($campos['precio'] ?? null);
        $this->valores['precio'] = $precioRaw;
        if ($precioRaw === '') {
            $this->errores['precio'] = self::FALTA_PRECIO;
        } elseif (preg_match('/^-/', $precioRaw) === 1 && is_numeric($precioRaw)) {
            $this->errores['precio'] = self::PRECIO_NEGATIVO;
        } elseif (preg_match(self::PRECIO, $precioRaw) !== 1) {
            // Sólo cifras y hasta dos decimales: «1e12» también es un número para PHP, y no entra
            // en la columna.
            $this->errores['precio'] = self::PRECIO_INVALIDO;
        } else {
            $this->precio = (float) str_replace(',', '.', $precioRaw);
        }

        // 5. Cupo disponible
        $cupoRaw = self::texto($campos['cupo'] ?? null);
        $this->valores['cupo'] = $cupoRaw;
        $cupoInt = filter_var($cupoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($cupoRaw === '' || $cupoInt === false) {
            $this->errores['cupo'] = self::CUPO_INVALIDO;
        } else {
            $cupo = (int) $cupoInt;
            if ($salaElegida !== null && $cupo > $salaElegida->datos->capacidad) {
                $this->errores['cupo'] = "El cupo no puede ser mayor a la capacidad de la sala ({$salaElegida->datos->capacidad}).";
            } else {
                $this->cupo = $cupo;
            }
        }
    }

    /**
     * Las reglas que el navegador puede revisar solo, con los mensajes que contesta este mismo
     * formulario (E4-09.1): campo => las claves que espera el parcial de campo del paquete front.
     * Así el texto que ve el organizador mientras carga es el mismo que si mandara, y es el mismo
     * en cualquier navegador.
     *
     * Lo que depende de la sala elegida —cuántas películas entran, la duración, la capacidad— y
     * que la fecha sea futura no están acá: ésos sólo los sabe el servidor, y llegan al mandar.
     * El servidor igual valida todo: el navegador se puede saltear.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function reglas(): array
    {
        return [
            'sala_id' => ['requerido' => true, 'mensajes' => ['requerido' => self::FALTA_SALA]],
            'fecha' => ['requerido' => true, 'mensajes' => ['requerido' => self::FALTA_FECHA]],
            'hora' => ['requerido' => true, 'mensajes' => ['requerido' => self::FALTA_HORA]],
            // Sólo se piden al abrir una votación: ModoFuncion los habilita en ese modo (EF-03.1).
            'cierre_fecha' => ['requerido' => true, 'mensajes' => ['requerido' => self::FALTA_CIERRE_FECHA]],
            'cierre_hora' => ['requerido' => true, 'mensajes' => ['requerido' => self::FALTA_CIERRE_HORA]],
            // El precio va en un campo de texto con inputmode="decimal" (E4-09.4): en uno numérico
            // Chromium descarta la coma de 1500,50 sin avisar y además no aplica el pattern. Por eso
            // no van min ni step, que sólo valen en un campo numérico, ni sus mensajes: acá el
            // navegador puede revisar que esté y que cumpla el patrón, nada más. El signo menos lo
            // rechaza el patrón, así que en línea se lee el mensaje del formato; al mandar, validar()
            // sigue contestando que el precio no puede ser negativo.
            'precio' => [
                'requerido' => true,
                'patron' => self::PRECIO_PARA_EL_NAVEGADOR,
                'mensajes' => [
                    'requerido' => self::FALTA_PRECIO,
                    'patron' => self::PRECIO_INVALIDO,
                ],
            ],
            // Las casillas de las obras no las revisa la validación en línea: el mínimo de candidatas
            // lo controla ModoFuncion, que es el que sabe si el formulario abre una votación.
            'obras' => [
                'candidatas_minimas' => self::CANDIDATAS_MINIMAS,
                'mensajes' => ['candidatas' => self::POCAS_CANDIDATAS],
            ],
            'cupo' => [
                'requerido' => true,
                'valor_minimo' => 1,
                // El servidor contesta lo mismo para el vacío, lo que no es entero y el cero.
                'mensajes' => ['requerido' => self::CUPO_INVALIDO, 'valor_minimo' => self::CUPO_INVALIDO, 'tipo' => self::CUPO_INVALIDO],
            ],
        ];
    }

    /**
     * Un día (AAAA-MM-DD) y una hora (HH:MM, o HH:MM:SS como la manda algún navegador) juntos, o
     * null si no forman una fecha que exista: createFromFormat corre el 31 de abril al 1 de mayo
     * sin avisar, por eso se compara contra lo que se escribió.
     */
    private static function fechaYHora(string $fecha, string $hora): ?DateTimeImmutable
    {
        $hora = strlen($hora) === 8 ? substr($hora, 0, 5) : $hora;
        $fechaHora = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$fecha} {$hora}");

        return $fechaHora !== false && $fechaHora->format('Y-m-d H:i') === "{$fecha} {$hora}" ? $fechaHora : null;
    }

    /**
     * La primera de las candidatas elegidas que dura más que una función de la sala, o null.
     *
     * @param list<Obra>   $obrasDisponibles
     * @param list<string> $obrasIds
     */
    private static function primeraQueNoEntra(array $obrasDisponibles, array $obrasIds, int $duracionFuncion): ?Obra
    {
        foreach ($obrasDisponibles as $obra) {
            if (in_array($obra->id, $obrasIds, true) && $obra->duracionMinutos > $duracionFuncion) {
                return $obra;
            }
        }

        return null;
    }

    private static function texto(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $valor));
    }
}
