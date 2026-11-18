<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\AutorDelCambio;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Nucleo\Seguridad\Rol;

/**
 * Cancela las reservas que vencieron sin pagar (X-01.5), para que «Mis reservas», el panel del
 * organizador y el del administrador las muestren como lo que son. La corre el cron del servidor
 * con bin/liberar-vencidas.php.
 *
 * El lugar ya quedó libre en el momento en que venció la reserva (RepositorioDeReservas::
 * lugaresOcupados() no cuenta las vencidas): esto ordena el estado, no el cupo. Si el pago llega
 * justo antes, gana el que guarda primero (guardarEstado() sólo cambia una pendiente).
 */
final class LiberacionDeVencidas
{
    /** De a cuántas: una tanda no traba la tabla, y se sigue hasta que no quede ninguna. */
    private const POR_TANDA = 100;

    public const MOTIVO = 'Venció el plazo para pagar.';

    public function __construct(private readonly RepositorioDeReservas $reservas)
    {
    }

    /**
     * @return int cuántas canceló
     */
    public function liberar(DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        $canceladas = 0;
        do {
            $vencidas = $this->reservas->vencidas($ahora, self::POR_TANDA);
            foreach ($vencidas as $reserva) {
                // El espectador no pagó a tiempo: es su reserva la que vence.
                $autor = new AutorDelCambio($reserva->usuarioId, Rol::Espectador, self::MOTIVO);
                if ($this->reservas->guardarEstado($reserva->cancelar(), $reserva->estado, $autor, $ahora)) {
                    ++$canceladas;
                }
            }
            // Una que no se pudo cancelar (se pagó mientras tanto) ya no está pendiente: la tanda
            // siguiente no la vuelve a traer.
        } while (count($vencidas) === self::POR_TANDA);

        return $canceladas;
    }
}
