<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\DatosDeSolicitud;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use IndieCinema\Nucleo\Correo\CorreoNoEnviado;
use IndieCinema\Nucleo\Seguridad\Usuario;
use Psr\Log\LoggerInterface;

/**
 * La solicitud para ser organizador le llega por correo al equipo, a la dirección de
 * [solicitudes] destino de config.ini, y responderla le escribe al contacto que dejó el
 * espectador. Dar el rol después es del administrador, desde «Usuarios y roles».
 */
final class ServicioDeSolicitudes
{
    public function __construct(
        private readonly ClienteSmtp $correo,
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @throws CorreoNoEnviado si el servidor de correo no responde o no lo acepta
     */
    public function enviar(DatosDeSolicitud $datos, Usuario $usuario): void
    {
        $this->correo->enviar(new Correo(
            (string) $this->configuracion->requerir('solicitudes.destino'),
            "Solicitud para ser organizador: {$datos->sala}",
            self::texto($datos, $usuario),
            $datos->correo,
        ));
        // El id y no los datos de contacto: el log no guarda datos personales.
        $this->log->info('El usuario {usuario} pidió ser organizador.', ['usuario' => $usuario->id]);
    }

    private static function texto(DatosDeSolicitud $datos, Usuario $usuario): string
    {
        return implode("\n", [
            ($usuario->nombre ?? 'Un espectador') . ' pidió ser organizador para abrir una sala en IndieCinema.',
            '',
            "Sala: {$datos->sala}",
            "Localidad: {$datos->localidad}",
            "Correo de contacto: {$datos->correo}",
            'Teléfono: ' . ($datos->telefono ?? 'no lo dejó'),
            "Usuario: {$usuario->id}",
            '',
            'Presentación:',
            $datos->presentacion,
            '',
            'Declaró que el espacio está habilitado y se comprometió a tener los derechos de exhibición.',
            '',
            'El rol se da desde «Usuarios y roles», en el panel del administrador. Respondiendo este',
            'correo le escribís a su correo de contacto.',
        ]);
    }
}
