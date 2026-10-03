<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\SesionAbierta;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use SensitiveParameter;

/**
 * El inicio y el cierre de sesión con correo y contraseña.
 */
final class ServicioDeIngreso
{
    /**
     * Un hash Argon2id de una contraseña al azar que nadie conoce. Si el correo no tiene cuenta,
     * se verifica contra este: así la respuesta tarda lo mismo que con una contraseña incorrecta y
     * el tiempo tampoco dice qué correos están registrados.
     */
    private const HASH_DE_RELLENO = '$argon2id$v=19$m=65536,t=4,p=1$QTk0enA3cWtHUzRDVVhPTQ$Wv5XVNO1CW9qlMPrXcQGs2OVKZz93vJXO0K0o0Xa48U';

    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        private readonly ServicioDeSesiones $sesiones,
    ) {
    }

    /**
     * Abre una sesión nueva, con un identificador nuevo. Si el navegador ya tenía una, se cierra:
     * cada inicio de sesión cambia el identificador.
     *
     * @param ?string $anterior el identificador de la cookie que ya tenía, si tenía una
     *
     * @throws CredencialesInvalidas
     * @throws CuentaSuspendida
     */
    public function ingresar(string $correo, #[SensitiveParameter] string $contrasena, #[SensitiveParameter] ?string $anterior): SesionAbierta
    {
        $usuario = $this->usuarios->buscarPorCorreo($correo);
        $correcta = password_verify($contrasena, $usuario->contrasenaHash ?? self::HASH_DE_RELLENO);
        if ($usuario === null || !$correcta) {
            throw new CredencialesInvalidas('El correo o la contraseña no son correctos.');
        }
        if ($usuario->estado !== EstadoCuenta::Activa) {
            throw new CuentaSuspendida('La cuenta no está activa.');
        }

        if ($anterior !== null) {
            $this->sesiones->cerrar($anterior);
        }

        return $this->sesiones->abrir($usuario->id);
    }

    public function salir(#[SensitiveParameter] string $identificador): void
    {
        $this->sesiones->cerrar($identificador);
    }
}
