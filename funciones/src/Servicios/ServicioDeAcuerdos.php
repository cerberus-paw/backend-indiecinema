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

        $acuerdoId = Uuid::nuevo();
        $franjas = [];

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
     * Vota o cambia el voto por una franja horaria (E3-13.3 / IC-155).
     *
     * @throws ReglaIncumplida si el acuerdo no está abierto o la franja no existe
     */
    public function votarFranja(string $acuerdoId, string $franjaId, string $usuarioId): void
    {
        $acuerdo = $this->acuerdoPorId($acuerdoId);
        if ($acuerdo === null) {
            throw new ReglaIncumplida('El acuerdo de fecha no existe.');
        }

        if (!$acuerdo->estaAbierto()) {
            throw new ReglaIncumplida('El acuerdo de fecha ya no se encuentra abierto para votación.');
        }

        $existeFranja = false;
        foreach ($acuerdo->franjas as $f) {
            if ($f->id === $franjaId) {
                $existeFranja = true;
                break;
            }
        }

        if (!$existeFranja) {
            throw new ReglaIncumplida('La franja seleccionada no pertenece a este acuerdo.');
        }

        $this->repositorio->votarOActualizar($acuerdoId, $acuerdo->funcionId, $franjaId, $usuarioId);
    }

    /**
     * Devuelve la franja votada por el usuario en este acuerdo (si votó).
     */
    public function franjaVotadaPorUsuario(string $acuerdoId, string $usuarioId): ?string
    {
        return $this->repositorio->votoDeUsuario($acuerdoId, $usuarioId);
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
}
