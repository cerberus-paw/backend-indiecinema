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
        return $this->formulario(null, volver: self::volver($peticion->consulta('volver')));
    }

    public function ingresar(Peticion $peticion): Respuesta
    {
        $formulario = FormularioDeIngreso::desdeCampos($peticion->cuerpo());
        $volver = self::volver($peticion->campo('volver'));
        if (!$formulario->esValido()) {
            // Sin mensaje general: la plantilla resume los errores de los campos (E4-10.5).
            return $this->formulario($formulario, volver: $volver);
        }

        try {
            $abierta = $this->servicio->ingresar($formulario->correo, $formulario->contrasena, $peticion->cookie(Sesion::COOKIE));
        } catch (CredencialesInvalidas $error) {
            return $this->formulario($formulario, $error->getMessage(), $volver);
        } catch (CuentaSuspendida) {
            return $this->formulario($formulario, 'Tu cuenta está suspendida. Si creés que es un error, escribinos.', $volver);
        }

        // A la página que pidió (EF-07.8) o a la portada del sitio, que es de programación: sin
        // el prefijo de cuentas.
        return Respuesta::redireccion($volver ?? '/')->conCookie(
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

    /**
     * La página adonde volver después de ingresar, sólo si es del sitio: una ruta que empieza con
     * una sola barra y sin barras invertidas, que algunos navegadores leen como //. Así el link de
     * ingresar no sirve para mandar a alguien a otro sitio.
     */
    private static function volver(mixed $volver): ?string
    {
        if (!is_string($volver) || !str_starts_with($volver, '/') || str_starts_with($volver, '//') || str_contains($volver, '\\')
            || preg_match('/\p{Cc}/u', $volver) !== 0) {
            return null;
        }

        return $volver;
    }

    private function formulario(?FormularioDeIngreso $formulario, ?string $error = null, ?string $volver = null): Respuesta
    {
        $datos = [
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
            'volver' => $volver,
        ];
        // La plantilla muestra el mensaje si la variable existe, aunque sea null.
        if ($error !== null) {
            $datos['error_general'] = $error;
        }

        return $this->vista(self::PLANTILLA, $datos, $error === null && $datos['errores'] === [] ? 200 : 422);
    }
}
