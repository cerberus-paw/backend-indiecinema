<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\Uuid;

/**
 * La calificación de una función (EF-04.2): un solo puntaje de 1 a 5 por espectador y función, con
 * un comentario opcional. Los promedios por sala, por obra y por organizador salen de acá.
 *
 * Califica sólo quien tiene la asistencia registrada, en una función finalizada y hasta 30 días
 * después: más tarde, la opinión ya no ayuda a nadie a elegir.
 */
final readonly class Calificacion
{
    public const PUNTAJE_MINIMO = 1;
    public const PUNTAJE_MAXIMO = 5;
    public const COMENTARIO_MAXIMO = 500;
    public const DIAS_PARA_CALIFICAR = 30;

    /**
     * @param list<string> $obrasIds las que se proyectaron, copiadas de programación
     */
    public function __construct(
        public string $id,
        public string $funcionId,
        public string $usuarioId,
        public string $reservaId,
        public int $puntaje,
        public ?string $comentario,
        public string $salaId,
        public string $organizadorId,
        public array $obrasIds,
        public DateTimeImmutable $creadaEn,
    ) {
    }

    /**
     * Por qué el espectador no puede calificar con esta reserva, para decírselo; null si puede.
     * Que no haya calificado ya la función lo controla la base (una por función y espectador).
     *
     * @param ?FuncionDeProgramacion $funcion null si programación ya no la muestra
     */
    public static function motivoParaNoCalificar(Reserva $reserva, ?FuncionDeProgramacion $funcion, DateTimeImmutable $ahora = new DateTimeImmutable()): ?string
    {
        if ($funcion === null) {
            return 'La función ya no está en el sitio: no se puede calificar.';
        }
        if ($reserva->estado !== EstadoReserva::Asistio) {
            return 'Sólo puede calificar la función quien tiene la asistencia registrada.';
        }
        if ($funcion->estado !== 'finalizada' || $funcion->fechaHora === null) {
            return 'La función todavía no terminó: se puede calificar cuando finalice.';
        }
        if ($funcion->fechaHora->modify('+' . self::DIAS_PARA_CALIFICAR . ' days') < $ahora) {
            return 'Pasaron más de ' . self::DIAS_PARA_CALIFICAR . ' días desde la función: ya no se puede calificar.';
        }

        return null;
    }

    /**
     * @param ?string $comentario sin espacios de más; vacío es lo mismo que null
     *
     * @throws ReglaIncumplida si no puede calificar (motivoParaNoCalificar()), o el puntaje o el
     *                         comentario no son válidos
     */
    public static function nueva(
        Reserva $reserva,
        FuncionDeProgramacion $funcion,
        int $puntaje,
        ?string $comentario,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): self {
        $motivo = self::motivoParaNoCalificar($reserva, $funcion, $ahora);
        if ($motivo !== null) {
            throw new ReglaIncumplida($motivo);
        }
        if ($puntaje < self::PUNTAJE_MINIMO || $puntaje > self::PUNTAJE_MAXIMO) {
            throw new ReglaIncumplida('Elegí un puntaje del ' . self::PUNTAJE_MINIMO . ' al ' . self::PUNTAJE_MAXIMO . '.');
        }
        $comentario = $comentario === null ? null : trim($comentario);
        if ($comentario !== null && mb_strlen($comentario) > self::COMENTARIO_MAXIMO) {
            throw new ReglaIncumplida('El comentario puede tener hasta ' . self::COMENTARIO_MAXIMO . ' caracteres.');
        }

        return new self(
            Uuid::nuevo(),
            $funcion->id,
            $reserva->usuarioId,
            $reserva->id,
            $puntaje,
            $comentario === '' ? null : $comentario,
            $funcion->salaId,
            $funcion->organizadorId,
            $funcion->obrasIds,
            // La base guarda las fechas en UTC, como las reservas.
            $ahora->setTimezone(new DateTimeZone('UTC')),
        );
    }
}
