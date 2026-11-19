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
 * Las partes suman exactamente lo cobrado menos la comisión de Mercado Pago: cada una se saca con
 * una división entera y lo que se pierde en los redondeos queda en la de la plataforma. Reparte lo
 * cobrado neto de reembolsos, que es lo que funciones cuenta como cobrado: una entrada devuelta no
 * se reparte. La comisión se descuenta antes de repartir (EF-22.1): es plata que nunca llega a la
 * cuenta del sitio, así que no es de nadie; si se repartiera lo cobrado entero, la plataforma
 * pagaría sola la comisión de las entradas de todos.
 *
 * Una función que no repartió nada —a la gorra, o paga pero sin un peso cobrado— también se
 * registra, sin partes: así la tarea no la vuelve a mirar en cada pasada.
 *
 * Guarda también de qué función fue, como era en ese momento (la fecha, la sala y los títulos):
 * es lo que muestra el detalle de la liquidación (EF-08.3), y así sigue diciendo de qué función
 * fue aunque después la sala se dé de baja o la obra cambie de título.
 */
final readonly class Reparto
{
    /** Lo que entra en la columna reparto.titulos. */
    private const LARGO_DE_LOS_TITULOS = 500;

    /**
     * @param list<ParteDelReparto> $partes
     *
     * @param int $comisionCentavos lo que se quedó Mercado Pago, que no se reparte
     *
     * @throws ReglaIncumplida si las partes no suman lo cobrado menos la comisión
     */
    private function __construct(
        public string $id,
        public string $funcionId,
        public int $cobradoCentavos,
        public ?string $parametroId,
        public DateTimeImmutable $finalizadaEn,
        public DateTimeImmutable $funcionFechaHora,
        public string $salaNombre,
        public string $titulos,
        public DateTimeImmutable $calculadoEn,
        public array $partes,
        public int $comisionCentavos = 0,
    ) {
        $repartido = array_sum(array_map(static fn (ParteDelReparto $parte): int => $parte->centavos, $partes));
        if ($comisionCentavos < 0 || $comisionCentavos > $cobradoCentavos) {
            throw new ReglaIncumplida('La comisión no puede ser negativa ni mayor que lo cobrado.');
        }
        if ($partes !== [] && $repartido !== $cobradoCentavos - $comisionCentavos) {
            throw new ReglaIncumplida('Las partes del reparto tienen que sumar lo cobrado de la función menos la comisión.');
        }
        if ($partes === [] && $cobradoCentavos !== 0) {
            throw new ReglaIncumplida('Las partes del reparto tienen que sumar lo cobrado de la función.');
        }
    }

    /**
     * @param int $cobradoCentavos     lo cobrado neto de reembolsos
     * @param int $comisionCentesimos la comisión de Mercado Pago en centésimos de punto: 761 es
     *                                el 7,61 %. Se redondea al centavo y se descuenta antes de
     *                                repartir.
     *
     * @throws ReglaIncumplida si no hay nada cobrado, o si la función no proyectó ninguna obra
     */
    public static function calcular(
        FuncionFinalizada $funcion,
        int $cobradoCentavos,
        ParametroDeReparto $parametro,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
        int $comisionCentesimos = 0,
    ): self {
        if ($cobradoCentavos <= 0) {
            throw new ReglaIncumplida('Una función sin nada cobrado no tiene qué repartir.');
        }
        if ($comisionCentesimos < 0 || $comisionCentesimos >= 10_000) {
            throw new ReglaIncumplida('La comisión tiene que ser de 0 a menos del 100 %.');
        }
        $comision = intdiv(2 * $cobradoCentavos * $comisionCentesimos + 10_000, 20_000);
        $repartible = $cobradoCentavos - $comision;
        $duenosDeLasObras = $funcion->duenosDeLasObras();
        if ($duenosDeLasObras === []) {
            throw new ReglaIncumplida('Una función sin obras proyectadas no se puede repartir.');
        }

        $sala = intdiv($repartible * $parametro->sala, ParametroDeReparto::TOTAL);
        $deLosDuenos = intdiv($repartible * $parametro->dueno, ParametroDeReparto::TOTAL);
        // La parte del dueño se divide en partes iguales entre las obras que se proyectaron, y se
        // suma por dueño: dos obras de la misma dueña son una sola parte, que es lo que se le
        // liquida (EF-08.3).
        $porObra = intdiv($deLosDuenos, count($duenosDeLasObras));
        $porDueno = [];
        foreach ($duenosDeLasObras as $duenoId) {
            $porDueno[$duenoId] = ($porDueno[$duenoId] ?? 0) + $porObra;
        }

        $partes = [new ParteDelReparto(RolDelReparto::Sala, $funcion->organizadorId, $sala)];
        foreach ($porDueno as $duenoId => $centavos) {
            $partes[] = new ParteDelReparto(RolDelReparto::Dueno, (string) $duenoId, $centavos);
        }
        // Lo que queda: su porcentaje más lo que se perdió en cada división entera. Por eso no se
        // calcula con el porcentaje, sino restando, y las partes cierran al centavo.
        $partes[] = new ParteDelReparto(RolDelReparto::Plataforma, null, $repartible - $sala - $porObra * count($duenosDeLasObras));

        return self::de($funcion, $cobradoCentavos, $parametro->id, $ahora, $partes, $comision);
    }

    /**
     * Una función que no repartió nada: a la gorra, o paga y sin un peso cobrado. Sin partes y sin
     * parámetro, porque no hubo con qué ni a quién.
     */
    public static function sinPlata(FuncionFinalizada $funcion, DateTimeImmutable $ahora = new DateTimeImmutable()): self
    {
        return self::de($funcion, 0, null, $ahora, []);
    }

    /**
     * @param list<ParteDelReparto> $partes
     */
    private static function de(
        FuncionFinalizada $funcion,
        int $cobradoCentavos,
        ?string $parametroId,
        DateTimeImmutable $ahora,
        array $partes,
        int $comisionCentavos = 0,
    ): self {
        $titulos = implode(', ', array_map(static fn (ObraVotada $obra): string => $obra->titulo, $funcion->elegidas()));

        return new self(
            Uuid::nuevo(),
            $funcion->id,
            $cobradoCentavos,
            $parametroId,
            $funcion->finalizadaEn,
            $funcion->fechaHora,
            $funcion->salaNombre,
            mb_substr($titulos, 0, self::LARGO_DE_LOS_TITULOS),
            $ahora,
            $partes,
            $comisionCentavos,
        );
    }

    /** «10000.00», con dos decimales como la columna. */
    public function cobrado(): string
    {
        return Monto::comoDecimal($this->cobradoCentavos);
    }

    /** «761.00», con dos decimales como la columna. */
    public function comision(): string
    {
        return Monto::comoDecimal($this->comisionCentavos);
    }

    /** Si no hubo nada que repartir: las funciones a la gorra y las que no cobraron un peso. */
    public function sinPartes(): bool
    {
        return $this->partes === [];
    }

    /** El mes en el que se liquida: el de la finalización, que es por donde va la tarea. */
    public function mes(): Mes
    {
        return Mes::de($this->finalizadaEn);
    }
}
