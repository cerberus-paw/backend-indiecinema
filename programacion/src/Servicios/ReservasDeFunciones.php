<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Las reservas de una función, que son del subsistema funciones: al cancelarse una función, se le
 * pide por su API interna que cancele sus reservas y les avise por correo a los espectadores
 * (E4-03.4); al terminar, que deje ausentes a los que no fueron (EF-04.1).
 */
final class ReservasDeFunciones
{
    private ?ClienteInterno $cliente = null;

    public function __construct(
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * La función ya quedó cancelada en programación cuando se llama, así que no entra ninguna
     * reserva nueva: lo que falta es lo de funciones. Si no responde, queda en el log y devuelve
     * false, y quien llama deshace la cancelación (F-01.7): si no, las reservas quedarían
     * confirmadas en una función cancelada y sin aviso. Cancelar las reservas se puede repetir: sólo
     * toca las confirmadas.
     *
     * @param ?string $fecha ya escrita, para el correo; null si no tenía
     *
     * @return bool si funciones canceló las reservas
     */
    public function cancelarDeLaFuncion(string $funcionId, string $organizadorId, string $titulo, ?string $fecha): bool
    {
        // Sin la dirección de funciones en config.ini no hay a quién avisar: es lo que pasa en las
        // pruebas de programación que no tienen nada que ver con las reservas.
        if ($this->configuracion->obtener('subsistemas.funciones') === null) {
            return true;
        }
        try {
            $this->cliente()->post('funciones', "/interno/funciones/{$funcionId}/cancelar-reservas", [
                'organizador_id' => $organizadorId,
                'titulo' => $titulo,
                'fecha' => $fecha,
            ]);

            return true;
        } catch (ExcepcionHttp | RuntimeException $error) {
            $this->log->error('Se canceló la función {funcion}, pero no sus reservas: {motivo}', [
                'funcion' => $funcionId,
                'motivo' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Le avisa a funciones que la función terminó, para que las reservas confirmadas sin asistencia
     * queden ausentes. Se llama antes de guardarla como finalizada: si funciones no responde,
     * queda en el log, la función sigue programada y la próxima pasada lo vuelve a intentar.
     * Repetirlo no cambia nada, porque sólo toca las confirmadas.
     *
     * @return bool si funciones lo recibió
     */
    public function avisarFinalizada(string $funcionId, string $organizadorId): bool
    {
        // Como en cancelarDeLaFuncion(): sin funciones en config.ini no hay a quién avisar.
        if ($this->configuracion->obtener('subsistemas.funciones') === null) {
            return true;
        }
        try {
            $this->cliente()->post('funciones', "/interno/funciones/{$funcionId}/finalizada", ['organizador_id' => $organizadorId]);

            return true;
        } catch (ExcepcionHttp | RuntimeException $error) {
            $this->log->error('La función {funcion} terminó, pero funciones no marcó las ausencias: {motivo}', [
                'funcion' => $funcionId,
                'motivo' => $error->getMessage(),
            ]);

            return false;
        }
    }

    private function cliente(): ClienteInterno
    {
        return $this->cliente ??= ClienteInterno::desdeConfiguracion($this->configuracion, $this->log);
    }
}
