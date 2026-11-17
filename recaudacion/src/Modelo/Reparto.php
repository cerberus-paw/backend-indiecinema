<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;
use IndieCinema\Nucleo\Uuid;

/**
 * Cómo se repartió lo cobrado de una función finalizada (EF-08.2): entre la sala, los dueños de
 * las obras que se proyectaron y la plataforma, con los porcentajes que regían en la fecha de la
 * función (EF-08.1).
 *
 * Las partes suman exactamente lo cobrado: cada una se saca con una división entera y lo que se
 * pierde en los redondeos queda en la de la plataforma. Reparte lo cobrado neto de reembolsos, que
 * es lo que funciones cuenta como cobrado: una entrada devuelta no se reparte.
 *
 * Una función que no repartió nada —a la gorra, o paga pero sin un peso cobrado— también se
 * registra, sin partes: así la tarea no la vuelve a mirar en cada pasada.
 */
final readonly class Reparto
{
    /**
     * @param list<ParteDelReparto> $partes
     *
     * @throws ReglaIncumplida si las partes no suman lo cobrado
     */
    private function __construct(
        public string $id,
        public string $funcionId,
        public int $cobradoCentavos,
        public ?string $parametroId,
        public DateTimeImmutable $finalizadaEn,
        public DateTimeImmutable $calculadoEn,
        public array $partes,
    ) {
        $repartido = array_sum(array_map(static fn (ParteDelReparto $parte): int => $parte->centavos, $partes));
        if ($repartido !== $cobradoCentavos) {
            throw new ReglaIncumplida('Las partes del reparto tienen que sumar lo cobrado de la función.');
        }
    }

    /**
     * @param DateTimeImmutable $finalizadaEn      cuándo pasó a finalizada en programación, que es
     *                                             por donde la tarea sigue desde su última pasada
     * @param string            $organizadorId     el de la sala: le toca la parte de la sala
     * @param list<string>      $duenosDeLasObras  el dueño de cada obra que se proyectó, repetido
     *                                             si proyectó más de una
     *
     * @throws ReglaIncumplida si no hay nada cobrado, o si la función no proyectó ninguna obra
     */
    public static function calcular(
        string $funcionId,
        DateTimeImmutable $finalizadaEn,
        int $cobradoCentavos,
        string $organizadorId,
        array $duenosDeLasObras,
        ParametroDeReparto $parametro,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): self {
        if ($cobradoCentavos <= 0) {
            throw new ReglaIncumplida('Una función sin nada cobrado no tiene qué repartir.');
        }
        if ($duenosDeLasObras === []) {
            throw new ReglaIncumplida('Una función sin obras proyectadas no se puede repartir.');
        }

        $sala = intdiv($cobradoCentavos * $parametro->sala, ParametroDeReparto::TOTAL);
        $deLosDuenos = intdiv($cobradoCentavos * $parametro->dueno, ParametroDeReparto::TOTAL);
        // La parte del dueño se divide en partes iguales entre las obras que se proyectaron, y se
        // suma por dueño: dos obras de la misma dueña son una sola parte, que es lo que se le
        // liquida (EF-08.3).
        $porObra = intdiv($deLosDuenos, count($duenosDeLasObras));
        $porDueno = [];
        foreach ($duenosDeLasObras as $duenoId) {
            $porDueno[$duenoId] = ($porDueno[$duenoId] ?? 0) + $porObra;
        }

        $partes = [new ParteDelReparto(RolDelReparto::Sala, $organizadorId, $sala)];
        foreach ($porDueno as $duenoId => $centavos) {
            $partes[] = new ParteDelReparto(RolDelReparto::Dueno, (string) $duenoId, $centavos);
        }
        // Lo que queda: su porcentaje más lo que se perdió en cada división entera. Por eso no se
        // calcula con el porcentaje, sino restando, y las partes cierran al centavo.
        $partes[] = new ParteDelReparto(RolDelReparto::Plataforma, null, $cobradoCentavos - $sala - $porObra * count($duenosDeLasObras));

        return new self(Uuid::nuevo(), $funcionId, $cobradoCentavos, $parametro->id, $finalizadaEn, $ahora, $partes);
    }

    /**
     * Una función que no repartió nada: a la gorra, o paga y sin un peso cobrado. Sin partes y sin
     * parámetro, porque no hubo con qué ni a quién.
     */
    public static function sinPlata(
        string $funcionId,
        DateTimeImmutable $finalizadaEn,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): self {
        return new self(Uuid::nuevo(), $funcionId, 0, null, $finalizadaEn, $ahora, []);
    }

    /** «10000.00», con dos decimales como la columna. */
    public function cobrado(): string
    {
        return Monto::comoDecimal($this->cobradoCentavos);
    }

    /** Si no hubo nada que repartir: las funciones a la gorra y las que no cobraron un peso. */
    public function sinPartes(): bool
    {
        return $this->partes === [];
    }
}
