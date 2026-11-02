<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\AcuerdoFecha;
use IndieCinema\Funciones\Modelo\EstadoAcuerdo;
use IndieCinema\Funciones\Modelo\FranjaHoraria;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Repositorios\RepositorioDeAcuerdos;
use IndieCinema\Nucleo\Uuid;
use InvalidArgumentException;

/**
 * Lógica del acuerdo de fecha con los espectadores (E3-13).
 */
class ServicioDeAcuerdos
{
    public function __construct(
        private readonly RepositorioDeAcuerdos $repositorio,
        private readonly FuncionesDeProgramacion $funciones,
    ) {
    }

    /**
     * El organizador propone las franjas horarias y el mínimo de asistentes (E3-13.2 / IC-154).
     *
     * @param list<array{fecha_hora_inicio: string, fecha_hora_fin?: string|null}> $franjasDatos
     *
     * @throws InvalidArgumentException si los datos no cumplen las validaciones básicas
     */
    public function proponerAcuerdo(string $funcionId, int $minimoAsistentes, array $franjasDatos): AcuerdoFecha
    {
        if (!Uuid::esValido($funcionId)) {
            throw new InvalidArgumentException('El ID de la función no es válido.');
        }

        if ($minimoAsistentes <= 0) {
            throw new InvalidArgumentException('El mínimo de asistentes debe ser mayor a cero.');
        }

        if ($franjasDatos === []) {
            throw new InvalidArgumentException('Debe proponer al menos una franja horaria.');
        }

        // Uno abierto por función: programación ya lo controla, pero la regla es de acá (E3-13.F2).
        if ($this->repositorio->buscarPorFuncion($funcionId)?->estaAbierto()) {
            throw new InvalidArgumentException('La función ya tiene un acuerdo de fecha abierto.');
        }

        $acuerdoId = Uuid::nuevo();
        $franjas = [];
        $ahora = new DateTimeImmutable();

        foreach ($franjasDatos as $dato) {
            $inicioStr = $dato['fecha_hora_inicio'] ?? '';
            if ($inicioStr === '') {
                throw new InvalidArgumentException('Cada franja debe incluir una fecha y hora de inicio.');
            }

            $inicio = new DateTimeImmutable($inicioStr);
            $fin = !empty($dato['fecha_hora_fin']) ? new DateTimeImmutable((string) $dato['fecha_hora_fin']) : null;

            if ($fin !== null && $fin <= $inicio) {
                throw new InvalidArgumentException('La hora de finalización debe ser posterior a la de inicio.');
            }
            if ($inicio <= $ahora) {
                throw new InvalidArgumentException('Cada franja tiene que empezar en el futuro.');
            }

            $franjas[] = new FranjaHoraria(
                Uuid::nuevo(),
                $acuerdoId,
                $inicio,
                $fin,
                new DateTimeImmutable(),
                0,
            );
        }

        $acuerdo = new AcuerdoFecha(
            $acuerdoId,
            $funcionId,
            $minimoAsistentes,
            EstadoAcuerdo::Abierto,
            null,
            new DateTimeImmutable(),
            null,
            $franjas,
        );

        $this->repositorio->guardar($acuerdo);

        return $acuerdo;
    }

    /**
     * Consulta el acuerdo de fecha de una función con sus franjas y votos.
     */
    public function acuerdoPorFuncion(string $funcionId): ?AcuerdoFecha
    {
        if (!Uuid::esValido($funcionId)) {
            return null;
        }

        return $this->repositorio->buscarPorFuncion($funcionId);
    }

    /**
     * Consulta un acuerdo por su ID.
     */
    public function acuerdoPorId(string $id): ?AcuerdoFecha
    {
        if (!Uuid::esValido($id)) {
            return null;
        }

        return $this->repositorio->buscarPorId($id);
    }

    /**
     * Marca «Me sirve» en una franja (E3-13.3 / IC-155). Se pueden marcar varias del mismo
     * acuerdo, como dice el wireframe: cada franja cuenta a los que la marcaron (E3-13.F7).
     *
     * @throws ReglaIncumplida si el acuerdo no está abierto o la franja no es de este acuerdo
     */
    public function marcarFranja(string $acuerdoId, string $franjaId, string $usuarioId): void
    {
        $acuerdo = $this->acuerdoParaVotar($acuerdoId, $franjaId);
        $this->repositorio->marcar($acuerdoId, $acuerdo->funcionId, $franjaId, $usuarioId);
    }

    /**
     * Quita el «Me sirve» de una franja, mientras el acuerdo esté abierto (E3-13.F7).
     *
     * @throws ReglaIncumplida si el acuerdo no está abierto o la franja no es de este acuerdo
     */
    public function desmarcarFranja(string $acuerdoId, string $franjaId, string $usuarioId): void
    {
        $this->acuerdoParaVotar($acuerdoId, $franjaId);
        $this->repositorio->desmarcar($franjaId, $usuarioId);
    }

    /**
     * Las franjas del acuerdo que marcó el usuario.
     *
     * @return list<string>
     */
    public function franjasMarcadasPor(string $acuerdoId, string $usuarioId): array
    {
        return $this->repositorio->franjasDeUsuario($acuerdoId, $usuarioId);
    }

    /**
     * Confirma la franja ganadora si alcanza el mínimo de asistentes (E3-13.4 / IC-156).
     *
     * @throws ReglaIncumplida si no está abierto o no alcanza el mínimo
     */
    public function confirmarFranjaGanadora(string $acuerdoId): AcuerdoFecha
    {
        $acuerdo = $this->acuerdoPorId($acuerdoId);
        if ($acuerdo === null) {
            throw new ReglaIncumplida('El acuerdo de fecha no existe.');
        }

        if (!$acuerdo->estaAbierto()) {
            throw new ReglaIncumplida('El acuerdo ya fue cerrado previamente.');
        }

        $ganadora = $acuerdo->franjaMasVotada();
        if ($ganadora === null || $ganadora->votos < $acuerdo->minimoAsistentes) {
            throw new ReglaIncumplida("La franja con más votos no alcanza el mínimo requerido de {$acuerdo->minimoAsistentes} asistentes.");
        }
        // Programación no puede fijar una fecha pasada: si se confirmara igual, el acuerdo quedaría
        // cerrado y la función sin programar (E3-13.F4).
        if ($ganadora->inicio <= new DateTimeImmutable()) {
            throw new ReglaIncumplida('La franja ganadora ya empezó.');
        }

        $this->repositorio->actualizarEstado($acuerdoId, EstadoAcuerdo::Confirmado, $ganadora->id);

        return $this->acuerdoPorId($acuerdoId) ?? $acuerdo;
    }

    /**
     * Cierra el acuerdo cuando no alcanza el mínimo de asistentes (E3-13.5 / IC-157).
     *
     * @throws ReglaIncumplida si no está abierto
     */
    public function cerrarSinMinimo(string $acuerdoId): AcuerdoFecha
    {
        $acuerdo = $this->acuerdoPorId($acuerdoId);
        if ($acuerdo === null) {
            throw new ReglaIncumplida('El acuerdo de fecha no existe.');
        }

        if (!$acuerdo->estaAbierto()) {
            throw new ReglaIncumplida('El acuerdo ya fue cerrado previamente.');
        }

        $this->repositorio->actualizarEstado($acuerdoId, EstadoAcuerdo::SinMinimo, null);

        return $this->acuerdoPorId($acuerdoId) ?? $acuerdo;
    }

    /**
     * El acuerdo, si está abierto y la franja es suya: lo que hace falta para marcarla o desmarcarla.
     *
     * @throws ReglaIncumplida si no
     */
    private function acuerdoParaVotar(string $acuerdoId, string $franjaId): AcuerdoFecha
    {
        $acuerdo = $this->acuerdoPorId($acuerdoId);
        if ($acuerdo === null) {
            throw new ReglaIncumplida('El acuerdo de fecha no existe.');
        }

        if (!$acuerdo->estaAbierto()) {
            throw new ReglaIncumplida('El acuerdo de fecha ya no se encuentra abierto para votación.');
        }

        foreach ($acuerdo->franjas as $franja) {
            if ($franja->id === $franjaId) {
                return $acuerdo;
            }
        }

        throw new ReglaIncumplida('La franja seleccionada no pertenece a este acuerdo.');
    }
}
