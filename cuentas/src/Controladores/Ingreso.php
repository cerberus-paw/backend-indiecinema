<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Servicios\CredencialesInvalidas;
use IndieCinema\Cuentas\Servicios\CuentaSuspendida;
use IndieCinema\Cuentas\Servicios\ServicioDeIngreso;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * El inicio de sesión, en /cuenta/ingresar, y el cierre, en /cuenta/salir. El núcleo ya controló
 * el token CSRF de los POST antes de llegar acá.
 */
final class Ingreso extends Controlador
{
    private const PLANTILLA = 'ingresar.html.twig';

    public function __construct(private readonly ServicioDeIngreso $servicio)
    {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->formulario(null);
    }

    public function ingresar(Peticion $peticion): Respuesta
    {
        $formulario = FormularioDeIngreso::desdeCampos($peticion->cuerpo());
        if (!$formulario->esValido()) {
            return $this->formulario($formulario, 'Revisá los campos marcados.');
        }

        try {
            $abierta = $this->servicio->ingresar($formulario->correo, $formulario->contrasena, $peticion->cookie(Sesion::COOKIE));
        } catch (CredencialesInvalidas $error) {
            return $this->formulario($formulario, $error->getMessage());
        } catch (CuentaSuspendida) {
            return $this->formulario($formulario, 'Tu cuenta está suspendida. Si creés que es un error, escribinos.');
        }

        // A la portada del sitio, que es de programación: sin el prefijo de cuentas.
        return Respuesta::redireccion('/')->conCookie(
            Sesion::COOKIE,
            $abierta->identificador,
            self::opcionesDeLaCookie($abierta->sesion->venceEn->getTimestamp()),
        );
    }

    public function salir(Peticion $peticion): Respuesta
    {
        $identificador = $peticion->cookie(Sesion::COOKIE);
        if ($identificador !== null) {
            $this->servicio->salir($identificador);
        }

        // Vencida en el pasado, el navegador la borra.
        return Respuesta::redireccion('/')->conCookie(Sesion::COOKIE, '', self::opcionesDeLaCookie(1));
    }

    /**
     * HttpOnly, para que el JavaScript no la lea; Secure, para que viaje sólo por HTTPS (o a
     * localhost); y SameSite=Lax, para que no vaya en los POST de otros sitios. Path=/ y sin
     * Domain: la mandan todos los subsistemas, que comparten el host.
     *
     * @return array<string, mixed>
     */
    private static function opcionesDeLaCookie(int $vence): array
    {
        return [
            'expires' => $vence,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private function formulario(?FormularioDeIngreso $formulario, ?string $error = null): Respuesta
    {
        $datos = [
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
        ];
        // La plantilla muestra el mensaje si la variable existe, aunque sea null.
        if ($error !== null) {
            $datos['error_general'] = $error;
        }

        return $this->vista(self::PLANTILLA, $datos, $error === null ? 200 : 422);
    }
}
