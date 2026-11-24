<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
use IndieCinema\Moderacion\Repositorios\RepositorioDeAvisos;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSolicitudes;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * La tarea diaria que cuida el plazo de las solicitudes devueltas (EF-22.10). La que pasó su
 * fecha límite (el último día para completarla, en Argentina) queda vencida, con un motivo fijo y
 * sin quién, y al solicitante le llega el correo. A la que le quedan recordatorio_devolucion_dias
 * días o menos le llega un recordatorio.
 *
 * Correrla de más no repite nada: una vencida ya no está devuelta, y cada correo se anota en la
 * tabla aviso antes de mandarlo, como en la tarea de vencimientos. El recordatorio lleva la fecha
 * límite en la clave, así una segunda devolución tiene el suyo. Si el correo falla, el aviso se
 * borra y sale en la próxima vuelta.
 */
final class VencimientoDeDevueltas
{
    public const MOTIVO = 'Venció el plazo para completar la documentación.';

    /** Hasta cuántos días después de vencerla se reintenta el correo que no salió. */
    private const DIAS_PARA_REINTENTAR = 7;

    public function __construct(
        private readonly RepositorioDeSolicitudes $solicitudes,
        private readonly RepositorioDeAvisos $avisos,
        private readonly UsuariosDeCuentas $usuarios,
        private readonly ClienteSmtp $correo,
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return int cuántas venció
     */
    public function correr(): int
    {
        $hoy = ServicioDeSolicitudes::hoy();
        $ahora = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $vencidas = 0;
        foreach ($this->solicitudes->devueltas() as $solicitud) {
            if ($solicitud->devolucion !== null && $hoy > $solicitud->devolucion->vence && $this->solicitudes->vencer($solicitud->id, self::MOTIVO, $ahora)) {
                $this->log->notice('La solicitud de sala {solicitud} venció sin que la completaran.', ['solicitud' => $solicitud->id]);
                ++$vencidas;
            }
        }

        // También las de las vueltas anteriores cuyo correo no salió.
        foreach ($this->solicitudes->vencidasDesde($ahora->modify('-' . self::DIAS_PARA_REINTENTAR . ' days')) as $solicitud) {
            $this->avisar($solicitud, "devuelta-vencida:{$solicitud->id}", ServicioDeSolicitudes::textoParaElSolicitante($solicitud, EstadoSolicitud::Vencida, self::MOTIVO));
        }

        $dias = (int) $this->configuracion->requerir('solicitudes.recordatorio_devolucion_dias');
        foreach ($this->solicitudes->devueltas() as $solicitud) {
            $vence = $solicitud->devolucion?->vence;
            if ($vence === null || $hoy < $vence->modify("-{$dias} days")) {
                continue;
            }
            $this->avisar($solicitud, "devuelta-recordatorio:{$solicitud->id}:" . $vence->format('Y-m-d'), ServicioDeSolicitudes::textoDelRecordatorio($solicitud));
        }

        return $vencidas;
    }

    /**
     * Una sola vez por clave: se anota antes de mandarlo y, si no sale, se borra.
     *
     * @param array{string, string} $correo el asunto y el texto
     */
    private function avisar(SolicitudDeSala $solicitud, string $clave, array $correo): void
    {
        if (!$this->avisos->reservar($solicitud->salaId, $clave, new DateTimeImmutable('now', new DateTimeZone('UTC')))) {
            return;
        }
        try {
            $usuario = $this->usuarios->buscar($solicitud->usuarioId) ?? throw new RuntimeException('cuentas ya no tiene al usuario.');
            $this->correo->enviar(new Correo($usuario->correo, ...$correo));
        } catch (Throwable $error) {
            $this->avisos->liberar($solicitud->salaId, $clave);
            $this->log->warning('No se le avisó al solicitante de la solicitud {solicitud} ({clave}): {mensaje}', [
                'solicitud' => $solicitud->id,
                'clave' => $clave,
                'mensaje' => $error->getMessage(),
            ]);
        }
    }
}
