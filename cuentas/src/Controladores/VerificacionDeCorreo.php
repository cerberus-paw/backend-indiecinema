<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Servicios\ServicioDeTokens;
use IndieCinema\Cuentas\Servicios\TokenInvalido;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * GET /cuenta/verificar?token=…: valida el token de un solo uso y marca el correo como verificado
 * (X-02.1).
 */
final class VerificacionDeCorreo extends Controlador
{
    private const PLANTILLA = 'verificar_correo.html.twig';

    public function __construct(private readonly ServicioDeTokens $tokens)
    {
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
}
