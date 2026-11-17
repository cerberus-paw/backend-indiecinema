<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Http\ExcepcionHttp;

/**
 * Emisión y reenvío de enlaces de verificación de correo (X-02.1, X-02.4).
 *
 * Un reenvío invalida los tokens de verificación pendientes anteriores y admite como máximo uno
 * cada 5 minutos por cuenta.
 */
final class ServicioDeVerificacion
{
    public const DURACION_VERIFICACION = 'PT24H';
    public const ESPERA_REENVIO = 'PT5M';
    public const MENSAJE_ESPERA = 'Podés pedir un enlace nuevo cada 5 minutos. Revisá tu casilla o esperá unos minutos.';

    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        private readonly ServicioDeTokens $tokens,
        private readonly CorreoDeCuenta $correos,
    ) {
    }

    public function enviarInicial(Usuario $usuario, ?DateTimeImmutable $ahora = null): void
    {
        $token = $this->tokens->emitir($usuario->id, PropositoDeToken::Verificar, self::DURACION_VERIFICACION, $ahora);
        $this->enviarCorreo($usuario, $token);
    }

    /**
     * Emite un nuevo enlace de verificación (24 h), invalida los anteriores y envía el correo.
     * Devuelve false si la cuenta ya tenía el correo verificado.
     *
     * @throws ExcepcionHttp 404 si la cuenta no existe, 429 si se pidió otro enlace hace menos de 5 minutos
     */
    public function reenviar(string $usuarioId, ?DateTimeImmutable $ahora = null): bool
    {
        $instante = ($ahora ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));

        /** @var ?array{usuario: Usuario, token: string} $emision */
        $emision = $this->tokens->enTransaccion(function () use ($usuarioId, $instante): ?array {
            $usuario = $this->usuarios->buscarPorId($usuarioId, bloquear: true);
            if ($usuario === null) {
                throw ExcepcionHttp::noEncontrada();
            }
            if ($usuario->correoVerificadoEn !== null) {
                return null;
            }
            if ($usuario->verificacionReenviadaEn !== null
                && $usuario->verificacionReenviadaEn->add(new DateInterval(self::ESPERA_REENVIO)) > $instante) {
                throw new ExcepcionHttp(429, self::MENSAJE_ESPERA);
            }

            $this->tokens->invalidarPendientes($usuario->id, PropositoDeToken::Verificar, $instante);
            $token = $this->tokens->emitir($usuario->id, PropositoDeToken::Verificar, self::DURACION_VERIFICACION, $instante);
            $this->usuarios->marcarVerificacionReenviada($usuario->id, $instante);

            return ['usuario' => $usuario, 'token' => $token];
        });

        if ($emision === null) {
            return false;
        }

        $this->enviarCorreo($emision['usuario'], $emision['token']);

        return true;
    }

    private function enviarCorreo(Usuario $usuario, string $token): void
    {
        $this->correos->enviarEnlace(
            $usuario,
            '/verificar',
            $token,
            'Verificá tu correo en IndieCinema',
            implode("\n", [
                "Hola {$usuario->nombre}:",
                '',
                'Confirmá tu correo en IndieCinema abriendo este enlace (vence en 24 horas):',
                CorreoDeCuenta::MARCADOR_ENLACE,
                '',
                'Si no creaste esta cuenta, podés ignorar este mensaje.',
            ]),
            'verificar el correo',
        );
    }
}
