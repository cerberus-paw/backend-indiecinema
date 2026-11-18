<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Repositorios\RepositorioDeAvisos;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Los correos al organizador de una sala por su documentación (EF-07.5), cada uno una sola vez. El
 * organizador lo dice programación y su dirección, cuentas. Si alguno no responde o el correo
 * falla, el aviso queda para la próxima vuelta de la tarea: lo que lo disparó (una suspensión, por
 * ejemplo) queda igual.
 */
final class AvisosAlOrganizador
{
    public function __construct(
        private readonly RepositorioDeAvisos $avisos,
        private readonly SalasDeProgramacion $salas,
        private readonly UsuariosDeCuentas $usuarios,
        private readonly ClienteSmtp $correo,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @param string                               $clave qué aviso es, para no repetirlo
     * @param Closure(string): array{string, string} $texto con el nombre de la sala, el asunto y el texto
     *
     * @return bool si salió ahora
     */
    public function avisar(string $salaId, string $clave, Closure $texto): bool
    {
        if (!$this->avisos->reservar($salaId, $clave, new DateTimeImmutable('now', new DateTimeZone('UTC')))) {
            return false;
        }
        try {
            $sala = $this->salas->datosDe($salaId) ?? throw new RuntimeException('programación ya no tiene la sala.');
            $organizador = $this->usuarios->buscar($sala['organizador_id']) ?? throw new RuntimeException('cuentas ya no tiene a su organizador.');
            [$asunto, $cuerpo] = $texto($sala['nombre']);
            $this->correo->enviar(new Correo($organizador->correo, $asunto, $cuerpo));
        } catch (Throwable $error) {
            $this->avisos->liberar($salaId, $clave);
            $this->log->warning('No salió el aviso a la sala {sala} ({clave}): {mensaje}. Se reintenta en la próxima vuelta.', [
                'sala' => $salaId,
                'clave' => $clave,
                'mensaje' => $error->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * La página donde el organizador sube el documento nuevo (EF-07.5).
     */
    public static function documentacion(string $salaId): string
    {
        return "/moderacion/salas/{$salaId}/documentacion";
    }
}
