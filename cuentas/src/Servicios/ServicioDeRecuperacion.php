<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateTimeImmutable;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\TokenDeCuenta;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use SensitiveParameter;

/**
 * Solicitud y restablecimiento de contraseña por enlace de un solo uso (X-02.2).
 */
final class ServicioDeRecuperacion
{
    public const DURACION_RECUPERACION = 'PT1H';

    /**
     * Mismo relleno Argon2id que ServicioDeIngreso para que pedir la recuperación de un correo
     * registrado y uno inexistente tarden lo mismo en la respuesta HTTP.
     */
    private const HASH_DE_RELLENO = '$argon2id$v=19$m=65536,t=4,p=1$QTk0enA3cWtHUzRDVVhPTQ$Wv5XVNO1CW9qlMPrXcQGs2OVKZz93vJXO0K0o0Xa48U';

    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        private readonly RepositorioDeSesiones $sesiones,
        private readonly ServicioDeTokens $tokens,
        private readonly CorreoDeCuenta $correos,
    ) {
    }

    /**
     * Emite el token de 1 hora si el correo pertenece a una cuenta activa, ejecutando trabajo
     * criptográfico equivalente si no existe. Devuelve la tarea diferida de envío SMTP (para
     * ejecutar con Respuesta::alTerminar) o null.
     *
     * @return (callable(): bool)|null
     */
    public function solicitar(string $correo, ?DateTimeImmutable $ahora = null): ?callable
    {
        $usuario = $this->usuarios->buscarPorCorreo($correo);
        password_verify('relleno-para-igualar-tiempo', self::HASH_DE_RELLENO);

        if ($usuario === null || $usuario->estado !== EstadoCuenta::Activa) {
            TokenDeCuenta::huellaDe(TokenDeCuenta::nuevoValor());

            return null;
        }

        $token = $this->tokens->emitir($usuario->id, PropositoDeToken::Recuperar, self::DURACION_RECUPERACION, $ahora);

        return fn (): bool => $this->correos->enviarEnlace(
            $usuario,
            '/recuperar/nueva',
            $token,
            'Recuperá tu contraseña en IndieCinema',
            implode("\n", [
                "Hola {$usuario->nombre}:",
                '',
                'Pediste restablecer tu contraseña en IndieCinema. Podés elegir una nueva desde este enlace (vence en 1 hora y sirve una sola vez):',
                CorreoDeCuenta::MARCADOR_ENLACE,
                '',
                'Si no pediste este cambio, podés ignorar este mensaje.',
            ]),
            'recuperar la contraseña',
        );
    }

    /**
     * Comprueba que el enlace siga vigente sin consumirlo todavía.
     *
     * @throws TokenInvalido
     */
    public function validarEnlace(string $token, ?DateTimeImmutable $ahora = null): void
    {
        $this->tokens->verificarDisponible($token, PropositoDeToken::Recuperar, $ahora);
    }

    /**
     * Consume el token, guarda la nueva contraseña (Argon2id) y cierra todas las sesiones de la
     * cuenta en una sola transacción.
     *
     * @throws TokenInvalido
     */
    public function restablecer(
        string $token,
        #[SensitiveParameter] string $nuevaContrasena,
        ?DateTimeImmutable $ahora = null,
    ): string {
        $this->tokens->verificarDisponible($token, PropositoDeToken::Recuperar, $ahora);
        $hash = password_hash($nuevaContrasena, PASSWORD_ARGON2ID);

        return $this->tokens->enTransaccion(function () use ($token, $hash, $ahora): string {
            $usuarioId = $this->tokens->consumir($token, PropositoDeToken::Recuperar, $ahora);
            $this->usuarios->cambiarContrasena($usuarioId, $hash);
            $this->sesiones->borrarDeUsuario($usuarioId);

            return $usuarioId;
        });
    }
}
