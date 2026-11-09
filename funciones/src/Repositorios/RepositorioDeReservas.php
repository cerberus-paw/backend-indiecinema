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
     * Los lugares que ocupan las reservas de la función: las confirmadas y las de quienes ya
     * entraron (EstadoReserva::ocupaLugares()).
     */
    public function lugaresOcupados(string $funcionId): int
    {
        $ocupan = array_values(array_filter(EstadoReserva::cases(), static fn (EstadoReserva $estado): bool => $estado->ocupaLugares()));

        return (int) $this->base->valor(
            'SELECT COALESCE(SUM(cantidad), 0) FROM reserva WHERE funcion_id = ? AND estado IN (' . implode(', ', array_fill(0, count($ocupan), '?')) . ')',
            [$funcionId, ...$ocupan],
        );
    }

    /**
     * Lo mismo que lugaresOcupados(), para varias funciones en una sola consulta: la cartelera
     * muestra una página de tarjetas y no hace un pedido por cada una.
     *
     * @param list<string> $funcionesIds
     *
     * @return array<string, int> funcion_id => lugares ocupados, también las que no tienen reservas (0)
     */
    public function lugaresOcupadosDe(array $funcionesIds): array
    {
        if ($funcionesIds === []) {
            return [];
        }
        $ocupan = array_values(array_filter(EstadoReserva::cases(), static fn (EstadoReserva $estado): bool => $estado->ocupaLugares()));
        $ocupados = array_fill_keys($funcionesIds, 0);
        foreach ($this->base->filas(
            'SELECT funcion_id, SUM(cantidad) AS ocupados FROM reserva WHERE funcion_id IN ('
            . implode(', ', array_fill(0, count($funcionesIds), '?')) . ') AND estado IN ('
            . implode(', ', array_fill(0, count($ocupan), '?')) . ') GROUP BY funcion_id',
            [...$funcionesIds, ...$ocupan],
        ) as $fila) {
            $ocupados[$fila['funcion_id']] = (int) $fila['ocupados'];
        }

        return $ocupados;
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
                $this->base->ejecutar(
                    'INSERT INTO cambio_reserva (reserva_id, estado_anterior, estado_nuevo, autor_id, autor_rol, motivo, hecho_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$reserva->id, $anterior, $reserva->estado, $autor->id, $autor->rol->value, $autor->motivo, $ahora->setTimezone(new DateTimeZone('UTC'))],
                );
            }

            return $cambio;
        });
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
