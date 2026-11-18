<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Recaudacion\Modelo\Cupon;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeCupones;
use LogicException;
use RuntimeException;

/**
 * Los puntos de un espectador y el canje por cupones (EF-17.1).
 *
 * El saldo se calcula cada vez: las asistencias, que guarda funciones, por los puntos de cada una,
 * menos lo que costaron los cupones canjeados. No hay una tabla de saldos que se pueda desfasar.
 */
final class PuntosDelEspectador
{
    public function __construct(
        private readonly ClienteInterno $cliente,
        private readonly RepositorioDeCupones $cupones,
    ) {
    }

    /**
     * El saldo, los movimientos (asistencias y canjes, del más nuevo al más viejo) y los cupones
     * con su estado.
     *
     * @return array{saldo: int, movimientos: list<array{fecha: DateTimeImmutable, puntos: int, motivo: string, detalle: string}>, cupones: list<array{cupon: Cupon, estado: string}>}
     *
     * @throws ExcepcionHttp 503 si funciones no responde
     */
    public function resumen(string $usuarioId, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        $asistencias = $this->asistencias($usuarioId);
        $cupones = $this->cupones->deUsuario($usuarioId);
        $enUso = $this->enUso($cupones);

        $movimientos = [];
        foreach ($asistencias as $asistencia) {
            $movimientos[] = ['fecha' => $asistencia['fecha'], 'puntos' => Cupon::PUNTOS_POR_ASISTENCIA, 'motivo' => 'asistencia', 'detalle' => $asistencia['codigo']];
        }
        foreach ($cupones as $cupon) {
            $movimientos[] = ['fecha' => $cupon->creadoEn, 'puntos' => -$cupon->puntos, 'motivo' => 'canje', 'detalle' => $cupon->codigo];
        }
        usort($movimientos, static fn (array $a, array $b): int => $b['fecha'] <=> $a['fecha']);

        return [
            'saldo' => count($asistencias) * Cupon::PUNTOS_POR_ASISTENCIA - $this->cupones->puntosGastados($usuarioId),
            'movimientos' => $movimientos,
            'cupones' => array_map(static fn (Cupon $cupon): array => [
                'cupon' => $cupon,
                'estado' => match (true) {
                    in_array($cupon->codigo, $enUso, true) => 'usado',
                    $cupon->vencido($ahora) => 'vencido',
                    default => 'disponible',
                },
            ], $cupones),
        ];
    }

    /**
     * Canjea puntos por un cupón, con el saldo bloqueado: dos canjes a la vez no gastan los mismos
     * puntos.
     *
     * @throws ReglaIncumplida si no le alcanzan los puntos
     * @throws ExcepcionHttp   503 si funciones no responde
     */
    public function canjear(string $usuarioId, DateTimeImmutable $ahora = new DateTimeImmutable()): Cupon
    {
        return $this->cupones->conElSaldoBloqueado($usuarioId, function () use ($usuarioId, $ahora): Cupon {
            $saldo = count($this->asistencias($usuarioId)) * Cupon::PUNTOS_POR_ASISTENCIA - $this->cupones->puntosGastados($usuarioId);
            if ($saldo < Cupon::PUNTOS_POR_CUPON) {
                throw new ReglaIncumplida('Todavía no te alcanzan los puntos: un cupón cuesta ' . Cupon::PUNTOS_POR_CUPON . " y tenés {$saldo}.");
            }
            for ($intento = 0; $intento < 5; ++$intento) {
                $cupon = Cupon::nuevo($usuarioId, $ahora);
                if ($this->cupones->agregar($cupon)) {
                    return $cupon;
                }
            }
            throw new LogicException('Salió cinco veces un código de cupón que ya existía: revisar el sorteo.');
        });
    }

    /**
     * @return list<array{codigo: string, fecha: DateTimeImmutable}>
     *
     * @throws ExcepcionHttp 503 si funciones no responde o contesta otra cosa
     */
    private function asistencias(string $usuarioId): array
    {
        $datos = $this->cliente->get('funciones', "/interno/usuarios/{$usuarioId}/asistencias");
        $lista = is_array($datos) ? ($datos['asistencias'] ?? null) : null;
        if (!is_array($lista)) {
            throw ExcepcionHttp::servicioNoDisponible();
        }
        $asistencias = [];
        foreach ($lista as $asistencia) {
            if (!is_array($asistencia) || !is_string($asistencia['codigo'] ?? null) || !is_string($asistencia['creada_en'] ?? null)) {
                throw new RuntimeException('funciones devolvió un formato inesperado en GET /interno/usuarios/{id}/asistencias.');
            }
            $asistencias[] = ['codigo' => $asistencia['codigo'], 'fecha' => new DateTimeImmutable($asistencia['creada_en'])];
        }

        return $asistencias;
    }

    /**
     * @param list<Cupon> $cupones
     *
     * @return list<string> los que están en una reserva que no se canceló
     */
    private function enUso(array $cupones): array
    {
        if ($cupones === []) {
            return [];
        }
        $datos = $this->cliente->get('funciones', '/interno/cupones/en-uso', ['codigos' => implode(',', array_map(static fn (Cupon $cupon): string => $cupon->codigo, array_slice($cupones, 0, 100)))]);
        $lista = is_array($datos) ? ($datos['en_uso'] ?? null) : null;

        return is_array($lista) ? array_values(array_filter($lista, is_string(...))) : [];
    }
}
