<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

use IndieCinema\Nucleo\Http\Respuesta;
use LogicException;

/**
 * Base de los controladores: validan la entrada, llaman a un servicio y deciden la respuesta.
 * No llevan SQL ni reglas de negocio (E2, sección 5).
 *
 * La vista y el prefijo no llegan por el constructor, así cada controlador puede pedir en el suyo
 * sólo los servicios que usa sin tener que pasarle nada a esta clase.
 */
abstract class Controlador
{
    private ?Vista $vista = null;

    private string $prefijo = '';

    /**
     * La llama la aplicación antes de despachar; los controladores no la usan.
     *
     * @internal
     */
    final public function prepararParaResponder(Vista $vista, string $prefijo): void
    {
        $this->vista = $vista;
        $this->prefijo = $prefijo;
    }

    /**
     * El usuario, el token CSRF y la ruta actual no hace falta pasarlos: los tiene toda plantilla.
     *
     * @param array<string, mixed> $datos
     */
    protected function vista(string $plantilla, array $datos = [], int $estado = 200): Respuesta
    {
        if ($this->vista === null) {
            throw new LogicException(static::class . ' respondió sin pasar por la aplicación.');
        }

        return Respuesta::html($this->vista->renderizar($plantilla, $datos), $estado);
    }

    /**
     * Redirige dentro del subsistema: agrega el prefijo que nginx sacó.
     */
    protected function redirigir(string $ruta): Respuesta
    {
        return Respuesta::redireccion($this->prefijo . $ruta);
    }

    protected function json(mixed $datos, int $estado = 200): Respuesta
    {
        return Respuesta::json($datos, $estado);
    }
}
