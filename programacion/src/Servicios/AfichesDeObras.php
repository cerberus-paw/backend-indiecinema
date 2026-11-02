<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;

/**
 * Los afiches de las obras, en la carpeta afiches del volumen de archivos de programación (donde
 * los copian los datos de prueba). Como las fotos de las salas, quedan fuera del webroot y los
 * entrega un controlador.
 */
final class AfichesDeObras
{
    private const NOMBRE = '/^[0-9a-f]{32}\.(jpg|png|webp)$/D';

    private readonly string $carpeta;

    public function __construct(Configuracion $configuracion)
    {
        $this->carpeta = rtrim((string) $configuracion->obtener('archivos.carpeta', '/var/indiecinema/archivos'), '/') . '/afiches';
    }

    /**
     * @return ?string null si no está o si el nombre no tiene la forma de un afiche
     */
    public function contenido(string $nombre): ?string
    {
        // El nombre sale de la base; si no tiene la forma esperada, no se arma una ruta con él.
        if (preg_match(self::NOMBRE, $nombre) !== 1) {
            return null;
        }
        $contenido = @file_get_contents("{$this->carpeta}/{$nombre}");

        return $contenido === false ? null : $contenido;
    }
}
