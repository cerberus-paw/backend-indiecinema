<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DateTimeImmutable;

/**
 * Calificación de conducta de un espectador en una reserva con asistencia (EF-13.1): positiva o
 * negativa (con motivo obligatorio), cargada por el organizador dentro de los 7 días de la función.
 */
final readonly class Conducta
{
    public const DIAS_PARA_CALIFICAR = 7;
    public const MOTIVO_MAXIMO = 500;
    public const PUNTAJE_INICIAL = 100;
    public const DESCUENTO_NEGATIVA = 10;
    public const DESCUENTO_AUSENCIA = 5;
    public const MESES_VIGENCIA_NEGATIVA = 6;
    public const UMBRAL = 60;
    public const TOPE_BAJO_UMBRAL = 2;

    public function __construct(
        public string $reservaId,
        public string $usuarioId,
        public string $organizadorId,
        public bool $positiva,
        public ?string $motivo,
        public DateTimeImmutable $creadaEn,
    ) {
    }

    /**
     * Por qué no se puede calificar la conducta en esta reserva; null si cumple las reglas.
     */
    public static function motivoParaNoCalificar(
        Reserva $reserva,
        FuncionDeProgramacion $funcion,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): ?string {
        if ($reserva->estado !== EstadoReserva::Asistio) {
            return 'Sólo se puede calificar la conducta de quien tiene la asistencia registrada.';
        }
        if ($funcion->fechaHora !== null && $funcion->fechaHora->modify('+' . self::DIAS_PARA_CALIFICAR . ' days') < $ahora) {
            return 'Pasaron más de ' . self::DIAS_PARA_CALIFICAR . ' días desde la función: ya no se puede calificar la conducta.';
        }

        return null;
    }

    /**
     * @throws ReglaIncumplida si no tiene asistencia, pasó el plazo de 7 días o falta el motivo en
     *                         una calificación negativa
     */
    public static function nueva(
        Reserva $reserva,
        FuncionDeProgramacion $funcion,
        string $organizadorId,
        bool $positiva,
        ?string $motivo,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): self {
        if ($reserva->funcionId !== $funcion->id) {
            throw new ReglaIncumplida('No hay ninguna reserva con ese identificador para esta función.');
        }
        $impedimento = self::motivoParaNoCalificar($reserva, $funcion, $ahora);
        if ($impedimento !== null) {
            throw new ReglaIncumplida($impedimento);
        }

        $motivoLimpio = null;
        if (!$positiva) {
            $limpio = is_string($motivo) && mb_check_encoding($motivo, 'UTF-8')
                ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $motivo) ?? '')
                : '';
            if ($limpio === '') {
                throw new ReglaIncumplida('Escribí el motivo de la calificación negativa.');
            }
            if (mb_strlen($limpio) > self::MOTIVO_MAXIMO) {
                throw new ReglaIncumplida('El motivo no puede superar los ' . self::MOTIVO_MAXIMO . ' caracteres.');
            }
            $motivoLimpio = $limpio;
        }

        return new self($reserva->id, $reserva->usuarioId, $organizadorId, $positiva, $motivoLimpio, $ahora);
    }

    public static function mensajeDeTopeBajoUmbral(int $tope = self::TOPE_BAJO_UMBRAL): string
    {
        return "Por tu historial de conducta podés tener hasta {$tope} " . ($tope === 1 ? 'reserva' : 'reservas') . ' a la vez.';
    }
}
