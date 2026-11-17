<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Recaudacion\Modelo\ParametroDeReparto;
use RuntimeException;

/**
 * Los parámetros del reparto, con su vigencia (EF-08.1).
 *
 * Las fechas van como las manda la aplicación, sin pasarlas a UTC: la base corre con la misma zona
 * que PHP (BaseDeDatos::conectar) y vigente_desde se compara con la fecha_hora de una función, que
 * programación guarda igual. Pasar una sola de las dos a UTC movería el reparto unas horas.
 */
final class RepositorioDeParametrosDeReparto
{
    private const COLUMNAS = 'id, sala, dueno, plataforma, vigente_desde, cargado_por, cargado_en';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function guardar(ParametroDeReparto $parametro): void
    {
        $this->base->ejecutar(
            'INSERT INTO parametro_reparto (id, sala, dueno, plataforma, vigente_desde, cargado_por, cargado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $parametro->id,
                ParametroDeReparto::comoDecimal($parametro->sala),
                ParametroDeReparto::comoDecimal($parametro->dueno),
                ParametroDeReparto::comoDecimal($parametro->plataforma),
                $parametro->vigenteDesde,
                $parametro->cargadoPor,
                $parametro->cargadoEn,
            ],
        );
    }

    /**
     * El que rige en ese momento: el de la vigencia más nueva que ya empezó. Null si no hay
     * ninguno anterior, que con la migración sólo puede pasar con una fecha previa al 2000.
     */
    public function vigenteEn(DateTimeImmutable $momento): ?ParametroDeReparto
    {
        $fila = $this->base->fila(
            'SELECT ' . self::COLUMNAS . ' FROM parametro_reparto
             WHERE vigente_desde <= ?
             ORDER BY vigente_desde DESC
             LIMIT 1',
            [$momento],
        );

        return $fila === null ? null : self::parametro($fila);
    }

    /**
     * Todos, del que rige último al primero: el historial de cambios de la pantalla. Arriba quedan
     * los que todavía no empezaron a valer, que es lo que el administrador acaba de cargar.
     *
     * @return list<ParametroDeReparto>
     */
    public function historial(int $cuantos): array
    {
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM parametro_reparto
             ORDER BY vigente_desde DESC
             LIMIT ?',
            [$cuantos],
        );

        return array_map(self::parametro(...), $filas);
    }

    /** Si ya hay uno que empieza a valer justo en ese momento (vigente_desde es única). */
    public function hayUnoVigenteDesde(DateTimeImmutable $momento): bool
    {
        return $this->base->valor('SELECT 1 FROM parametro_reparto WHERE vigente_desde = ?', [$momento]) !== null;
    }

    /**
     * @param array<string, mixed> $fila
     *
     * @throws RuntimeException si un porcentaje de la base no es uno (la columna es DECIMAL(5,2))
     */
    private static function parametro(array $fila): ParametroDeReparto
    {
        $centesimas = static function (string $columna) use ($fila): int {
            return ParametroDeReparto::centesimas((string) $fila[$columna])
                ?? throw new RuntimeException("El parámetro de reparto guardado tiene un «{$columna}» que no es un porcentaje.");
        };

        return new ParametroDeReparto(
            (string) $fila['id'],
            $centesimas('sala'),
            $centesimas('dueno'),
            $centesimas('plataforma'),
            new DateTimeImmutable((string) $fila['vigente_desde']),
            $fila['cargado_por'] === null ? null : (string) $fila['cargado_por'],
            new DateTimeImmutable((string) $fila['cargado_en']),
        );
    }
}
