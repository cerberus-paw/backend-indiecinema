<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\Calificacion;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Repositorios\RepositorioDeCalificaciones;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;

/**
 * Las calificaciones de los espectadores (EF-04.2): quién puede calificar y con qué reserva.
 * Va en funciones porque es el que sabe quién asistió.
 */
final class ServicioDeCalificaciones
{
    public function __construct(
        private readonly ServicioDeReservas $reservas,
        private readonly RepositorioDeCalificaciones $calificaciones,
    ) {
    }

    /**
     * La reserva con su función, si el espectador todavía la puede calificar.
     *
     * @return array{Reserva, FuncionDeProgramacion}
     *
     * @throws ExcepcionHttp 404 si la reserva no es suya; 403 con el motivo si no puede calificar
     *                       o ya calificó la función; 503 si programación no responde
     */
    public function paraCalificar(Usuario $espectador, string $reservaId, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        [$reserva, $funcion] = $this->reservas->delEspectador($espectador, $reservaId);
        $motivo = Calificacion::motivoParaNoCalificar($reserva, $funcion, $ahora);
        if ($motivo !== null) {
            throw new ExcepcionHttp(403, $motivo);
        }
        if ($this->calificaciones->delUsuarioEn($espectador->id, [$reserva->funcionId]) !== []) {
            throw new ExcepcionHttp(403, 'Ya calificaste esta función.');
        }
        assert($funcion !== null);

        return [$reserva, $funcion];
    }

    /**
     * @throws ExcepcionHttp   como paraCalificar()
     * @throws ReglaIncumplida si el puntaje o el comentario no son válidos, o si calificó la misma
     *                         función mientras tanto (desde otra pestaña, o con otra reserva)
     */
    public function calificar(Usuario $espectador, string $reservaId, int $puntaje, ?string $comentario, DateTimeImmutable $ahora = new DateTimeImmutable()): Calificacion
    {
        [$reserva, $funcion] = $this->paraCalificar($espectador, $reservaId, $ahora);
        $calificacion = Calificacion::nueva($reserva, $funcion, $puntaje, $comentario, $ahora);
        if (!$this->calificaciones->agregar($calificacion)) {
            throw new ReglaIncumplida('Ya calificaste esta función.');
        }

        return $calificacion;
    }

    /**
     * Las calificaciones del espectador en las funciones de esas reservas, para «Mis reservas».
     *
     * @param list<Reserva> $reservas
     *
     * @return array<string, Calificacion> con el id de la función como clave
     */
    public function deLasReservas(Usuario $espectador, array $reservas): array
    {
        $funciones = array_values(array_unique(array_map(static fn (Reserva $reserva): string => $reserva->funcionId, $reservas)));

        return $this->calificaciones->delUsuarioEn($espectador->id, $funciones);
    }
}
