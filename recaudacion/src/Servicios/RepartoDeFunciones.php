<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\Monto;
use IndieCinema\Recaudacion\Modelo\Reparto;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeRepartos;
use RuntimeException;

/**
 * Reparte lo cobrado de cada función paga que terminó (EF-08.2). La corre el cron del servidor con
 * bin/repartir.php, una vez por hora.
 *
 * Le pide a programación las funciones finalizadas desde la última que repartió
 * (GET /interno/funciones/finalizadas?desde=…, por fecha de finalización y no por la de la función)
 * y a funciones lo cobrado de cada una (GET /interno/cobros), que ya viene neto de reembolsos. Con
 * los porcentajes que regían en la fecha de la función (EF-08.1) guarda el reparto y sus partes.
 *
 * Correrla de más no duplica nada: la función ya repartida se saltea, y la clave única de
 * reparto.funcion_id es la red. Si uno de los dos subsistemas no responde, no reparte nada y la
 * próxima pasada vuelve a intentarlo; si una función no se puede repartir, corta ahí y no sigue con
 * las que vienen después, para no dejarla atrás del cursor sin repartir.
 */
final class RepartoDeFunciones
{
    /**
     * De dónde arranca la primera pasada: anterior a cualquier función del sitio, como la vigencia
     * del parámetro que carga la migración.
     */
    private const DESDE_EL_PRINCIPIO = '2000-01-01 00:00:00';

    public function __construct(
        private readonly FuncionesFinalizadas $finalizadas,
        private readonly CobrosDeFunciones $cobros,
        private readonly ServicioDeParametrosDeReparto $parametros,
        private readonly RepositorioDeRepartos $repartos,
    ) {
    }

    /**
     * @return array{repartidas: int, sinPlata: int, quedanMas: bool} las que repartieron plata, las
     *                                                               que no tenían qué repartir y si
     *                                                               quedaron más para la próxima
     *
     * @throws RuntimeException si una función no se puede repartir, o si programación o funciones
     *                          responden algo que no se entiende
     */
    public function repartir(DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        $desde = $this->repartos->ultimaFinalizacion() ?? new DateTimeImmutable(self::DESDE_EL_PRINCIPIO);
        $respuesta = $this->finalizadas->desdeLaFinalizacion($desde);
        $pendientes = $this->sinReparto($respuesta['funciones']);
        if ($pendientes === []) {
            return ['repartidas' => 0, 'sinPlata' => 0, 'quedanMas' => !$respuesta['completas']];
        }

        // Lo cobrado de las pagas, en tandas de cien: a las de a la gorra no hace falta pedírselo.
        $pagas = array_values(array_filter($pendientes, static fn (FuncionFinalizada $funcion): bool => !$funcion->esALaGorra()));
        $cobrado = $this->cobros->deVarias(array_map(static fn (FuncionFinalizada $funcion): string => $funcion->id, $pagas));

        $repartidas = 0;
        $sinPlata = 0;
        foreach ($pendientes as $funcion) {
            $neto = $funcion->esALaGorra() ? 0 : Monto::centavos($cobrado[$funcion->id]->neto);
            $reparto = $neto === 0
                ? Reparto::sinPlata($funcion->id, $funcion->finalizadaEn, $ahora)
                : $this->reparto($funcion, $neto, $ahora);
            $this->repartos->guardar($reparto);
            if ($reparto->sinPartes()) {
                ++$sinPlata;
            } else {
                ++$repartidas;
            }
        }

        return ['repartidas' => $repartidas, 'sinPlata' => $sinPlata, 'quedanMas' => !$respuesta['completas']];
    }

    /**
     * El reparto de una función con plata cobrada: con el parámetro que regía en su fecha, no el de
     * hoy, así un cambio de porcentajes no mueve lo que ya pasó.
     *
     * @throws RuntimeException si no hay parámetro para esa fecha o si la función no proyectó nada
     */
    private function reparto(FuncionFinalizada $funcion, int $neto, DateTimeImmutable $ahora): Reparto
    {
        $parametro = $this->parametros->vigenteEn($funcion->fechaHora);
        if ($parametro === null) {
            throw new RuntimeException("No hay parámetro de reparto vigente en la fecha de la función {$funcion->id}.");
        }
        $duenos = $funcion->duenosDeLasObras();
        if ($duenos === []) {
            throw new RuntimeException("La función {$funcion->id} se finalizó sin ninguna obra proyectada: no hay cómo repartirla.");
        }

        return Reparto::calcular(
            $funcion->id,
            $funcion->finalizadaEn,
            $neto,
            $funcion->organizadorId,
            $duenos,
            $parametro,
            $ahora,
        );
    }

    /**
     * Las que todavía no tienen reparto. El cursor incluye su momento, así que la última de la
     * pasada anterior vuelve en esta: acá se va.
     *
     * @param list<FuncionFinalizada> $funciones
     *
     * @return list<FuncionFinalizada>
     */
    private function sinReparto(array $funciones): array
    {
        $ya = $this->repartos->yaRepartidas(array_map(static fn (FuncionFinalizada $funcion): string => $funcion->id, $funciones));

        return array_values(array_filter(
            $funciones,
            static fn (FuncionFinalizada $funcion): bool => !in_array($funcion->id, $ya, true),
        ));
    }
}
