<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;

/**
 * Valida lo que manda el formulario para proponer las franjas del acuerdo de fecha (E3-13.2).
 *
 * Se valida acá, antes de llamar a funciones: funciones también controla, pero sus 422 llegan
 * como un error del cliente interno, y el organizador vería una página 500 en vez del motivo y su
 * formulario con lo que cargó (E3-13.F2).
 *
 * Los campos son los de la plantilla programacion/proponer_franjas: franjas[n][fecha],
 * franjas[n][hora_inicio] y franjas[n][hora_fin]. También acepta la fecha y la hora juntas
 * (franjas[n][fecha_hora_inicio] o franja_inicio[]), como las mandaba la primera versión.
 */
final class PropuestaDeFranjas
{
    /** Las mismas cotas que dice el formulario y que aplica su JavaScript. */
    public const MINIMO_DE_FRANJAS = 2;
    public const MAXIMO_DE_FRANJAS = 5;

    /** @var array{minimo_asistentes: string, franjas: list<array{fecha: string, hora_inicio: string, hora_fin: string}>} lo que mandó, para volver a mostrarlo */
    public private(set) array $valores = ['minimo_asistentes' => '', 'franjas' => []];

    /** @var array<string, string> campo => mensaje de error */
    public private(set) array $errores = [];

    public private(set) int $minimo = 0;

    /** @var list<array{fecha_hora_inicio: string, fecha_hora_fin: ?string}> las franjas como las pide funciones */
    public private(set) array $franjas = [];

    /**
     * @param array<string, mixed> $campos el cuerpo del POST
     * @param int                  $cupo   el de la función: el mínimo no puede pasarlo
     */
    public static function desdeCampos(array $campos, int $cupo, DateTimeImmutable $ahora = new DateTimeImmutable()): self
    {
        $propuesta = new self();
        $propuesta->validarMinimo($campos['minimo_asistentes'] ?? null, $cupo);
        $propuesta->validarFranjas(self::filas($campos), $ahora);

        return $propuesta;
    }

    public function esValida(): bool
    {
        return $this->errores === [];
    }

    private function validarMinimo(mixed $valor, int $cupo): void
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';
        $this->valores['minimo_asistentes'] = $texto;
        $minimo = filter_var($texto, FILTER_VALIDATE_INT);
        if ($minimo === false || $minimo < 1 || $minimo > $cupo) {
            $this->errores['minimo_asistentes'] = "El mínimo de asistentes tiene que ser un número entre 1 y {$cupo}, el cupo de la función.";

            return;
        }
        $this->minimo = $minimo;
    }

    /**
     * @param list<array{fecha: string, hora_inicio: string, hora_fin: string}> $filas
     */
    private function validarFranjas(array $filas, DateTimeImmutable $ahora): void
    {
        $this->valores['franjas'] = $filas;
        if (count($filas) < self::MINIMO_DE_FRANJAS) {
            $this->errores['franjas'] = 'Proponé al menos ' . self::MINIMO_DE_FRANJAS . ' franjas, para que el público pueda elegir.';

            return;
        }
        if (count($filas) > self::MAXIMO_DE_FRANJAS) {
            $this->errores['franjas'] = 'Podés proponer hasta ' . self::MAXIMO_DE_FRANJAS . ' franjas.';

            return;
        }

        $problemas = [];
        $inicios = [];
        foreach ($filas as $indice => $fila) {
            $opcion = $indice + 1;
            $inicio = self::fechaHora($fila['fecha'], $fila['hora_inicio']);
            if ($inicio === null) {
                $problemas[] = "A la opción {$opcion} le falta la fecha o el horario de inicio.";

                continue;
            }
            $fin = null;
            if ($fila['hora_fin'] !== '') {
                $fin = self::fechaHora($fila['fecha'], $fila['hora_fin']);
                if ($fin === null) {
                    $problemas[] = "El horario de fin de la opción {$opcion} no es válido.";

                    continue;
                }
            }
            if ($inicio <= $ahora) {
                $problemas[] = "La opción {$opcion} ya pasó: tiene que ser una fecha y un horario futuros.";

                continue;
            }
            // La franja es de un solo día: una que pasa la medianoche termina, a lo sumo, 23:59.
            if ($fin !== null && $fin <= $inicio) {
                $problemas[] = "La opción {$opcion} tiene que terminar después de empezar.";

                continue;
            }
            $clave = $inicio->format('Y-m-d H:i');
            if (isset($inicios[$clave])) {
                $problemas[] = "Las opciones {$inicios[$clave]} y {$opcion} empiezan el mismo día a la misma hora.";

                continue;
            }
            $inicios[$clave] = $opcion;
            $this->franjas[] = [
                'fecha_hora_inicio' => $inicio->format('Y-m-d H:i:s'),
                'fecha_hora_fin' => $fin?->format('Y-m-d H:i:s'),
            ];
        }
        if ($problemas !== []) {
            $this->errores['franjas'] = implode(' ', $problemas);
            $this->franjas = [];
        }
    }

    /**
     * Las filas que mandó, con la fecha y las horas por separado. Las que vienen vacías (una
     * opción agregada y no completada) no cuentan.
     *
     * @param array<string, mixed> $campos
     *
     * @return list<array{fecha: string, hora_inicio: string, hora_fin: string}>
     */
    private static function filas(array $campos): array
    {
        $filas = [];
        if (is_array($campos['franjas'] ?? null)) {
            foreach ($campos['franjas'] as $franja) {
                if (!is_array($franja)) {
                    continue;
                }
                if (isset($franja['fecha_hora_inicio'])) {
                    $filas[] = self::separar(self::texto($franja['fecha_hora_inicio']), self::texto($franja['fecha_hora_fin'] ?? ''));
                } else {
                    $filas[] = [
                        'fecha' => self::texto($franja['fecha'] ?? ''),
                        'hora_inicio' => self::texto($franja['hora_inicio'] ?? ''),
                        'hora_fin' => self::texto($franja['hora_fin'] ?? ''),
                    ];
                }
            }
        } elseif (is_array($campos['franja_inicio'] ?? null)) {
            $fines = is_array($campos['franja_fin'] ?? null) ? $campos['franja_fin'] : [];
            foreach ($campos['franja_inicio'] as $indice => $inicio) {
                $filas[] = self::separar(self::texto($inicio), self::texto($fines[$indice] ?? ''));
            }
        }

        return array_values(array_filter(
            $filas,
            static fn (array $fila): bool => $fila['fecha'] !== '' || $fila['hora_inicio'] !== '' || $fila['hora_fin'] !== '',
        ));
    }

    /**
     * «2026-11-20 20:00:00» y «2026-11-20 22:30:00» a la fila del formulario.
     *
     * @return array{fecha: string, hora_inicio: string, hora_fin: string}
     */
    private static function separar(string $inicio, string $fin): array
    {
        [$fecha, $horaInicio] = array_pad(explode(' ', $inicio, 2), 2, '');
        $horaFin = $fin === '' ? '' : (explode(' ', $fin, 2)[1] ?? '');

        return ['fecha' => $fecha, 'hora_inicio' => substr($horaInicio, 0, 5), 'hora_fin' => substr($horaFin, 0, 5)];
    }

    /**
     * La fecha y la hora juntas, o null si alguna falta o no es válida (como el 31/02).
     */
    private static function fechaHora(string $fecha, string $hora): ?DateTimeImmutable
    {
        $hora = substr($hora, 0, 5);
        if ($fecha === '' || $hora === '') {
            return null;
        }
        $fechaHora = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$fecha} {$hora}");

        return $fechaHora !== false && $fechaHora->format('Y-m-d H:i') === "{$fecha} {$hora}" ? $fechaHora : null;
    }

    private static function texto(mixed $valor): string
    {
        return is_scalar($valor) ? trim((string) $valor) : '';
    }
}
