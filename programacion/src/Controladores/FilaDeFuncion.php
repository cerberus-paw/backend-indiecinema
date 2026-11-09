<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Programacion\Modelo\FuncionEnCartelera;

/**
 * Lo que muestra parciales/fila_funcion del paquete front: las próximas funciones de la ficha de
 * la sala, una por fila. Es lo de la tarjeta (TarjetaDeFuncion) más el cupo, para escribir los
 * lugares libres como el wireframe: «5 de 80 lugares libres».
 */
final class FilaDeFuncion
{
    /**
     * @param list<FuncionEnCartelera>                 $funciones
     * @param array<string, array<string, mixed>|null> $acuerdos  los de ServicioDeCartelera::acuerdosDe()
     * @param ?array<string, int>                      $ocupados  como en TarjetaDeFuncion::todas()
     *
     * @return list<array<string, mixed>> lo de TarjetaDeFuncion::datos() más cupo
     */
    public static function todas(array $funciones, array $acuerdos, ?array $ocupados = null): array
    {
        return array_map(
            static fn (FuncionEnCartelera $funcion, array $tarjeta): array => $tarjeta + ['cupo' => $funcion->cupo],
            $funciones,
            TarjetaDeFuncion::todas($funciones, $acuerdos, $ocupados),
        );
    }
}
