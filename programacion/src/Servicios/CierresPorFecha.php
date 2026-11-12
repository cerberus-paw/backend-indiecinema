<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Los cierres que pasan solos al llegar la fecha límite (EF-03), como dice la propuesta. Lo corre
 * el cron del servidor con bin/cerrar-por-fecha.php:
 * - la votación de títulos que pasó su fecha de cierre pasa al acuerdo de fecha con las obras más
 *   votadas, tantas como películas por función tiene la sala, igual que si la cerrara el
 *   organizador (EF-03.1, EF-05.1);
 * - el acuerdo de fecha que pasó su fecha límite confirma la franja que llegó al mínimo y la
 *   función queda programada; si ninguna llegó, la función se cancela y se les avisa a los que
 *   marcaron «Me sirve» (EF-03.2).
 *
 * El cierre a mano sigue andando: la tarea sólo toma lo que pasó su fecha y sigue abierto, así que
 * correrla de más, o después de que el organizador cerró, no hace nada.
 */
final class CierresPorFecha
{
    /** De a cuántas, como FinalizacionDeFunciones: una tanda no traba la tabla. */
    private const POR_TANDA = 100;

    public function __construct(
        private readonly RepositorioDeFunciones $funciones,
        private readonly RepositorioDeVotos $votos,
        private readonly RepositorioDeSalas $salas,
        private readonly RepositorioDeObras $obras,
        private readonly ?AcuerdosDeFunciones $acuerdos = null,
        private readonly ?LoggerInterface $log = null,
    ) {
    }

    /**
     * @return int cuántas votaciones cerró
     */
    public function cerrarVotaciones(DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        $cerradas = 0;
        do {
            $vencidas = $this->funciones->conVotacionVencida($ahora, self::POR_TANDA);
            foreach ($vencidas as $id) {
                $funcion = $this->funciones->buscar($id);
                // El organizador la pudo cerrar o cancelar entre la consulta y acá.
                if ($funcion === null || $funcion->estado !== EstadoFuncion::EnVotacionTitulos) {
                    continue;
                }
                $this->cerrarVotacion($funcion);
                ++$cerradas;
            }
        // Cada vencida sale de la consulta al cerrarla, así que la próxima tanda trae otras.
        } while (count($vencidas) === self::POR_TANDA);

        return $cerradas;
    }

    /**
     * Termina la votación con las obras más votadas que entran juntas en la duración de la función
     * de la sala, hasta las películas por función, con los parámetros que la sala tiene ahora
     * (EF-05.1, EF-05.4), y la guarda en acuerdo de fecha. Es el mismo cierre para el organizador
     * (ServicioDeFuncionesDelOrganizador::cerrarVotacion()) y para la tarea: así los dos eligen
     * igual, también al desempatar.
     *
     * Si no entra ninguna (una votación abierta antes de controlar las candidatas, o una sala que
     * achicó la duración), queda la más votada sola y el log lo avisa, para revisar esa sala.
     */
    public function cerrarVotacion(Funcion $funcion): void
    {
        $sala = $this->salas->buscar($funcion->salaId)
            ?? throw new LogicException("La función {$funcion->id} es de una sala que no existe.");
        $duraciones = array_column($this->obras->todas(), 'duracionMinutos', 'id');
        $maxima = $sala->datos->duracionFuncion;
        $elegidas = $this->votos->masVotadas($funcion->id, $funcion->obrasIds, $sala->datos->peliculasPorFuncion, $duraciones, $maxima);
        if (array_sum(array_map(static fn (string $id): int => $duraciones[$id] ?? 0, $elegidas)) > $maxima) {
            $this->log?->warning(
                'Votación {funcion} cerrada con la más votada aunque ninguna candidata entra en la duración de la sala {sala} ({maxima} minutos).',
                ['funcion' => $funcion->id, 'sala' => $sala->id, 'maxima' => $maxima],
            );
        }
        $funcion->cerrarVotacionTitulos($elegidas);
        $this->funciones->guardar($funcion);
    }

    /**
     * Por qué la función, si empezara en $inicio, se pisaría con otra programada de su sala o no
     * dejaría el tiempo entre funciones (EF-05.5); null si entra. Dura lo que duran sus obras
     * elegidas, como las demás de la sala. Lo usan proponer y confirmar el acuerdo, a mano y acá.
     */
    public function motivoParaPisarse(Funcion $funcion, DateTimeImmutable $inicio): ?string
    {
        $sala = $this->salas->buscar($funcion->salaId)
            ?? throw new LogicException("La función {$funcion->id} es de una sala que no existe.");

        return $sala->motivoParaPisarse(
            $inicio,
            $this->obras->duracionDe($funcion->obrasIds),
            $this->funciones->ocupadoEnLaSala($sala->id, $funcion->id),
        );
    }

    /**
     * Para revisar la sala: la tarea canceló una función porque la franja ganadora se pisaba.
     */
    private function avisarPisada(Funcion $funcion, string $pisada): void
    {
        $this->log?->warning('La función {funcion} de la sala {sala} se cancela: la franja ganadora {pisada}', [
            'funcion' => $funcion->id,
            'sala' => $funcion->salaId,
            'pisada' => $pisada,
        ]);
    }

    /**
     * Los acuerdos de fecha que pasaron su fecha límite (EF-03.2). El acuerdo vive en funciones:
     * se le pregunta por cada función en acuerdo, y al leerlo funciones ya cierra sin mínimo el que
     * venció sin llegar (ServicioDeAcuerdos::cerrarSiVencio()).
     *
     * Lo que se le pide a funciones va antes de guardar la función, como en
     * FinalizacionDeFunciones: si funciones no responde, la excepción corta la pasada, la función
     * sigue en acuerdo y la próxima lo vuelve a intentar. Repetir cada pedido no duplica nada.
     *
     * @return array{programadas: int, canceladas: int}
     *
     * @throws ExcepcionHttp|RuntimeException si funciones no responde o responde otra cosa
     */
    public function cerrarAcuerdos(DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        $hechas = ['programadas' => 0, 'canceladas' => 0];
        if ($this->acuerdos === null) {
            return $hechas;
        }
        $desde = '';
        do {
            $ids = $this->funciones->enAcuerdoDeFecha(self::POR_TANDA, $desde);
            foreach ($ids as $id) {
                $desde = $id;
                $resultado = $this->cerrarAcuerdo($id, $ahora);
                if ($resultado !== null) {
                    ++$hechas[$resultado];
                }
            }
        } while (count($ids) === self::POR_TANDA);

        return $hechas;
    }

    /**
     * @return 'programadas'|'canceladas'|null lo que pasó con la función; null si no venció o no
     *                                          le toca a la tarea
     */
    private function cerrarAcuerdo(string $funcionId, DateTimeImmutable $ahora): ?string
    {
        $acuerdos = $this->acuerdos ?? throw new LogicException('Sin el cliente de acuerdos.');
        $acuerdo = $acuerdos->obtenerPorFuncion($funcionId);
        $funcion = $this->funciones->buscar($funcionId);
        // Sin franjas propuestas todavía no hay fecha límite: lo sigue el organizador.
        if ($acuerdo === null || $funcion === null || $funcion->estado !== EstadoFuncion::EnAcuerdoFecha) {
            return null;
        }
        $limite = self::fecha($acuerdo['fecha_limite'] ?? null);
        $estado = $acuerdo['estado'] ?? null;

        // Confirmado en funciones pero no programado acá: la pasada anterior se cortó entre los
        // dos pasos. Se termina con la franja que quedó ganadora.
        if ($estado === 'confirmado') {
            $inicio = self::fecha(is_array($acuerdo['franja_ganadora'] ?? null) ? ($acuerdo['franja_ganadora']['inicio'] ?? null) : null);
            if ($inicio === null || $inicio <= $ahora) {
                return null;
            }
            // Si en el medio se programó otra en ese horario (EF-05.5), se cancela. Funciones no
            // avisa a los de un acuerdo confirmado, así que queda en el log.
            $pisada = $this->motivoParaPisarse($funcion, $inicio);
            if ($pisada !== null) {
                $this->avisarPisada($funcion, $pisada);
                $funcion->cancelar();
                $this->funciones->guardar($funcion);

                return 'canceladas';
            }
            $funcion->fijarFechaHora($inicio, $ahora);
            $this->funciones->guardar($funcion);

            return 'programadas';
        }

        $motivo = 'sin_minimo';
        if ($estado === 'abierto' && $limite !== null && $limite < $ahora && AcuerdosDeFunciones::alcanzoElMinimo($acuerdo)) {
            $inicio = self::fecha((AcuerdosDeFunciones::franjaQueLidera($acuerdo) ?? [])['inicio'] ?? null);
            // La que se pisa con otra función de la sala (EF-05.5) tampoco se programa: se cancela
            // como la que ya empezó, pero el aviso dice el motivo verdadero.
            $pisada = $inicio !== null && $inicio > $ahora ? $this->motivoParaPisarse($funcion, $inicio) : null;
            if ($pisada !== null) {
                $this->avisarPisada($funcion, $pisada);
                $motivo = 'horario_ocupado';
            } elseif ($inicio !== null && $inicio > $ahora) {
                $acuerdos->confirmar((string) $acuerdo['id']);
                $funcion->fijarFechaHora($inicio, $ahora);
                $this->funciones->guardar($funcion);

                return 'programadas';
            }
            // La franja que llegó al mínimo ya empezó, o se pisa: no se puede programar, y la
            // función se cancela como si no hubiera llegado (es la regla del cierre a mano).
            $acuerdo = $acuerdos->cerrarSinMinimo((string) $acuerdo['id']);
            $estado = 'sin_minimo';
        }

        // Sólo el que cerró sin mínimo por vencer: el que el organizador cerró a mano antes de la
        // fecha límite es para proponer otras franjas (E3-13.F8), y ése no se cancela.
        $cerrado = self::fecha($acuerdo['cerrado_en'] ?? null);
        if ($estado !== 'sin_minimo' || $limite === null || $limite >= $ahora || ($cerrado !== null && $cerrado <= $limite)) {
            return null;
        }
        $titulos = $this->funciones->resumen($funcionId)->titulos ?? [];
        $acuerdos->avisarCancelacion((string) $acuerdo['id'], $titulos === [] ? 'la función' : implode(' + ', $titulos), $motivo);
        $funcion->cancelar();
        $this->funciones->guardar($funcion);

        return 'canceladas';
    }

    private static function fecha(mixed $texto): ?DateTimeImmutable
    {
        if (!is_string($texto)) {
            return null;
        }
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $texto);

        return $fecha === false ? null : $fecha;
    }
}
