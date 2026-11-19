<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Recaudacion\Modelo\Liquidacion;
use IndieCinema\Recaudacion\Modelo\Mes;
use IndieCinema\Recaudacion\Modelo\ParteLiquidada;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeLiquidaciones;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeRepartos;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Lo que se le liquida a cada organizador y a cada dueño por mes (EF-08.3).
 *
 * No es otra cuenta: la liquidación es la suma de las partes que ya repartió EF-08.2, agrupadas
 * por beneficiario y por el mes en que se finalizó cada función. La arma la misma tarea del cron
 * cada vez que reparte algo, así la pantalla lee una tabla y no suma nada al vuelo.
 *
 * La transferencia se hace fuera del sitio: el administrador marca la liquidación como transferida
 * con la fecha y una nota, y desde ahí su monto no se vuelve a mover.
 */
final class ServicioDeLiquidaciones
{
    public function __construct(
        private readonly RepositorioDeLiquidaciones $liquidaciones,
        private readonly RepositorioDeRepartos $repartos,
        // La tarea del cron (bin/repartir.php) sólo arma: no marca nada, y no necesita el log.
        private readonly LoggerInterface $log = new NullLogger(),
    ) {
    }

    /**
     * Rearma las liquidaciones pendientes de esos meses con lo que suman las partes repartidas.
     *
     * @param list<Mes> $meses
     *
     * @return int cuántas quedaron armadas o actualizadas
     */
    public function armar(array $meses): int
    {
        $armadas = 0;
        foreach (self::sinRepetir($meses) as $mes) {
            $armadas += $this->liquidaciones->armar($mes, $this->repartos->sumasDelMes($mes));
        }

        return $armadas;
    }

    /**
     * Las de un organizador o un dueño, del mes más nuevo al más viejo.
     *
     * @return list<Liquidacion>
     */
    public function delBeneficiario(string $beneficiarioId): array
    {
        return $this->liquidaciones->delBeneficiario($beneficiarioId);
    }

    /**
     * El detalle de un mes: qué función y qué parte, de la más vieja a la más nueva.
     *
     * @return list<ParteLiquidada>
     */
    public function detalle(string $beneficiarioId, Mes $mes): array
    {
        return $this->repartos->detalleDelMes($beneficiarioId, $mes);
    }

    /**
     * Las de un mes, primero las que falta transferir: lo que ve el administrador.
     *
     * @return list<Liquidacion>
     */
    public function delMes(Mes $mes): array
    {
        return $this->liquidaciones->delMes($mes);
    }

    /**
     * Los meses que tienen alguna liquidación, del más nuevo al más viejo.
     *
     * @return list<Mes>
     */
    public function meses(): array
    {
        return $this->liquidaciones->meses();
    }

    public function buscar(string $id): ?Liquidacion
    {
        return $this->liquidaciones->buscar($id);
    }

    /**
     * @return bool si ésta la marcó; false si ya estaba transferida, y entonces no se pisa la
     *              fecha ni la nota de la primera vez
     */
    public function marcarTransferida(Usuario $administrador, string $id, ?string $nota, DateTimeImmutable $ahora = new DateTimeImmutable()): bool
    {
        $marcada = $this->liquidaciones->marcarTransferida($id, $nota, $ahora);
        if ($marcada) {
            // La auditoría que prometió la propuesta (EF-18.1): quién la marcó, como los cambios de
            // rol en cuentas y las decisiones de moderación. Sólo los ids, sin datos personales.
            $this->log->notice('{administrador} marcó como transferida la liquidación {liquidacion}.', [
                'administrador' => $administrador->id,
                'liquidacion' => $id,
            ]);
        }

        return $marcada;
    }

    /**
     * @param list<Mes> $meses
     *
     * @return list<Mes>
     */
    private static function sinRepetir(array $meses): array
    {
        $unicos = [];
        foreach ($meses as $mes) {
            $unicos[$mes->comoTexto()] = $mes;
        }

        return array_values($unicos);
    }
}
