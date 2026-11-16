<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\DatosDeRegistro;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;

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
        private readonly CorreoDeCuenta $correos,
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
        $this->correos->enviarEnlace(
            $usuario,
            '/verificar',
            $token,
            'Verificá tu correo en IndieCinema',
            implode("\n", [
                "Hola {$usuario->nombre}:",
                '',
                'Creaste tu cuenta en IndieCinema. Confirmá tu correo abriendo este enlace (vence en 24 horas):',
                CorreoDeCuenta::MARCADOR_ENLACE,
                '',
                'Si no creaste esta cuenta, podés ignorar este mensaje.',
            ]),
            'verificar el correo',
        );

        return $usuario;
    }
}
