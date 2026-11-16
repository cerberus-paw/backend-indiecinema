<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\DatosDeRegistro;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El alta de una cuenta. La contraseña se guarda sólo como hash Argon2id (E2, seguridad), con los
 * parámetros por defecto de PHP; el hash lleva el algoritmo y los parámetros, así que si cambian
 * las cuentas viejas siguen entrando.
 *
 * Emite un token de verificación (24 h) y envía el enlace por correo; si el envío falla, la cuenta
 * queda creada igual (X-02.1).
 */
final class ServicioDeRegistro
{
    public const DURACION_VERIFICACION = 'PT24H';

    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        private readonly ServicioDeTokens $tokens,
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
        private readonly ?ClienteSmtp $correo = null,
    ) {
    }

    /**
     * @throws CorreoYaRegistrado
     */
    public function registrar(DatosDeRegistro $datos): Usuario
    {
        $usuario = Usuario::nuevo($datos->nombre, $datos->correo, password_hash($datos->contrasena, PASSWORD_ARGON2ID));
        $this->usuarios->agregar($usuario);

        $token = $this->tokens->emitir($usuario->id, PropositoDeToken::Verificar, self::DURACION_VERIFICACION);
        $this->enviarVerificacion($usuario, $token);

        return $usuario;
    }

    private function enviarVerificacion(Usuario $usuario, string $token): void
    {
        if ($this->correo === null) {
            return;
        }

        $baseUrl = rtrim((string) $this->configuracion->obtener('sitio.url', ''), '/');
        $prefijo = trim((string) $this->configuracion->obtener('app.prefijo', '/cuenta'), '/');
        $ruta = ($prefijo === '' ? '' : "/{$prefijo}") . '/verificar?token=' . urlencode($token);
        $enlace = $baseUrl . $ruta;

        $cuerpo = implode("\n", [
            "Hola {$usuario->nombre}:",
            '',
            'Creaste tu cuenta en IndieCinema. Confirmá tu correo abriendo este enlace (vence en 24 horas):',
            $enlace,
            '',
            'Si no creaste esta cuenta, podés ignorar este mensaje.',
        ]);

        try {
            $this->correo->enviar(new Correo($usuario->correo, 'Verificá tu correo en IndieCinema', $cuerpo));
        } catch (Throwable $error) {
            $this->log->warning(
                'La cuenta {usuario} se creó, pero no se pudo enviar el correo de verificación: {motivo}',
                ['usuario' => $usuario->id, 'motivo' => $error->getMessage()],
            );
        }
    }
}
