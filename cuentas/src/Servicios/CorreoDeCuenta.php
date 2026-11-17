<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Arma enlaces públicos de cuentas y envía correos SMTP sin registrar direcciones ni tokens en el
 * log.
 */
final class CorreoDeCuenta
{
    public const MARCADOR_ENLACE = '{{ENLACE}}';

    public function __construct(
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
        private readonly ?ClienteSmtp $correo = null,
    ) {
    }

    public function enlaceConToken(string $rutaSinPrefijo, string $token): string
    {
        $baseUrl = rtrim((string) $this->configuracion->obtener('sitio.url', ''), '/');
        $prefijo = trim((string) $this->configuracion->obtener('app.prefijo', '/cuenta'), '/');
        $ruta = ($prefijo === '' ? '' : "/{$prefijo}") . '/' . ltrim($rutaSinPrefijo, '/');

        return "{$baseUrl}{$ruta}?token=" . urlencode($token);
    }

    public function enviarEnlace(
        Usuario $usuario,
        string $rutaSinPrefijo,
        string $token,
        string $asunto,
        string $plantillaCuerpo,
        string $operacionLog,
    ): bool {
        if ($this->correo === null) {
            return false;
        }

        $cuerpo = str_replace(self::MARCADOR_ENLACE, $this->enlaceConToken($rutaSinPrefijo, $token), $plantillaCuerpo);
        try {
            $this->correo->enviar(new Correo($usuario->correo, $asunto, $cuerpo));

            return true;
        } catch (Throwable $error) {
            $this->log->warning(
                'No se pudo enviar el correo para {operacion} de la cuenta {usuario}: {motivo}',
                ['operacion' => $operacionLog, 'usuario' => $usuario->id, 'motivo' => $error->getMessage()],
            );

            return false;
        }
    }
}
