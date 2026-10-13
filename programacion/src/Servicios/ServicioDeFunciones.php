<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\ResumenDeFuncion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;

/**
 * Las funciones para los otros subsistemas, por la API interna. Quién puede pedirlas lo controla
 * la ruta (sus llamadores), antes de llegar acá.
 */
final class ServicioDeFunciones
{
    public function __construct(private readonly RepositorioDeFunciones $funciones)
    {
    }

    /**
     * @throws ExcepcionHttp 404 si el id no es un UUID, la función no existe o su sala se dio de baja
     */
    public function resumen(string $id): ResumenDeFuncion
    {
        return (Uuid::esValido($id) ? $this->funciones->resumen($id) : null) ?? throw ExcepcionHttp::noEncontrada();
    }
}
