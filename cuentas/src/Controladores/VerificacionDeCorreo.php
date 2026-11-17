<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Servicios\ServicioDeTokens;
use IndieCinema\Cuentas\Servicios\ServicioDeUsuarios;
use IndieCinema\Cuentas\Servicios\ServicioDeVerificacion;
use IndieCinema\Cuentas\Servicios\TokenInvalido;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use LogicException;

/**
 * GET /cuenta/verificar?token=… y POST /cuenta/verificar/reenviar (X-02.1, X-02.4).
 */
final class VerificacionDeCorreo extends Controlador
{
    private const PLANTILLA = 'verificar_correo.html.twig';

    public function __construct(
        private readonly ServicioDeTokens $tokens,
        private readonly ServicioDeVerificacion $verificacion,
        private readonly ServicioDeUsuarios $usuarios,
    ) {
    }

    public function verificar(Peticion $peticion): Respuesta
    {
        $token = $peticion->consulta('token');
        if (!is_string($token) || $token === '') {
            return $this->vista(self::PLANTILLA, [
                'verificado' => false,
                'titulo' => 'Enlace de verificación inválido',
                'mensaje_titulo' => 'Falta el código de verificación',
                'mensaje' => 'El enlace está incompleto o no es válido.',
            ], 400);
        }

        try {
            $this->tokens->verificarCorreo($token);
        } catch (TokenInvalido $error) {
            return $this->vista(self::PLANTILLA, [
                'verificado' => false,
                'titulo' => 'No pudimos verificar tu correo',
                'mensaje_titulo' => 'Enlace no válido',
                'mensaje' => $error->getMessage(),
            ], $error->estadoHttp);
        }

        return $this->vista(self::PLANTILLA, [
            'verificado' => true,
            'titulo' => 'Correo verificado',
            'mensaje_titulo' => '¡Correo verificado!',
            'mensaje' => 'Tu correo quedó verificado. Ya podés votar títulos, acordar fechas y reservar entradas.',
        ]);
    }

    public function reenviar(Peticion $peticion): Respuesta
    {
        $usuario = $peticion->usuario() ?? throw new LogicException('La ruta pide sesión.');
        $volver = self::funcionParaVolver($peticion->campo('volver'));

        try {
            $reenviado = $this->verificacion->reenviar($usuario->id);
        } catch (ExcepcionHttp $error) {
            if ($error->estado === 429 && !$peticion->aceptaJson() && $volver === null) {
                $cuenta = $this->usuarios->buscarEnListado($usuario->id);
                if ($cuenta !== null) {
                    return $this->vista('mi_cuenta.html.twig', Inicio::datosDeMiCuenta(
                        $cuenta,
                        false,
                        false,
                        $error->getMessage(),
                    ), 429);
                }
            }

            throw $error;
        }

        if ($peticion->aceptaJson()) {
            return $this->json(['ok' => true, 'reenviado' => $reenviado]);
        }

        if ($volver !== null) {
            return Respuesta::redireccion($reenviado ? "{$volver}?reenviado=1" : $volver);
        }

        return $this->redirigir($reenviado ? '/?reenviado=1' : '/');
    }

    private static function funcionParaVolver(mixed $volver): ?string
    {
        if (!is_string($volver) || !preg_match('#^/cartelera/([0-9a-f-]+)$#i', $volver, $coincidencias)) {
            return null;
        }

        return Uuid::esValido($coincidencias[1]) ? '/cartelera/' . strtolower($coincidencias[1]) : null;
    }
}
