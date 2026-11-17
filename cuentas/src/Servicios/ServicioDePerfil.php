<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Http\ExcepcionHttp;

/**
 * Edición del nombre, correo y contraseña desde «Mi cuenta» (EF-15.1 / IC-563).
 *
 * Cambiar el correo exige la contraseña actual, marca la cuenta como sin verificar, envía un enlace
 * nuevo y cierra las demás sesiones con RepositorioDeSesiones::borrarDeUsuario(). Cambiar la
 * contraseña también exige la actual y cierra las demás sesiones.
 */
final class ServicioDePerfil
{
    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        private readonly RepositorioDeSesiones $sesiones,
        private readonly ServicioDeTokens $tokens,
        private readonly ServicioDeVerificacion $verificacion,
    ) {
    }

    /**
     * @throws ExcepcionHttp              404 si la cuenta no existe
     * @throws ContrasenaActualIncorrecta si cambió el correo sin contraseña actual válida
     * @throws CorreoYaRegistrado         si otro usuario ya usa ese correo
     */
    public function actualizarDatos(
        string $usuarioId,
        string $nombre,
        string $correo,
        string $contrasenaActual,
        ?string $cookieSesion,
    ): bool {
        $usuario = $this->usuarios->buscarPorId($usuarioId) ?? throw ExcepcionHttp::noEncontrada();
        $cambioCorreo = strcasecmp($correo, $usuario->correo) !== 0;

        if ($cambioCorreo) {
            if ($contrasenaActual === '') {
                throw ContrasenaActualIncorrecta::faltanteParaCambiarCorreo();
            }
            if (!password_verify($contrasenaActual, $usuario->contrasenaHash)) {
                throw ContrasenaActualIncorrecta::noCoincide();
            }
        }

        $huellaActual = $cookieSesion === null ? null : Sesion::huellaDe($cookieSesion);
        $ahora = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->tokens->enTransaccion(function () use ($usuario, $nombre, $correo, $cambioCorreo, $ahora, $huellaActual): void {
            $this->usuarios->actualizarPerfil($usuario->id, $nombre, $correo, reiniciarVerificacion: $cambioCorreo);
            if ($cambioCorreo) {
                $this->tokens->invalidarPendientes($usuario->id, PropositoDeToken::Verificar, $ahora);
                $this->sesiones->borrarDeUsuario($usuario->id, $huellaActual);
            }
        });

        if ($cambioCorreo) {
            $actualizado = $this->usuarios->buscarPorId($usuario->id);
            if ($actualizado instanceof Usuario) {
                $this->verificacion->enviarInicial($actualizado);
            }
        }

        return $cambioCorreo;
    }

    /**
     * @throws ExcepcionHttp              404 si la cuenta no existe
     * @throws ContrasenaActualIncorrecta si la contraseña actual no coincide
     */
    public function cambiarContrasena(
        string $usuarioId,
        string $contrasenaActual,
        string $contrasenaNueva,
        ?string $cookieSesion,
    ): void {
        $usuario = $this->usuarios->buscarPorId($usuarioId) ?? throw ExcepcionHttp::noEncontrada();
        if (!password_verify($contrasenaActual, $usuario->contrasenaHash)) {
            throw ContrasenaActualIncorrecta::noCoincide();
        }

        $huellaActual = $cookieSesion === null ? null : Sesion::huellaDe($cookieSesion);
        $hash = password_hash($contrasenaNueva, PASSWORD_ARGON2ID);

        $this->tokens->enTransaccion(function () use ($usuario, $hash, $huellaActual): void {
            $this->usuarios->actualizarContrasena($usuario->id, $hash);
            $this->sesiones->borrarDeUsuario($usuario->id, $huellaActual);
        });
    }
}
