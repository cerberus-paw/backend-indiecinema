<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * La moderación y la conducta del período para el administrador (EF-22.4). Lo que un subsistema
 * no respondió viene vacío y con un aviso.
 */
final readonly class ModeracionDelPeriodo
{
    /**
     * @param list<ModeracionDeSala>                                   $salas            de la que más denuncias tiene a la que menos
     * @param array<string, int>                                       $solicitudes      solicitudes de sala por estado
     * @param array{abierta: int, desestimada: int, resuelta: int}     $otrasDenuncias   las de obras, calificaciones y salas o funciones fuera del período
     * @param int                                                      $otrasSuspendidas salas suspendidas que no tuvieron funciones en el período
     * @param list<string>                                             $avisos
     */
    public function __construct(
        public array $salas,
        public array $solicitudes,
        public array $otrasDenuncias,
        public int $otrasSuspendidas,
        public array $avisos = [],
    ) {
    }
}
