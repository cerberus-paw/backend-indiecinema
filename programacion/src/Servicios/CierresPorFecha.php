<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;

/**
 * Los cierres que pasan solos al llegar la fecha límite (EF-03): la votación de títulos que pasó
 * su fecha de cierre pasa al acuerdo de fecha con la obra más votada, igual que si la cerrara el
 * organizador. Lo corre el cron del servidor con bin/cerrar-por-fecha.php.
 *
 * El cierre a mano sigue andando: la tarea sólo toma las que pasaron su fecha y siguen abiertas,
 * así que correrla de más, o después de que el organizador cerró, no hace nada.
 */
final class CierresPorFecha
{
    /** De a cuántas, como FinalizacionDeFunciones: una tanda no traba la tabla. */
    private const POR_TANDA = 100;

    public function __construct(
        private readonly RepositorioDeFunciones $funciones,
        private readonly RepositorioDeVotos $votos,
    ) {
    }

    /**
     * @return int cuántas votaciones cerró
     */
    public function cerrarVotaciones(DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        $cerradas = 0;
        do {
            $vencidas = $this->funciones->conVotacionVencida($ahora, self::POR_TANDA);
            foreach ($vencidas as $id) {
                $funcion = $this->funciones->buscar($id);
                // El organizador la pudo cerrar o cancelar entre la consulta y acá.
                if ($funcion === null || $funcion->estado !== EstadoFuncion::EnVotacionTitulos) {
                    continue;
                }
                $this->cerrarVotacion($funcion);
                ++$cerradas;
            }
        // Cada vencida sale de la consulta al cerrarla, así que la próxima tanda trae otras.
        } while (count($vencidas) === self::POR_TANDA);

        return $cerradas;
    }

    /**
     * Termina la votación con la obra más votada y la guarda en acuerdo de fecha. Es el mismo
     * cierre para el organizador (ServicioDeFuncionesDelOrganizador::cerrarVotacion()) y para la
     * tarea: así los dos eligen igual, también al desempatar.
     */
    public function cerrarVotacion(Funcion $funcion): void
    {
        $obraGanadora = $this->votos->obraMasVotada($funcion->id, $funcion->obrasIds);
        $funcion->cerrarVotacionTitulos([$obraGanadora]);
        $this->funciones->guardar($funcion);
    }
}
