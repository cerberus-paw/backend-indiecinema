<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\EstadoDeLiquidacion;
use IndieCinema\Recaudacion\Modelo\Liquidacion;
use IndieCinema\Recaudacion\Modelo\Mes;
use IndieCinema\Recaudacion\Modelo\Monto;
use RuntimeException;

/**
 * Las liquidaciones por beneficiario y mes (EF-08.3).
 *
 * Las fechas van como las manda la aplicación, sin pasarlas a UTC, por lo mismo que en los otros
 * repositorios de recaudación: se comparan con las de programación, que guarda la hora del sitio.
 */
final class RepositorioDeLiquidaciones
{
    private const COLUMNAS = 'id, beneficiario_id, periodo, monto, estado, transferida_en, nota';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Deja las liquidaciones del mes con lo que suman las partes repartidas: agrega las que no
     * estaban y actualiza el monto de las que siguen pendientes. Una ya transferida no se toca,
     * porque su monto es el que se pagó.
     *
     * Las dos cosas van en una transacción: el mes se arma entero o no se arma.
     *
     * @param array<string, int> $sumas beneficiario => centavos
     *
     * @return int cuántas quedaron armadas o actualizadas
     */
    public function armar(Mes $mes, array $sumas): int
    {
        if ($sumas === []) {
            return 0;
        }

        return (int) $this->base->enTransaccion(function (BaseDeDatos $base) use ($mes, $sumas): int {
            $armadas = 0;
            foreach ($sumas as $beneficiarioId => $centavos) {
                // Con el alias de la fila nueva, que es como se escribe desde MySQL 8.0.19;
                // VALUES() quedó deprecado. Las columnas de la que ya está van con el nombre de la
                // tabla, porque con el alias quedan ambiguas. Si ya está transferida, no se mueve.
                $base->ejecutar(
                    "INSERT INTO liquidacion (id, beneficiario_id, periodo, monto)
                     VALUES (?, ?, ?, ?) AS nueva
                     ON DUPLICATE KEY UPDATE
                         monto = IF(liquidacion.estado = 'pendiente', nueva.monto, liquidacion.monto)",
                    [Uuid::nuevo(), $beneficiarioId, $mes->comoTexto(), Monto::comoDecimal($centavos)],
                );
                ++$armadas;
            }

            return $armadas;
        });
    }

    /**
     * Las de un beneficiario, del mes más nuevo al más viejo: lo que ve el organizador o el dueño.
     *
     * @return list<Liquidacion>
     */
    public function delBeneficiario(string $beneficiarioId): array
    {
        return array_map(self::liquidacion(...), $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM liquidacion WHERE beneficiario_id = ? ORDER BY periodo DESC',
            [$beneficiarioId],
        ));
    }

    /**
     * Las de un mes, primero las que falta transferir y después por monto: lo que ve el
     * administrador.
     *
     * @return list<Liquidacion>
     */
    public function delMes(Mes $mes): array
    {
        return array_map(self::liquidacion(...), $this->base->filas(
            'SELECT ' . self::COLUMNAS . " FROM liquidacion
             WHERE periodo = ?
             ORDER BY estado = 'transferida', monto DESC, beneficiario_id",
            [$mes->comoTexto()],
        ));
    }

    /**
     * Los meses que tienen alguna liquidación, del más nuevo al más viejo: con eso el
     * administrador elige cuál mirar.
     *
     * @return list<Mes>
     */
    public function meses(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $fila): ?Mes => Mes::desdeTexto((string) $fila['periodo']),
            $this->base->filas('SELECT DISTINCT periodo FROM liquidacion ORDER BY periodo DESC'),
        )));
    }

    public function buscar(string $id): ?Liquidacion
    {
        $fila = $this->base->fila('SELECT ' . self::COLUMNAS . ' FROM liquidacion WHERE id = ?', [$id]);

        return $fila === null ? null : self::liquidacion($fila);
    }

    /**
     * La marca del administrador: la transferencia se hizo fuera del sitio. Sólo pasa una
     * pendiente a transferida, así que marcarla dos veces no mueve la fecha ni la nota.
     *
     * @return bool si ésta la marcó (false si ya estaba transferida o no existe)
     */
    public function marcarTransferida(string $id, ?string $nota, DateTimeImmutable $cuando): bool
    {
        return $this->base->ejecutar(
            "UPDATE liquidacion SET estado = 'transferida', transferida_en = ?, nota = ?
             WHERE id = ? AND estado = 'pendiente'",
            [$cuando, $nota, $id],
        ) > 0;
    }

    /**
     * @param array<string, mixed> $fila
     *
     * @throws RuntimeException si el período guardado no es un mes (la columna es CHAR(7))
     */
    private static function liquidacion(array $fila): Liquidacion
    {
        return new Liquidacion(
            (string) $fila['id'],
            (string) $fila['beneficiario_id'],
            Mes::desdeTexto((string) $fila['periodo'])
                ?? throw new RuntimeException('La liquidación guardada tiene un período que no es un mes.'),
            Monto::centavos((string) $fila['monto']),
            EstadoDeLiquidacion::from((string) $fila['estado']),
            $fila['transferida_en'] === null ? null : new DateTimeImmutable((string) $fila['transferida_en']),
            $fila['nota'] === null ? null : (string) $fila['nota'],
        );
    }
}
