<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use RuntimeException;

/**
 * Las ventas, la asistencia y las calificaciones de las funciones del historial de prueba de
 * programación (EF-09.4), para que los reportes de recaudación tengan qué cruzar en la demo.
 * Programación carga cuándo y dónde fue cada función y qué se votó (su HistorialDePrueba); acá se
 * las pide por la API interna, por su id fijo, y se inventa cuánta gente fue con un modelo simple:
 *
 * - la ocupación depende del día: los miércoles va poca gente (22 %) y los sábados, casi todos
 *   (93 %); «Metrópolis» se llena siempre (98 %);
 * - los sábados va el 95 % de los que reservaron, y los demás días el 80 %; el resto queda ausente;
 * - califica uno de cada dos que fueron, con puntajes fijos por obra: «Metrópolis» y «Nosferatu»
 *   gustan más que el resto.
 *
 * Los compradores son los espectadores inventados de la serie 00000008-… de programación, desde el
 * 10: los que votaron son del 1 en adelante, así que una parte de los votantes también compró.
 */
final class HistorialDePrueba
{
    /** Las funciones del historial se piden de a tantas, como acepta programación. */
    private const POR_PEDIDO = 20;

    /** El primer comprador de cada función, en la serie de los espectadores inventados. */
    private const PRIMER_COMPRADOR = 10;

    private const METROPOLIS = '00000002-0000-4000-8000-000000000001';
    private const NOSFERATU = '00000002-0000-4000-8000-000000000002';

    /** Ocupación de cada día de la semana, en porcentaje: 1 es lunes, como date('N'). */
    private const OCUPACION = [1 => 40, 2 => 40, 3 => 22, 4 => 45, 5 => 62, 6 => 93, 7 => 55];

    /** Las calificaciones de cada obra, en orden: se repiten. */
    private const PUNTAJES = [self::METROPOLIS => [5, 5, 4, 5, 5], self::NOSFERATU => [5, 4, 4, 5, 4]];
    private const PUNTAJES_DEL_RESTO = [4, 3, 4, 4, 3];

    /** El alfabeto de los códigos de reserva (Reserva::ALFABETO), para que se puedan dictar igual. */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private function __construct(
        private readonly BaseDeDatos $base,
        private readonly FuncionesDeProgramacion $programacion,
    ) {
    }

    /**
     * Como los datos de prueba: en desarrollo, siempre; en producción, sólo con la confirmación.
     *
     * @throws RuntimeException en producción, sin la confirmación
     */
    public static function para(Configuracion $configuracion, BaseDeDatos $base, FuncionesDeProgramacion $programacion, bool $confirmadoEnProduccion = false): self
    {
        if (!$configuracion->enDesarrollo() && !$confirmadoEnProduccion) {
            throw new RuntimeException(
                'Esto es producción: el historial de prueba se carga acá sólo con la confirmación explícita, '
                . 'agregando --confirmar-produccion al comando.',
            );
        }

        return new self($base, $programacion);
    }

    /** El id de la función n del historial de programación (HistorialDePrueba::idDeFuncion()). */
    public static function idDeFuncion(int $numero): string
    {
        return sprintf('00000005-0000-4000-8000-%012d', $numero);
    }

    /** El espectador inventado n (HistorialDePrueba::idDeEspectador() de programación). */
    public static function idDeEspectador(int $numero): string
    {
        return sprintf('00000008-0000-4000-8000-%012d', $numero);
    }

    /**
     * Recorre las funciones del historial de programación, de a veinte, hasta que no venga
     * ninguna. A una finalizada que ya tiene reservas no le agrega nada: correrlo de nuevo no
     * duplica, y si alguien reservó a mano para probar, queda como estaba.
     *
     * @return array{agregadas: int, yaEstaban: int, reservas: int, calificaciones: int}
     *
     * @throws RuntimeException si programación no tiene el historial: hay que cargarlo antes
     */
    public function cargar(): array
    {
        $resultado = ['agregadas' => 0, 'yaEstaban' => 0, 'reservas' => 0, 'calificaciones' => 0];
        for ($desde = 1; ; $desde += self::POR_PEDIDO) {
            $ids = array_map(self::idDeFuncion(...), range($desde, $desde + self::POR_PEDIDO - 1));
            $funciones = $this->programacion->buscarVarias($ids);
            if ($funciones === []) {
                break;
            }
            foreach ($ids as $indice => $id) {
                $funcion = $funciones[$id] ?? null;
                if ($funcion === null || $funcion->estado !== 'finalizada' || $funcion->fechaHora === null) {
                    continue;
                }
                if ($this->base->fila('SELECT 1 FROM reserva WHERE funcion_id = ? LIMIT 1', [$id]) !== null) {
                    ++$resultado['yaEstaban'];
                    continue;
                }
                [$reservas, $calificaciones] = $this->cargarUna($funcion, $desde + $indice);
                ++$resultado['agregadas'];
                $resultado['reservas'] += $reservas;
                $resultado['calificaciones'] += $calificaciones;
            }
        }
        if ($resultado['agregadas'] + $resultado['yaEstaban'] === 0) {
            throw new RuntimeException('Programación no tiene el historial de prueba: primero programacion/herramientas/historial-de-prueba.php.');
        }

        return $resultado;
    }

    /**
     * Lo que pasó en una función: cuántos lugares se vendieron, de a una o dos entradas, quiénes
     * fueron y qué les pareció. Sin red ni base: lo usa cargar() y lo prueban las pruebas.
     *
     * @return list<array{cantidad: int, asistio: bool, puntaje: ?int}> una por reserva
     */
    public static function reservas(FuncionDeProgramacion $funcion): array
    {
        $dia = (int) ($funcion->fechaHora ?? throw new RuntimeException('Una función del historial sin fecha.'))->format('N');
        $ocupacion = in_array(self::METROPOLIS, $funcion->obrasIds, true) ? 98 : self::OCUPACION[$dia];
        $vendidas = intdiv($funcion->cupo * $ocupacion, 100);
        $asistentes = intdiv($vendidas * ($dia === 6 ? 95 : 80), 100);
        $puntajes = self::PUNTAJES[$funcion->obrasIds[0] ?? ''] ?? self::PUNTAJES_DEL_RESTO;

        $reservas = [];
        $lugares = 0;
        $fueron = 0;
        $calificaron = 0;
        while ($lugares < $vendidas) {
            // Una de cada tres, de a dos entradas.
            $cantidad = min(count($reservas) % 3 === 2 ? 2 : 1, $vendidas - $lugares);
            $asistio = $fueron + $cantidad <= $asistentes;
            $puntaje = null;
            if ($asistio) {
                $fueron += $cantidad;
                // Califica uno de cada dos que fueron.
                if ($fueron % 2 === 0 || $cantidad === 2) {
                    $puntaje = $puntajes[$calificaron++ % count($puntajes)];
                }
            }
            $reservas[] = ['cantidad' => $cantidad, 'asistio' => $asistio, 'puntaje' => $puntaje];
            $lugares += $cantidad;
        }

        return $reservas;
    }

    /**
     * @return array{int, int} cuántas reservas y cuántas calificaciones
     */
    private function cargarUna(FuncionDeProgramacion $funcion, int $numero): array
    {
        $reservas = self::reservas($funcion);
        $fecha = $funcion->fechaHora ?? throw new RuntimeException('Una función del historial sin fecha.');

        return $this->base->enTransaccion(function (BaseDeDatos $base) use ($funcion, $numero, $reservas, $fecha): array {
            $calificaciones = 0;
            foreach ($reservas as $indice => $reserva) {
                $reservaId = sprintf('00000009-%04d-4000-8000-%012d', $numero, $indice);
                $usuarioId = self::idDeEspectador(self::PRIMER_COMPRADOR + $indice);
                $base->ejecutar(
                    'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en, pago_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $reservaId,
                        $funcion->id,
                        $usuarioId,
                        self::codigo($numero, $indice),
                        $reserva['cantidad'],
                        number_format($funcion->precioBase * $reserva['cantidad'], 2, '.', ''),
                        $reserva['asistio'] ? EstadoReserva::Asistio : EstadoReserva::Ausente,
                        $fecha->modify('-3 days')->setTime(10, 0),
                        // Pagada por Mercado Pago, con un pago inventado; a la gorra, sin pago.
                        $funcion->precioBase > 0 ? sprintf('historial-%d-%d', $numero, $indice) : null,
                    ],
                );
                if ($reserva['puntaje'] === null) {
                    continue;
                }
                $calificacionId = sprintf('0000000a-%04d-4000-8000-%012d', $numero, $indice);
                $base->ejecutar(
                    'INSERT INTO calificacion (id, funcion_id, usuario_id, reserva_id, puntaje, comentario, sala_id, organizador_id, creada_en)
                     VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)',
                    [
                        $calificacionId, $funcion->id, $usuarioId, $reservaId, $reserva['puntaje'],
                        $funcion->salaId, $funcion->organizadorId,
                        $fecha->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
                    ],
                );
                foreach ($funcion->obrasIds as $obraId) {
                    $base->ejecutar('INSERT INTO calificacion_obra (calificacion_id, obra_id) VALUES (?, ?)', [$calificacionId, $obraId]);
                }
                ++$calificaciones;
            }

            return [count($reservas), $calificaciones];
        });
    }

    /**
     * Un código distinto por función y reserva, con el alfabeto de los de verdad: «H» y el
     * número en base 31, completado a ocho.
     */
    private static function codigo(int $numero, int $indice): string
    {
        $valor = $numero * 1000 + $indice;
        $codigo = '';
        do {
            $codigo = self::ALFABETO[$valor % strlen(self::ALFABETO)] . $codigo;
            $valor = intdiv($valor, strlen(self::ALFABETO));
        } while ($valor > 0);

        return 'H' . str_pad($codigo, 7, self::ALFABETO[0], STR_PAD_LEFT);
    }
}
