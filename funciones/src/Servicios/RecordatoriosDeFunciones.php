<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;

/**
 * El recordatorio por correo el día antes de la función (EF-03.3): cada reserva confirmada de una
 * función que empieza en las próximas 24 horas recibe uno, con la sala, la dirección, la hora y su
 * código. La corre el cron del servidor cada hora con bin/recordar-funciones.php.
 *
 * Las funciones y su sala las sabe programación, que se las da por la API interna; las reservas
 * son de acá. Cada reserva se toma antes de mandar (recordada_en), así repetir la tarea no repite
 * el correo; si el correo no sale, se suelta y la próxima pasada lo intenta otra vez.
 */
final class RecordatoriosDeFunciones
{
    /** Cuánto antes de la función sale el recordatorio. */
    public const HORAS_ANTES = 24;

    public function __construct(
        private readonly FuncionesDeProgramacion $funciones,
        private readonly RepositorioDeReservas $reservas,
        private readonly AvisosDeReservas $avisos,
    ) {
    }

    /**
     * @return array{enviados: int, fallidos: int}
     *
     * @throws \IndieCinema\Nucleo\Http\ExcepcionHttp|\RuntimeException si programación no
     *         responde: no se manda nada y la próxima pasada lo vuelve a intentar
     */
    public function recordar(DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        $hechos = ['enviados' => 0, 'fallidos' => 0];
        foreach ($this->funciones->proximas($ahora, $ahora->modify('+' . self::HORAS_ANTES . ' hours')) as $funcion) {
            foreach ($this->reservas->paraRecordar($funcion->id) as $reserva) {
                // Otra pasada que corre al mismo tiempo, o una cancelación de recién: no se manda.
                if (!$this->reservas->tomarRecordatorio($reserva->id, $ahora)) {
                    continue;
                }
                if ($this->avisos->recordatorio($reserva, $funcion)) {
                    ++$hechos['enviados'];
                } else {
                    $this->reservas->soltarRecordatorio($reserva->id);
                    ++$hechos['fallidos'];
                }
            }
        }

        return $hechos;
    }
}
