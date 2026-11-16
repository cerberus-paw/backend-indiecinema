<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\DatosDeRegistro;
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
    public const DURACION_VERIFICACION = ServicioDeVerificacion::DURACION_VERIFICACION;

    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        private readonly ServicioDeVerificacion $verificacion,
    ) {
    }

    /**
     * @throws CorreoYaRegistrado
     */
    public function registrar(DatosDeRegistro $datos): Usuario
    {
        $usuario = Usuario::nuevo($datos->nombre, $datos->correo, password_hash($datos->contrasena, PASSWORD_ARGON2ID));
        $this->usuarios->agregar($usuario);
        $this->verificacion->enviarInicial($usuario);

        return $usuario;
    }
}
