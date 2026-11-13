<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\FuncionFinalizada;
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

    /**
     * Varias de una vez, para una página de «Mis reservas» de funciones. Las que no existen o
     * cuya sala se dio de baja no vienen: quien pide decide qué mostrar en su lugar.
     *
     * @param list<string> $ids UUID válidos, sin repetir: los controla el controlador
     *
     * @return array<string, ResumenDeFuncion> con el id como clave
     */
    public function resumenes(array $ids): array
    {
        return $this->funciones->resumenes($ids);
    }

    /**
     * Las programadas que empiezan en el rango, para el recordatorio de funciones (EF-03.3).
     *
     * @return list<ResumenDeFuncion>
     */
    public function programadasEntre(DateTimeImmutable $desde, DateTimeImmutable $hasta, int $cuantas): array
    {
        return $this->funciones->programadasEntre($desde, $hasta, $cuantas);
    }

    /**
     * Las finalizadas de un período, para la reportería de recaudación (EF-09).
     *
     * @return list<FuncionFinalizada>
     */
    public function finalizadasEntre(DateTimeImmutable $desde, DateTimeImmutable $hasta, ?string $organizadorId, ?string $duenoId, int $cuantas): array
    {
        return $this->funciones->finalizadasEntre($desde, $hasta, $organizadorId, $duenoId, $cuantas);
    }
}
