<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Seguridad;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use Psr\Log\LoggerInterface;

/**
 * Autoriza las rutas /interno/: el pedido dice qué subsistema llama (X-Llamador), trae su token
 * (Authorization: Bearer …) y ese subsistema tiene que estar entre los llamadores que declara la
 * ruta (E2, sección 4). nginx ya bloquea /interno/ desde afuera; esto cuida la red interna, donde
 * todos los contenedores se alcanzan entre sí.
 *
 * De cada llamador se guarda el SHA-256 de su token y no el token: un subsistema comprometido lee
 * en su config.ini con qué tokens lo llaman los demás, pero no los puede usar para hacerse pasar
 * por ellos ante un tercero. Los tokens son al azar y largos, así que alcanza con SHA-256.
 */
final class ControlDeLlamadores
{
    public const CABECERA_LLAMADOR = 'X-Llamador';

    /**
     * @param array<string, string> $huellas llamador => SHA-256 de su token (sección [llamadores])
     */
    public function __construct(private readonly array $huellas, private readonly LoggerInterface $log)
    {
    }

    /**
     * @throws ExcepcionHttp 403 si falta el llamador o el token, si el token no es el suyo o si la
     *                       ruta no acepta a ese llamador
     */
    public function autenticar(Peticion $peticion): Peticion
    {
        $ruta = $peticion->rutaResuelta();
        if ($ruta === null || !$ruta->esInterna()) {
            return $peticion;
        }

        $llamador = $peticion->cabecera(self::CABECERA_LLAMADOR) ?? '';
        $token = self::tokenDe($peticion);
        $huella = $this->huellas[$llamador] ?? null;
        $contexto = ['metodo' => $peticion->metodo(), 'ruta' => $ruta->patron, 'llamador' => $llamador];

        if ($huella === null || $token === null || !hash_equals($huella, hash('sha256', $token))) {
            $this->log->warning('Llamada a {metodo} {ruta} sin el token de «{llamador}»: se rechaza.', $contexto);

            throw ExcepcionHttp::prohibida();
        }
        if (!in_array($llamador, $ruta->llamadores, true)) {
            $this->log->warning('{llamador} llamó a {metodo} {ruta}, que no lo acepta: se rechaza.', $contexto);

            throw ExcepcionHttp::prohibida();
        }

        return $peticion->conLlamador($llamador);
    }

    private static function tokenDe(Peticion $peticion): ?string
    {
        $autorizacion = $peticion->cabecera('Authorization') ?? '';

        return preg_match('/^Bearer\s+(\S+)$/', $autorizacion, $coincidencia) === 1 ? $coincidencia[1] : null;
    }
}
