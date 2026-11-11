<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;

/**
 * Pasa a finalizada cada función programada que ya terminó (EF-04.1). Hasta acá ninguna llegaba
 * sola a ese estado: una función cuya hora pasó se trataba como «ya empezó» comparando la fecha.
 * Finalizada, la función queda en el historial de la sala y sus espectadores la pueden calificar.
 * La corre el cron del servidor con bin/finalizar-funciones.php.
 *
 * Primero le avisa a funciones, que deja ausentes a los que no fueron, y recién después la guarda
 * finalizada: si funciones no responde, la función sigue programada y la próxima pasada lo vuelve
 * a intentar. Al revés, una finalizada cuyo aviso falló no tendría cómo encontrarse otra vez.
 */
final class FinalizacionDeFunciones
{
    /** De a cuántas: una tanda no traba la tabla, y se sigue hasta que no quede ninguna. */
    private const POR_TANDA = 100;

    public function __construct(
        private readonly RepositorioDeFunciones $funciones,
        private readonly ReservasDeFunciones $reservas,
    ) {
    }

    /**
     * @return int cuántas finalizó
     */
    public function finalizar(DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        $finalizadas = 0;
        do {
            $terminadas = $this->funciones->terminadas($ahora, self::POR_TANDA);
            $fallo = false;
            foreach ($terminadas as $terminada) {
                if (!$this->reservas->avisarFinalizada($terminada['id'], $terminada['organizador_id'])) {
                    // Funciones no responde: las demás van a fallar igual. Quedan para la próxima.
                    $fallo = true;
                    break;
                }
                $funcion = $this->funciones->buscar($terminada['id']);
                // Si el organizador la canceló mientras tanto, ya no está programada.
                if ($funcion === null || $funcion->estado !== EstadoFuncion::Programada) {
                    continue;
                }
                $funcion->finalizar($ahora);
                $this->funciones->guardar($funcion);
                ++$finalizadas;
            }
        } while (!$fallo && count($terminadas) === self::POR_TANDA);

        return $finalizadas;
    }
}
