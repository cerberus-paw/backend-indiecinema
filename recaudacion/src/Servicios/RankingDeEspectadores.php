<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\EspectadorDeSala;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\RankingDeSala;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * El ranking de espectadores de cada sala (EF-22.19): quiénes más participan, con un puntaje que
 * dice de dónde sale. Tomás lo pidió el 23/11, para que lo vea sólo quien tiene que verlo: está en
 * los reportes, que ven el organizador (de sus salas) y el administrador. Ningún espectador lo ve.
 *
 * Cruza lo que sabe programación (quiénes votaron en cada función) con lo que sabe funciones
 * (hasta dónde llegó cada uno: si fue, si calificó y cuánto pagó) y le pone nombre con cuentas.
 */
final class RankingDeEspectadores
{
    /** Cuántos se muestran por sala. */
    public const PRIMEROS = 10;

    public function __construct(
        private readonly HastaDondeLlegaron $hastaDonde,
        private readonly UsuariosDeCuentas $usuarios,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return array{salas: list<RankingDeSala>, avisos: list<string>} las salas en orden de nombre
     */
    public function delPeriodo(DatosDelReporte $datos): array
    {
        $avisos = [];
        try {
            $llegadas = $this->hastaDonde->enLasFunciones(array_map(static fn (FuncionFinalizada $funcion): string => $funcion->id, $datos->funciones));
        } catch (ExcepcionHttp|RuntimeException|LogicException $error) {
            $this->log->warning('El reporte salió sin el ranking: {error}', ['error' => $error->getMessage()]);
            $llegadas = [];
            $avisos[] = 'Funciones no respondió: el ranking cuenta sólo los votos.';
        }

        /** @var array<string, array{nombre: string, espectadores: array<string, array{fue: int, voto: int, califico: int, pagado: int}>}> $porSala */
        $porSala = [];
        foreach ($datos->funciones as $funcion) {
            $sala = $porSala[$funcion->salaId] ?? ['nombre' => $funcion->salaNombre, 'espectadores' => []];
            $vacio = ['fue' => 0, 'voto' => 0, 'califico' => 0, 'pagado' => 0];
            foreach (array_unique($funcion->votantesIds) as $votante) {
                $sala['espectadores'][$votante] = $sala['espectadores'][$votante] ?? $vacio;
                ++$sala['espectadores'][$votante]['voto'];
            }
            foreach ($llegadas[$funcion->id] ?? [] as $usuario => $llegada) {
                $espectador = $sala['espectadores'][$usuario] ?? $vacio;
                if ($llegada['paso'] >= HastaDondeLlegaron::PASO['fue']) {
                    ++$espectador['fue'];
                }
                if ($llegada['paso'] >= HastaDondeLlegaron::PASO['califico']) {
                    ++$espectador['califico'];
                }
                if ($llegada['paso'] >= HastaDondeLlegaron::PASO['pago']) {
                    $espectador['pagado'] += $llegada['centavos'];
                }
                $sala['espectadores'][(string) $usuario] = $espectador;
            }
            $porSala[$funcion->salaId] = $sala;
        }

        // Los nombres, de una vez para todas las salas: sólo los de los que entran en algún ranking.
        $rankings = [];
        foreach ($porSala as $salaId => $sala) {
            $espectadores = [];
            foreach ($sala['espectadores'] as $usuario => $hizo) {
                $espectador = new EspectadorDeSala((string) $usuario, null, $hizo['fue'], $hizo['voto'], $hizo['califico'], $hizo['pagado']);
                if ($espectador->puntaje() > 0) {
                    $espectadores[] = $espectador;
                }
            }
            usort($espectadores, static fn (EspectadorDeSala $a, EspectadorDeSala $b): int => [$b->puntaje(), $b->pagado, $a->usuarioId] <=> [$a->puntaje(), $a->pagado, $b->usuarioId]);
            $rankings[(string) $salaId] = [$sala['nombre'], array_slice($espectadores, 0, self::PRIMEROS), count($espectadores)];
        }
        $ids = [];
        foreach ($rankings as [, $primeros]) {
            foreach ($primeros as $espectador) {
                $ids[] = $espectador->usuarioId;
            }
        }
        $nombres = $this->usuarios->nombresDe($ids);
        if ($ids !== [] && $nombres === []) {
            $avisos[] = 'Cuentas no respondió: el ranking sale sin los nombres.';
        }

        $salas = [];
        foreach ($rankings as $salaId => [$nombre, $primeros, $participaron]) {
            $salas[] = new RankingDeSala(
                $salaId,
                $nombre,
                array_map(static fn (EspectadorDeSala $e): EspectadorDeSala => new EspectadorDeSala($e->usuarioId, $nombres[$e->usuarioId] ?? null, $e->fue, $e->voto, $e->califico, $e->pagado), $primeros),
                $participaron,
            );
        }
        usort($salas, static fn (RankingDeSala $a, RankingDeSala $b): int => $a->salaNombre <=> $b->salaNombre);

        return ['salas' => $salas, 'avisos' => $avisos];
    }
}
