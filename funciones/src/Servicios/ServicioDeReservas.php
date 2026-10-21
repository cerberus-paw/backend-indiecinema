<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use LogicException;

/**
 * Las reservas de los espectadores (E4-01): que nunca se confirmen más lugares que el cupo,
 * aunque dos personas reserven a la vez.
 */
final class ServicioDeReservas
{
    /** Cuántas veces se sortea el código si sale uno repetido, que ya es muy raro una vez. */
    private const INTENTOS_DE_CODIGO = 3;

    public function __construct(
        private readonly FuncionesDeProgramacion $funciones,
        private readonly RepositorioDeReservas $reservas,
    ) {
    }

    /**
     * La función para la página de la reserva.
     *
     * @throws ExcepcionHttp 404 si no existe o su sala no está publicada; 503 si programación no
     *                       responde
     */
    public function funcion(string $funcionId): FuncionDeProgramacion
    {
        $funcion = $this->funciones->buscar($funcionId);
        // Una sala sin publicar no muestra sus funciones: es lo mismo que si no existiera.
        if ($funcion === null || !$funcion->salaHabilitada) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }

    /**
     * Una reserva del espectador, con su función. La de otro es 404, como si no existiera: así no
     * se puede averiguar qué ids existen.
     *
     * @return array{Reserva, ?FuncionDeProgramacion} la función es null si programación ya no la
     *                                                muestra (su sala se dio de baja)
     *
     * @throws ExcepcionHttp 404
     */
    public function delEspectador(Usuario $espectador, string $id): array
    {
        $reserva = Uuid::esValido($id) ? $this->reservas->buscar($id) : null;
        if ($reserva === null || !hash_equals($reserva->usuarioId, $espectador->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return [$reserva, $this->funciones->buscar($reserva->funcionId)];
    }

    /**
     * Reserva $cantidad lugares de la función para el espectador.
     *
     * El cupo se pide a programación antes de bloquear: así la espera por la API interna no
     * frena a las otras reservas de la función.
     *
     * @throws ExcepcionHttp   404 si la función no existe o su sala no está publicada; 503 si
     *                         programación no responde o la función queda bloqueada demasiado
     * @throws ReglaIncumplida si la cantidad no es válida, la función ya no admite reservas o no
     *                         quedan tantos lugares
     */
    public function reservar(
        Usuario $espectador,
        string $funcionId,
        int $cantidad,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): Reserva {
        $funcion = $this->funcion($funcionId);
        $reserva = Reserva::nueva($funcion, $espectador->id, $cantidad, $ahora);

        return $this->reservas->conLaFuncionBloqueada($funcion->id, function () use ($funcion, $reserva): Reserva {
            $libres = max(0, $funcion->cupo - $this->reservas->lugaresOcupados($funcion->id));
            if ($reserva->cantidad > $libres) {
                throw new ReglaIncumplida(match ($libres) {
                    0 => 'La función está agotada.',
                    1 => 'Queda 1 lugar.',
                    default => "Quedan {$libres} lugares.",
                });
            }
            for ($intento = 0; $intento < self::INTENTOS_DE_CODIGO; ++$intento) {
                if ($this->reservas->agregar($reserva)) {
                    return $reserva;
                }
                $reserva = $reserva->conOtroCodigo();
            }
            throw new LogicException('Salió el mismo código de reserva ' . self::INTENTOS_DE_CODIGO . ' veces: revisar el sorteo.');
        });
    }
}
