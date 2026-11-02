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
 * (E4-03.4).
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
     * La función ya quedó cancelada en programación cuando se llama: lo que falta es lo de
     * funciones. Si no responde, la cancelación sigue en pie (una función cancelada no admite
     * reservas nuevas ni registra asistencia), pero sus reservas quedan confirmadas y sin aviso: se
     * deja en el log como error, con el id, para cancelarlas a mano, y el organizador lo ve.
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

    private function cliente(): ClienteInterno
    {
        return $this->cliente ??= ClienteInterno::desdeConfiguracion($this->configuracion, $this->log);
    }
}
