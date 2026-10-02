<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Seguridad;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * Token CSRF con doble envío: el token va en una cookie y en cada formulario, y un POST sólo pasa
 * si los dos coinciden. Otro sitio puede hacer que el navegador mande la cookie, pero no puede
 * leerla para ponerla en el formulario.
 *
 * Se eligió así porque los subsistemas no guardan estado de sesión: la sesión vive en cuentas, y
 * guardar el token del lado del servidor obligaría a cada subsistema a tener su propia tabla. La
 * cookie es una sola para todo el sitio, así un formulario lo puede servir un subsistema y
 * recibirlo otro.
 */
final class ProteccionCsrf
{
    public const COOKIE = 'csrf';
    /** El campo oculto de los formularios. */
    public const CAMPO = '_csrf';
    /** Para los pedidos del JavaScript propio, que no mandan un formulario. */
    public const CABECERA = 'X-CSRF-Token';

    private const METODOS_SEGUROS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Le pone a la petición el token de la cookie o, si no hay uno válido, uno nuevo, que
     * guardarToken() manda en la respuesta. Las rutas /interno/ no lo necesitan: las llama otro
     * subsistema con su token de servicio, no un navegador.
     */
    public function asignarToken(Peticion $peticion): Peticion
    {
        if (str_starts_with($peticion->ruta(), '/interno/')) {
            return $peticion;
        }
        $token = $peticion->cookie(self::COOKIE);

        return $peticion->conTokenCsrf(self::esValido($token) ? $token : bin2hex(random_bytes(32)));
    }

    /**
     * @throws ExcepcionHttp 403 si un pedido que cambia algo no trae el mismo token que la cookie
     */
    public function verificar(Peticion $peticion): void
    {
        if (in_array($peticion->metodo(), self::METODOS_SEGUROS, true) || $peticion->rutaResuelta()?->esInterna()) {
            return;
        }

        $esperado = $peticion->cookie(self::COOKIE);
        $recibido = $peticion->campo(self::CAMPO) ?? $peticion->cabecera(self::CABECERA);
        if (!self::esValido($esperado) || !is_string($recibido) || !hash_equals($esperado, $recibido)) {
            throw ExcepcionHttp::csrfInvalido();
        }
    }

    /**
     * Manda la cookie sólo si el token es nuevo. SameSite=Lax y no Strict: con Strict, quien
     * llega desde un link de otro sitio no la manda, recibe un token nuevo y los formularios que
     * tenía abiertos en otras pestañas dejan de andar. Lax ya la deja afuera de los POST de otros
     * sitios, que es lo que importa.
     */
    public function guardarToken(Peticion $peticion, Respuesta $respuesta): Respuesta
    {
        $token = $peticion->tokenCsrf();
        if ($token === null || $token === $peticion->cookie(self::COOKIE)) {
            return $respuesta;
        }

        return $respuesta->conCookie(self::COOKIE, $token, [
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function esValido(?string $token): bool
    {
        return $token !== null && preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }
}
