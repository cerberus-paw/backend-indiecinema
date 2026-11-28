<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\Mes;
use IndieCinema\Recaudacion\Modelo\Monto;
use IndieCinema\Recaudacion\Modelo\ParteDelReparto;
use IndieCinema\Recaudacion\Modelo\ParteLiquidada;
use IndieCinema\Recaudacion\Modelo\Reparto;
use IndieCinema\Recaudacion\Modelo\RolDelReparto;

/**
 * Los repartos de lo cobrado de cada función, con sus partes (EF-08.2).
 *
 * Las fechas van como las manda la aplicación, sin pasarlas a UTC, por lo mismo que en
 * RepositorioDeParametrosDeReparto: finalizada_en es la de programación, que guarda la hora del
 * sitio, y las dos bases corren con la misma zona.
 */
final class RepositorioDeRepartos
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * El reparto y sus partes juntos: un reparto a medio guardar repartiría de menos. Si la
     * función ya tenía reparto, la clave única de funcion_id lo rechaza y no se guarda nada.
     */
    public function guardar(Reparto $reparto): void
    {
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($reparto): void {
            $base->ejecutar(
                'INSERT INTO reparto (id, funcion_id, cobrado, comision, parametro_id, finalizada_en, calculado_en,
                                      funcion_fecha_hora, sala_nombre, titulos)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $reparto->id,
                    $reparto->funcionId,
                    $reparto->cobrado(),
                    $reparto->comision(),
                    $reparto->parametroId,
                    $reparto->finalizadaEn,
                    $reparto->calculadoEn,
                    $reparto->funcionFechaHora,
                    $reparto->salaNombre,
                    $reparto->titulos,
                ],
            );
            foreach ($reparto->partes as $parte) {
                $base->ejecutar(
                    'INSERT INTO reparto_parte (id, reparto_id, rol, beneficiario_id, monto) VALUES (?, ?, ?, ?, ?)',
                    [Uuid::nuevo(), $reparto->id, $parte->rol->value, $parte->beneficiarioId, $parte->monto()],
                );
            }
        });
    }

    /**
     * La fecha de finalización del último reparto hecho: por ahí sigue la tarea, que le pide a
     * programación las funciones finalizadas desde ese momento. Null si todavía no se repartió
     * nada, y entonces la tarea arranca del principio del sitio.
     */
    public function ultimaFinalizacion(): ?DateTimeImmutable
    {
        $ultima = $this->base->valor('SELECT MAX(finalizada_en) FROM reparto');

        return $ultima === null ? null : new DateTimeImmutable((string) $ultima);
    }

    /**
     * Cuáles de esas funciones ya tienen reparto: las que la pasada anterior ya hizo. El cursor
     * vuelve a traerlas, porque es «desde» e incluye el momento de la última.
     *
     * @param list<string> $funcionesIds
     *
     * @return list<string>
     */
    public function yaRepartidas(array $funcionesIds): array
    {
        if ($funcionesIds === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($funcionesIds), '?'));

        return array_map(
            static fn (array $fila): string => (string) $fila['funcion_id'],
            $this->base->filas("SELECT funcion_id FROM reparto WHERE funcion_id IN ({$marcas})", $funcionesIds),
        );
    }

    /**
     * Lo que le toca a cada beneficiario en el mes: la suma de sus partes de los repartos que se
     * finalizaron en él, que es con lo que se arma la liquidación (EF-08.3). La parte de la
     * plataforma no cuenta: es el sitio, no hay a quién transferirle.
     *
     * @return array<string, int> beneficiario => centavos, del que más cobra al que menos
     */
    public function sumasDelMes(Mes $mes): array
    {
        $sumas = [];
        foreach ($this->base->filas(
            'SELECT p.beneficiario_id, SUM(p.monto) AS total
             FROM reparto_parte p
             JOIN reparto r ON r.id = p.reparto_id
             WHERE p.beneficiario_id IS NOT NULL AND r.finalizada_en >= ? AND r.finalizada_en < ?
             GROUP BY p.beneficiario_id
             ORDER BY total DESC, p.beneficiario_id',
            [$mes->empieza(), $mes->termina()],
        ) as $fila) {
            $sumas[(string) $fila['beneficiario_id']] = Monto::centavos((string) $fila['total']);
        }

        return $sumas;
    }

    /**
     * El detalle de la liquidación de un beneficiario: una línea por parte suya en el mes, de la
     * función más vieja a la más nueva.
     *
     * @return list<ParteLiquidada>
     */
    public function detalleDelMes(string $beneficiarioId, Mes $mes): array
    {
        return array_map(
            static fn (array $fila): ParteLiquidada => new ParteLiquidada(
                (string) $fila['funcion_id'],
                $fila['funcion_fecha_hora'] === null ? null : new DateTimeImmutable((string) $fila['funcion_fecha_hora']),
                $fila['sala_nombre'] === null ? null : (string) $fila['sala_nombre'],
                $fila['titulos'] === null ? null : (string) $fila['titulos'],
                Monto::centavos((string) $fila['cobrado']),
                Monto::centavos((string) $fila['comision']),
                RolDelReparto::from((string) $fila['rol']),
                Monto::centavos((string) $fila['monto']),
            ),
            $this->base->filas(
                'SELECT r.funcion_id, r.funcion_fecha_hora, r.sala_nombre, r.titulos, r.cobrado, r.comision, p.rol, p.monto
                 FROM reparto_parte p
                 JOIN reparto r ON r.id = p.reparto_id
                 WHERE p.beneficiario_id = ? AND r.finalizada_en >= ? AND r.finalizada_en < ?
                 ORDER BY r.funcion_fecha_hora, r.funcion_id, FIELD(p.rol, \'sala\', \'dueno\')',
                [$beneficiarioId, $mes->empieza(), $mes->termina()],
            ),
        );
    }

    /**
     * Las partes de una función, de la sala a la plataforma: para las liquidaciones (EF-08.3) y
     * para comprobar el reparto.
     *
     * @return list<ParteDelReparto>
     */
    public function partesDe(string $funcionId): array
    {
        return array_map(
            static fn (array $fila): ParteDelReparto => new ParteDelReparto(
                RolDelReparto::from((string) $fila['rol']),
                $fila['beneficiario_id'] === null ? null : (string) $fila['beneficiario_id'],
                Monto::centavos((string) $fila['monto']),
            ),
            $this->base->filas(
                'SELECT p.rol, p.beneficiario_id, p.monto
                 FROM reparto_parte p
                 JOIN reparto r ON r.id = p.reparto_id
                 WHERE r.funcion_id = ?
                 ORDER BY FIELD(p.rol, \'sala\', \'dueno\', \'plataforma\'), p.beneficiario_id',
                [$funcionId],
            ),
        );
    }

    /**
     * Cómo se repartió cada una de esas funciones, sumado por rol: para el resultado de cada sala
     * y de la plataforma en los reportes (EF-22.1). Las que todavía no se repartieron no vienen.
     *
     * @param list<string> $funcionesIds
     *
     * @return array<string, array{cobrado: int, comision: int, sala: int, dueno: int, plataforma: int}> por función, en centavos
     */
    public function sumasPorRol(array $funcionesIds): array
    {
        $sumas = [];
        foreach (array_chunk(array_values(array_unique($funcionesIds)), 500) as $tanda) {
            $marcas = implode(', ', array_fill(0, count($tanda), '?'));
            foreach ($this->base->filas(
                "SELECT r.funcion_id, r.cobrado, r.comision,
                        COALESCE(SUM(CASE WHEN p.rol = 'sala' THEN p.monto END), 0) AS sala,
                        COALESCE(SUM(CASE WHEN p.rol = 'dueno' THEN p.monto END), 0) AS dueno,
                        COALESCE(SUM(CASE WHEN p.rol = 'plataforma' THEN p.monto END), 0) AS plataforma
                 FROM reparto r
                 LEFT JOIN reparto_parte p ON p.reparto_id = r.id
                 WHERE r.funcion_id IN ({$marcas})
                 GROUP BY r.id, r.funcion_id, r.cobrado, r.comision",
                $tanda,
            ) as $fila) {
                $sumas[(string) $fila['funcion_id']] = [
                    'cobrado' => Monto::centavos((string) $fila['cobrado']),
                    'comision' => Monto::centavos((string) $fila['comision']),
                    'sala' => Monto::centavos((string) $fila['sala']),
                    'dueno' => Monto::centavos((string) $fila['dueno']),
                    'plataforma' => Monto::centavos((string) $fila['plataforma']),
                ];
            }
        }

        return $sumas;
    }
}
