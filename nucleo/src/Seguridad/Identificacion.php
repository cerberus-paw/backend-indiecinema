<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Seguridad;

use IndieCinema\Nucleo\Http\Peticion;
use Psr\Log\LoggerInterface;

/**
 * Arma el usuario de la petición con las cabeceras que agrega nginx después de validar la cookie
 * de sesión con cuentas (auth_request).
 *
 * Sólo les cree si el pedido trae además el secreto que nginx comparte con este subsistema: en la
 * red interna del compose los subsistemas se alcanzan entre ellos, y sin el secreto uno
 * comprometido podría llamar a otro haciéndose pasar por un administrador (E2, sección 8).
 */
final class Identificacion
{
    public const CABECERA_SECRETO = 'X-Nginx-Secreto';
    public const CABECERA_ID = 'X-Usuario-Id';
    public const CABECERA_ROL = 'X-Rol';
    /**
     * No está en la E2, que dice pedirle el nombre a cuentas para mostrarlo. El encabezado lo
     * muestra en todas las páginas, así que preguntarlo en cada petición sería una llamada más por
     * página: lo manda cuentas junto con el id y el rol. Viaja codificado con rawurlencode() porque
     * una cabecera no garantiza UTF-8 y porque así un nombre no puede meter un salto de línea.
     */
    public const CABECERA_NOMBRE = 'X-Usuario-Nombre';

    public function __construct(private readonly string $secreto, private readonly LoggerInterface $log)
    {
    }

    public function identificar(Peticion $peticion): Peticion
    {
        return $peticion->conUsuario($this->usuarioDe($peticion));
    }

    private function usuarioDe(Peticion $peticion): ?Usuario
    {
        $id = $peticion->cabecera(self::CABECERA_ID) ?? '';

        if (!hash_equals($this->secreto, $peticion->cabecera(self::CABECERA_SECRETO) ?? '')) {
            // Desde afuera nginx las descarta: si llegan sin el secreto, alguien de la red interna
            // está intentando hacerse pasar por otro.
            if ($id !== '') {
                $this->log->warning('Cabeceras de identidad sin el secreto de nginx en {metodo} {ruta}: se ignoran.', [
                    'metodo' => $peticion->metodo(),
                    'ruta' => $peticion->ruta(),
                ]);
            }

            return null;
        }

        $rol = Rol::tryFrom($peticion->cabecera(self::CABECERA_ROL) ?? '');
        // Sin sesión, cuentas responde las cabeceras vacías.
        if ($id === '' || $rol === null || $rol === Rol::Visitante) {
            return null;
        }

        $nombre = $peticion->cabecera(self::CABECERA_NOMBRE) ?? '';

        return new Usuario($id, $rol, $nombre === '' ? null : rawurldecode($nombre));
    }
}
