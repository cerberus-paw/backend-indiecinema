<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;

/**
 * Los avisos de error que vuelven a la ficha de una función en la URL, después de un formulario que
 * no salió (votar un título, marcar una franja, confirmar o cerrar el acuerdo): ?error_voto=… o
 * ?error_acuerdo=…, con el texto de la regla que no se cumplió.
 *
 * El texto va firmado (F-01.4). Sin la firma, cualquiera armaba un link del sitio que mostraba el
 * mensaje que quisiera, con el formato de un error de verdad: Twig lo escapaba, así que no era XSS,
 * pero sí un aviso falso con nuestra dirección. La firma es un HMAC del tipo de aviso, la función y
 * el texto: un link armado a mano no la trae, y la de un aviso real no sirve para otro texto ni para
 * otra función. Se firma y no se reemplaza por códigos fijos porque los textos salen de muchas
 * reglas, también de las de funciones que programación reenvía, y una lista se quedaría atrás.
 *
 * La clave es el secreto que programación comparte con nginx, que todo subsistema tiene que tener
 * (Aplicacion lo exige al arrancar), con un prefijo propio para que esta firma no sirva para otra
 * cosa ni otra para ésta.
 */
final class AvisosDeLaFicha
{
    public const VOTO = 'error_voto';
    public const ACUERDO = 'error_acuerdo';

    public function __construct(private readonly Configuracion $configuracion)
    {
    }

    /**
     * La dirección de la ficha con el aviso y su firma, para redirigir.
     *
     * @param self::VOTO|self::ACUERDO $tipo
     */
    public function direccion(string $funcionId, string $tipo, string $mensaje): string
    {
        return "/cartelera/{$funcionId}?" . http_build_query([$tipo => $mensaje, 'firma' => $this->firma($funcionId, $tipo, $mensaje)], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * El aviso de la URL, sólo si su firma es la de este texto para esta función; si no, null.
     *
     * @param self::VOTO|self::ACUERDO $tipo
     */
    public function leer(Peticion $peticion, string $funcionId, string $tipo): ?string
    {
        $mensaje = $peticion->consulta($tipo);
        $firma = $peticion->consulta('firma');
        if (!is_string($mensaje) || $mensaje === '' || !is_string($firma)) {
            return null;
        }

        return hash_equals($this->firma($funcionId, $tipo, $mensaje), $firma) ? $mensaje : null;
    }

    private function firma(string $funcionId, string $tipo, string $mensaje): string
    {
        $clave = (string) $this->configuracion->requerir('nginx.secreto');

        return hash_hmac('sha256', "aviso-de-la-ficha\n{$tipo}\n{$funcionId}\n{$mensaje}", $clave);
    }
}
