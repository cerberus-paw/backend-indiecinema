<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Http;

use RuntimeException;

/**
 * Corta la petición con un código HTTP. El manejador de errores la convierte en la página (o el
 * JSON) de ese código, y no la registra como error porque no lo es.
 */
final class ExcepcionHttp extends RuntimeException
{
    /**
     * @param string                $mensaje   lo que ve el usuario: nunca detalles internos
     * @param array<string, string> $cabeceras las que tiene que llevar la respuesta
     */
    public function __construct(
        public readonly int $estado,
        string $mensaje,
        public readonly array $cabeceras = [],
    ) {
        parent::__construct($mensaje, $estado);
    }

    public static function solicitudInvalida(string $mensaje): self
    {
        return new self(400, $mensaje);
    }

    /**
     * 401: la ruta pide un rol y no hay sesión.
     */
    public static function sinSesion(): self
    {
        return new self(401, 'Tenés que ingresar para ver esta página.');
    }

    public static function prohibida(): self
    {
        return new self(403, 'No tenés permiso para entrar acá.');
    }

    /**
     * El token del formulario no coincide con el de la cookie: o el pedido viene de otro sitio, o
     * la cookie se perdió (por ejemplo, al cerrar el navegador) y la página quedó vieja.
     */
    public static function csrfInvalido(): self
    {
        return new self(403, 'La página venció. Volvé a cargarla y probá de nuevo.');
    }

    public static function noEncontrada(): self
    {
        return new self(404, 'No encontramos lo que buscabas.');
    }

    /**
     * @param list<string> $permitidos
     */
    public static function metodoNoPermitido(array $permitidos): self
    {
        return new self(405, 'Esta página no acepta ese tipo de pedido.', ['Allow' => implode(', ', $permitidos)]);
    }
}
