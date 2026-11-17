<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\Monto;
use IndieCinema\Recaudacion\Modelo\ParteDelReparto;
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
                'INSERT INTO reparto (id, funcion_id, cobrado, parametro_id, finalizada_en, calculado_en)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $reparto->id,
                    $reparto->funcionId,
                    $reparto->cobrado(),
                    $reparto->parametroId,
                    $reparto->finalizadaEn,
                    $reparto->calculadoEn,
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
}
