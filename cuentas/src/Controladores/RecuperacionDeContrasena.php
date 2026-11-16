<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Servicios\ServicioDeRecuperacion;
use IndieCinema\Cuentas\Servicios\TokenInvalido;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * GET|POST /cuenta/recuperar y /cuenta/recuperar/nueva (X-02.2).
 */
final class RecuperacionDeContrasena extends Controlador
{
    private const PLANTILLA_SOLICITUD = 'recuperar.html.twig';
    private const PLANTILLA_NUEVA = 'recuperar_nueva.html.twig';

    public function __construct(private readonly ServicioDeRecuperacion $servicio)
    {
    }

    public function mostrarSolicitud(Peticion $peticion): Respuesta
    {
        return $this->vista(self::PLANTILLA_SOLICITUD, [
            'enviado' => $peticion->consulta('enviada') === '1',
            'valores' => [],
            'errores' => [],
        ]);
    }

    public function solicitar(Peticion $peticion): Respuesta
    {
        $formulario = FormularioDeRecuperacion::desdeCampos($peticion->cuerpo());
        if (!$formulario->esValido()) {
            return $this->vista(self::PLANTILLA_SOLICITUD, [
                'enviado' => false,
                'valores' => $formulario->valores,
                'errores' => $formulario->errores,
            ], 422);
        }

        $envioCorreo = $this->servicio->solicitar($formulario->correo);
        $respuesta = $this->redirigir('/recuperar?enviada=1');

        return $envioCorreo === null ? $respuesta : $respuesta->alTerminar($envioCorreo);
    }

    public function mostrarNueva(Peticion $peticion): Respuesta
    {
        $token = $peticion->consulta('token');
        if (!is_string($token) || $token === '') {
            return $this->enlaceInvalido('El enlace está incompleto o no es válido.', 400);
        }

        try {
            $this->servicio->validarEnlace($token);
        } catch (TokenInvalido $error) {
            return $this->enlaceInvalido($error->getMessage(), $error->estadoHttp);
        }

        return $this->vista(self::PLANTILLA_NUEVA, [
            'token_valido' => true,
            'token' => $token,
            'errores' => [],
        ]);
    }

    public function restablecer(Peticion $peticion): Respuesta
    {
        $cuerpo = $peticion->cuerpo();
        $token = $cuerpo['token'] ?? $peticion->consulta('token');
        if (!is_string($token) || $token === '') {
            return $this->enlaceInvalido('El enlace está incompleto o no es válido.', 400);
        }

        try {
            $this->servicio->validarEnlace($token);
        } catch (TokenInvalido $error) {
            return $this->enlaceInvalido($error->getMessage(), $error->estadoHttp);
        }

        $formulario = FormularioDeNuevaContrasena::desdeCampos($cuerpo);
        if (!$formulario->esValido()) {
            return $this->vista(self::PLANTILLA_NUEVA, [
                'token_valido' => true,
                'token' => $token,
                'errores' => $formulario->errores,
            ], 422);
        }

        try {
            $this->servicio->restablecer($token, $formulario->contrasena);
        } catch (TokenInvalido $error) {
            return $this->enlaceInvalido($error->getMessage(), $error->estadoHttp);
        }

        $respuesta = $this->redirigir('/ingresar?recuperada=1');
        if ($peticion->cookie(Sesion::COOKIE) !== null) {
            return $respuesta->conCookie(Sesion::COOKIE, '', [
                'expires' => 1,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $respuesta;
    }

    private function enlaceInvalido(string $mensaje, int $estado): Respuesta
    {
        return $this->vista(self::PLANTILLA_NUEVA, [
            'token_valido' => false,
            'error_general' => $mensaje,
        ], $estado);
    }
}
