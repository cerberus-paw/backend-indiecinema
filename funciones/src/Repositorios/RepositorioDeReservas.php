<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\AutorDelCambio;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use LogicException;
use PDOException;

final class RepositorioDeReservas
{
    private const CLAVE_DUPLICADA = 1062;

    /**
     * Cuánto espera una reserva a que termine otra de la misma función. Cada una tarda
     * milisegundos: si pasan estos segundos, algo anda mal y es mejor un 503 que colgar la página.
     */
    private const ESPERA_DEL_BLOQUEO = 5;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Corre $trabajo en una transacción, con las reservas de la función bloqueadas: dos reservas
     * de la misma función van una después de la otra, así ninguna cuenta los lugares mientras la
     * otra está por ocupar el último.
     *
     * Es un bloqueo con nombre (GET_LOCK) y no un SELECT … FOR UPDATE: el cupo vive en la función,
     * que es de programación, y en este esquema no hay una fila por función que bloquear. Funciones
     * de distinto id no se esperan entre sí.
     *
     * @template T
     *
     * @param callable(BaseDeDatos): T $trabajo
     *
     * @return T
     *
     * @throws ExcepcionHttp 503 si otra reserva no suelta la función a tiempo
     */
    public function conLaFuncionBloqueada(string $funcionId, callable $trabajo): mixed
    {
        // Hasta 64 caracteres: con el prefijo y el UUID son 54.
        $bloqueo = "funciones.reserva.{$funcionId}";
        if ($this->base->valor('SELECT GET_LOCK(?, ?)', [$bloqueo, self::ESPERA_DEL_BLOQUEO]) !== 1) {
            throw ExcepcionHttp::servicioNoDisponible();
        }
        try {
            // La transacción se confirma antes de soltar el bloqueo: la reserva que espera ya ve
            // los lugares que ocupó esta.
            return $this->base->enTransaccion($trabajo);
        } finally {
            $this->base->valor('SELECT RELEASE_LOCK(?)', [$bloqueo]);
        }
    }

    /**
     * Los lugares que ocupan las reservas de la función: las confirmadas, las de quienes ya
     * entraron y las que esperan el pago, mientras estén a tiempo (EstadoReserva::ocupaLugares()).
     *
     * Una pendiente vencida deja de ocupar sus lugares en el momento en que vence, aunque la tarea
     * que la cancela (bin/liberar-vencidas.php) todavía no haya pasado (X-01.5): así el cupo nunca
     * depende de cada cuánto corre el cron.
     */
    public function lugaresOcupados(string $funcionId, DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        [$condicion, $parametros] = self::queOcupan($ahora);

        return (int) $this->base->valor(
            "SELECT COALESCE(SUM(cantidad), 0) FROM reserva WHERE funcion_id = ? AND {$condicion}",
            [$funcionId, ...$parametros],
        );
    }

    /**
     * Los lugares que ya tiene esa persona en la función, con la misma regla que lugaresOcupados():
     * las confirmadas, las de quien ya entró y las pendientes de pago que siguen a tiempo. Una
     * pendiente vencida ya no le cuenta para el tope, aunque el cron todavía no la haya cancelado.
     */
    public function lugaresOcupadosPorUsuario(string $funcionId, string $usuarioId, DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        [$condicion, $parametros] = self::queOcupan($ahora);

        return (int) $this->base->valor(
            "SELECT COALESCE(SUM(cantidad), 0) FROM reserva WHERE funcion_id = ? AND usuario_id = ? AND {$condicion}",
            [$funcionId, $usuarioId, ...$parametros],
        );
    }

    /**
     * La condición de las reservas que ocupan lugares ahora, con sus parámetros.
     *
     * @return array{string, list<mixed>}
     */
    private static function queOcupan(DateTimeImmutable $ahora): array
    {
        $sinVencer = array_values(array_filter(
            EstadoReserva::cases(),
            static fn (EstadoReserva $estado): bool => $estado->ocupaLugares() && $estado !== EstadoReserva::PendientePago,
        ));

        return [
            '(estado IN (' . implode(', ', array_fill(0, count($sinVencer), '?')) . ') OR (estado = ? AND vence_en > ?))',
            [...$sinVencer, EstadoReserva::PendientePago, $ahora->setTimezone(new DateTimeZone('UTC'))],
        ];
    }

    /**
     * Lo mismo que lugaresOcupados(), para varias funciones en una sola consulta: la cartelera
     * muestra una página de tarjetas y no hace un pedido por cada una.
     *
     * @param list<string> $funcionesIds
     *
     * @return array<string, int> funcion_id => lugares ocupados, también las que no tienen reservas (0)
     */
    public function lugaresOcupadosDe(array $funcionesIds, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        if ($funcionesIds === []) {
            return [];
        }
        [$condicion, $parametros] = self::queOcupan($ahora);
        $ocupados = array_fill_keys($funcionesIds, 0);
        foreach ($this->base->filas(
            'SELECT funcion_id, SUM(cantidad) AS ocupados FROM reserva WHERE funcion_id IN ('
            . implode(', ', array_fill(0, count($funcionesIds), '?')) . ") AND {$condicion} GROUP BY funcion_id",
            [...$funcionesIds, ...$parametros],
        ) as $fila) {
            $ocupados[$fila['funcion_id']] = (int) $fila['ocupados'];
        }

        return $ocupados;
    }

    /**
     * Cuántos lugares entraron a cada función, para el historial de proyecciones de la ficha de
     * una sala (EF-04.3): los de las reservas con la asistencia registrada.
     *
     * @param list<string> $funcionesIds
     *
     * @return array<string, int> funcion_id => asistentes; las que no tienen, en 0
     */
    public function asistentesDe(array $funcionesIds): array
    {
        if ($funcionesIds === []) {
            return [];
        }
        $asistentes = array_fill_keys($funcionesIds, 0);
        foreach ($this->base->filas(
            'SELECT funcion_id, SUM(cantidad) AS asistentes FROM reserva WHERE funcion_id IN ('
            . implode(', ', array_fill(0, count($funcionesIds), '?')) . ') AND estado = ? GROUP BY funcion_id',
            [...$funcionesIds, EstadoReserva::Asistio],
        ) as $fila) {
            $asistentes[$fila['funcion_id']] = (int) $fila['asistentes'];
        }

        return $asistentes;
    }

    /**
     * Las reservas en pie de esas funciones, para lo cobrado que pide recaudación (EF-02.5): las que
     * ocupan lugares salvo las que todavía esperan el pago, que no se cobraron.
     *
     * Y las canceladas que se habían pagado (EF-06.4): soltaron sus lugares, pero su plata entró, y
     * recaudación tiene que ver si volvió. Si el reembolso salió, el neto las descuenta; si quedó
     * pendiente, siguen contando, porque la plata todavía está en la cuenta.
     *
     * @param list<string> $funcionesIds
     *
     * @return array<string, list<Reserva>> funcion_id => sus reservas, de la primera a la última;
     *                                      las que no tienen, con la lista vacía
     */
    public function cobradasDe(array $funcionesIds): array
    {
        if ($funcionesIds === []) {
            return [];
        }
        $estados = array_values(array_filter(
            EstadoReserva::cases(),
            static fn (EstadoReserva $estado): bool => $estado->ocupaLugares() && $estado !== EstadoReserva::PendientePago,
        ));
        $porFuncion = array_fill_keys($funcionesIds, []);
        foreach ($this->base->filas(
            'SELECT * FROM reserva WHERE funcion_id IN (' . implode(', ', array_fill(0, count($funcionesIds), '?')) . ')
             AND (estado IN (' . implode(', ', array_fill(0, count($estados), '?')) . ') OR pago_id IS NOT NULL)
             ORDER BY creada_en, codigo',
            [...$funcionesIds, ...$estados],
        ) as $fila) {
            $porFuncion[$fila['funcion_id']][] = self::reserva($fila);
        }

        return $porFuncion;
    }

    /**
     * Por qué se canceló cada una de esas reservas (EF-06.3): el motivo que dejó el cambio que la
     * canceló. Lo escribe quien cancela y ya viene redactado para el espectador —«Venció el plazo
     * para pagar.», «Se canceló la función.», «Anulada por el administrador.»—, así que sale tal
     * cual. Las que canceló el espectador no tienen ninguno: las canceló él y ya sabe por qué.
     *
     * @param list<string> $reservasIds
     *
     * @return array<string, string> reserva_id => motivo; sólo las que tienen uno
     */
    public function motivosDeCancelacion(array $reservasIds): array
    {
        if ($reservasIds === []) {
            return [];
        }
        $motivos = [];
        // De la más vieja a la más nueva, así gana la última: una pendiente puede cancelarse al
        // vencer, volver a confirmarse con un pago que llegó tarde y cancelarse otra vez.
        foreach ($this->base->filas(
            'SELECT reserva_id, motivo FROM cambio_reserva WHERE reserva_id IN ('
            . implode(', ', array_fill(0, count($reservasIds), '?')) . ')
             AND estado_nuevo = ? AND motivo IS NOT NULL ORDER BY hecho_en, id',
            [...$reservasIds, EstadoReserva::Cancelada],
        ) as $fila) {
            $motivos[(string) $fila['reserva_id']] = (string) $fila['motivo'];
        }

        return $motivos;
    }

    public function buscar(string $id): ?Reserva
    {
        $fila = $this->base->fila('SELECT * FROM reserva WHERE id = ?', [$id]);

        return $fila === null ? null : self::reserva($fila);
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function reserva(array $fila): Reserva
    {
        return new Reserva(
            $fila['id'],
            $fila['funcion_id'],
            $fila['usuario_id'],
            $fila['codigo'],
            $fila['cantidad'],
            $fila['total'],
            EstadoReserva::from($fila['estado']),
            new DateTimeImmutable($fila['creada_en'], new DateTimeZone('UTC')),
            $fila['vence_en'] === null ? null : new DateTimeImmutable($fila['vence_en'], new DateTimeZone('UTC')),
            $fila['preferencia_id'],
            $fila['enlace_de_pago'],
            $fila['pago_id'],
            $fila['reembolso_id'],
            $fila['reembolsada_en'] === null ? null : new DateTimeImmutable($fila['reembolsada_en'], new DateTimeZone('UTC')),
        );
    }

    /**
     * Anota la preferencia de Checkout Pro de una reserva que todavía espera el pago. Si mientras
     * tanto se canceló, no anota nada: ya no hay qué cobrar.
     *
     * @return bool false si ya no estaba pendiente de pago
     */
    public function guardarPreferencia(Reserva $reserva): bool
    {
        return $this->base->ejecutar(
            'UPDATE reserva SET preferencia_id = ?, enlace_de_pago = ? WHERE id = ? AND estado = ?',
            [$reserva->preferenciaId, $reserva->enlaceDePago, $reserva->id, EstadoReserva::PendientePago],
        ) === 1;
    }

    /**
     * Guarda el estado nuevo de la reserva, si todavía está en $anterior, y deja el cambio en el
     * historial con su autor, en la misma transacción. Así, si el espectador cancela mientras el
     * organizador registra su asistencia, gana el primero y el otro se entera, en lugar de pisarse;
     * y el historial no anota un cambio que no pasó.
     *
     * @return bool false si otro la cambió antes, y entonces no guardó nada
     */
    public function guardarEstado(Reserva $reserva, EstadoReserva $anterior, AutorDelCambio $autor, DateTimeImmutable $ahora = new DateTimeImmutable()): bool
    {
        return $this->base->enTransaccion(function () use ($reserva, $anterior, $autor, $ahora): bool {
            $cambio = $this->base->ejecutar(
                'UPDATE reserva SET estado = ? WHERE id = ? AND estado = ?',
                [$reserva->estado, $reserva->id, $anterior],
            ) === 1;
            if ($cambio) {
                $this->anotarCambio($reserva, $anterior, $autor, $ahora);
            }

            return $cambio;
        });
    }

    /**
     * Confirma la reserva con el pago que la pagó (X-01.4), si todavía está pendiente: es
     * guardarEstado() con el pago. Si llegan dos avisos del mismo pago a la vez, el primero la
     * confirma y el segundo no encuentra nada pendiente; y como pago_id es único, un pago no
     * confirma dos reservas.
     *
     * @return bool false si ya no estaba pendiente o el pago ya confirmó otra, y entonces no
     *              guardó nada
     */
    public function guardarPago(Reserva $confirmada, AutorDelCambio $autor, DateTimeImmutable $ahora = new DateTimeImmutable()): bool
    {
        try {
            return $this->base->enTransaccion(function () use ($confirmada, $autor, $ahora): bool {
                $cambio = $this->base->ejecutar(
                    'UPDATE reserva SET estado = ?, pago_id = ? WHERE id = ? AND estado = ?',
                    [$confirmada->estado, $confirmada->pagoId, $confirmada->id, EstadoReserva::PendientePago],
                ) === 1;
                if ($cambio) {
                    $this->anotarCambio($confirmada, EstadoReserva::PendientePago, $autor, $ahora);
                }

                return $cambio;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }
            throw $error;
        }
    }

    /**
     * Anota el reembolso del pago de la reserva, si todavía no tenía ninguno (EF-06.4). Es una sola
     * sentencia, como tomarRecordatorio(): si dos cancelaciones compiten, una sola lo anota, y la
     * otra se entera por el false y no se lo vuelve a pedir a Mercado Pago.
     *
     * No deja línea en cambio_reserva: ese historial anota cambios de estado —su CHECK
     * cambio_reserva_cambia_el_estado no acepta dos iguales— y el reembolso no cambia el estado,
     * que ya quedó cancelado. Queda en la reserva y en el log.
     *
     * @return bool false si ya estaba reembolsada, y entonces no guardó nada
     */
    public function guardarReembolso(Reserva $reembolsada): bool
    {
        $reembolsoId = $reembolsada->reembolsoId
            ?? throw new LogicException("La reserva {$reembolsada->id} no tiene ningún reembolso que guardar.");

        return $this->base->ejecutar(
            'UPDATE reserva SET reembolso_id = ?, reembolsada_en = ? WHERE id = ? AND pago_id IS NOT NULL AND reembolso_id IS NULL',
            [$reembolsoId, $reembolsada->reembolsadaEn, $reembolsada->id],
        ) === 1;
    }

    private function anotarCambio(Reserva $reserva, EstadoReserva $anterior, AutorDelCambio $autor, DateTimeImmutable $ahora): void
    {
        $this->base->ejecutar(
            'INSERT INTO cambio_reserva (reserva_id, estado_anterior, estado_nuevo, autor_id, autor_rol, motivo, hecho_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$reserva->id, $anterior, $reserva->estado, $autor->id, $autor->rol->value, $autor->motivo, $ahora->setTimezone(new DateTimeZone('UTC'))],
        );
    }

    /**
     * Las pendientes de pago que ya vencieron, de la que venció primero a la última (X-01.5). Usa
     * el índice por estado y vencimiento.
     *
     * @return list<Reserva>
     */
    public function vencidas(DateTimeImmutable $ahora, int $cuantas): array
    {
        return array_map(self::reserva(...), $this->base->filas(
            'SELECT * FROM reserva WHERE estado = ? AND vence_en <= ? ORDER BY vence_en, id LIMIT ?',
            [EstadoReserva::PendientePago, $ahora->setTimezone(new DateTimeZone('UTC')), $cuantas],
        ));
    }

    /**
     * Las confirmadas de la función que todavía no recibieron el recordatorio (EF-03.3). Las
     * pendientes de pago no: si no pagan, vencen; y las canceladas ya no van.
     *
     * @return list<Reserva>
     */
    public function paraRecordar(string $funcionId): array
    {
        return array_map(self::reserva(...), $this->base->filas(
            'SELECT * FROM reserva WHERE funcion_id = ? AND estado = ? AND recordada_en IS NULL ORDER BY creada_en, codigo',
            [$funcionId, EstadoReserva::Confirmada],
        ));
    }

    /**
     * Toma el recordatorio de la reserva: lo marca como enviado, si todavía no lo estaba y sigue
     * confirmada. Es una sola sentencia para que dos pasadas al mismo tiempo no lo manden las dos.
     *
     * @return bool si lo tomó esta pasada
     */
    public function tomarRecordatorio(string $id, DateTimeImmutable $ahora = new DateTimeImmutable()): bool
    {
        return $this->base->ejecutar(
            'UPDATE reserva SET recordada_en = ? WHERE id = ? AND estado = ? AND recordada_en IS NULL',
            [$ahora->setTimezone(new DateTimeZone('UTC')), $id, EstadoReserva::Confirmada],
        ) === 1;
    }

    /**
     * Lo devuelve si el correo no salió, para que la próxima pasada lo intente otra vez.
     */
    public function soltarRecordatorio(string $id): void
    {
        $this->base->ejecutar('UPDATE reserva SET recordada_en = NULL WHERE id = ?', [$id]);
    }

    /**
     * Las reservas de una función, de la primera a la última: la lista del organizador y su CSV.
     *
     * @return list<Reserva>
     */
    public function deLaFuncion(string $funcionId): array
    {
        return array_map(self::reserva(...), $this->base->filas(
            'SELECT * FROM reserva WHERE funcion_id = ? ORDER BY creada_en, codigo',
            [$funcionId],
        ));
    }

    /**
     * La reserva de una función con ese código: lo que el organizador tipea en la puerta. El
     * código de otra función no sirve, aunque exista.
     */
    public function porCodigo(string $funcionId, string $codigo): ?Reserva
    {
        $fila = $this->base->fila('SELECT * FROM reserva WHERE funcion_id = ? AND codigo = ?', [$funcionId, $codigo]);

        return $fila === null ? null : self::reserva($fila);
    }

    /**
     * Todas las reservas, de la más nueva a la más vieja, para el panel del administrador.
     *
     * @param int $numero desde 1
     *
     * @return array{0: list<Reserva>, 1: int} las de la página y cuántas hay en total
     */
    public function pagina(?EstadoReserva $estado, int $numero, int $tamano): array
    {
        [$donde, $parametros] = $estado === null ? ['', []] : ['WHERE estado = ?', [$estado]];
        $total = (int) $this->base->valor("SELECT COUNT(*) FROM reserva {$donde}", $parametros);
        $filas = $this->base->filas(
            "SELECT * FROM reserva {$donde} ORDER BY creada_en DESC, codigo LIMIT ? OFFSET ?",
            [...$parametros, $tamano, ($numero - 1) * $tamano],
        );

        return [array_map(self::reserva(...), $filas), $total];
    }

    /**
     * Las reservas de un espectador, de la más nueva a la más vieja, para «Mis reservas»
     * (E4-04.1). Usa el índice por usuario y fecha de alta.
     *
     * @param int $numero desde 1
     *
     * @return array{0: list<Reserva>, 1: int} las de la página y cuántas tiene en total
     */
    public function delUsuario(string $usuarioId, int $numero, int $tamano): array
    {
        $total = (int) $this->base->valor('SELECT COUNT(*) FROM reserva WHERE usuario_id = ?', [$usuarioId]);
        $filas = $this->base->filas(
            'SELECT * FROM reserva WHERE usuario_id = ? ORDER BY creada_en DESC, codigo LIMIT ? OFFSET ?',
            [$usuarioId, $tamano, ($numero - 1) * $tamano],
        );

        return [array_map(self::reserva(...), $filas), $total];
    }

    /**
     * @return bool false si el código ya es de otra reserva, y entonces no guardó nada
     */
    public function agregar(Reserva $reserva): bool
    {
        try {
            $this->base->ejecutar(
                'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en, vence_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$reserva->id, $reserva->funcionId, $reserva->usuarioId, $reserva->codigo, $reserva->cantidad, $reserva->total, $reserva->estado, $reserva->creadaEn, $reserva->venceEn],
            );

            return true;
        } catch (PDOException $error) {
            // El id es un UUID nuevo: lo único que se puede repetir es el código.
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }
            throw $error;
        }
    }
}
