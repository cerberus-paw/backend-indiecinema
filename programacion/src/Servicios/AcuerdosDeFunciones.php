<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cliente de la API interna del subsistema funciones para la gestión de acuerdos de fecha (E3-13).
 */
class AcuerdosDeFunciones
{
    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * El organizador propone las franjas y el mínimo de asistentes (E3-13.2 / IC-154).
     *
     * @param list<array{fecha_hora_inicio: string, fecha_hora_fin?: string|null}> $franjas
     *
     * @return array<string, mixed>
     *
     * @throws ExcepcionHttp    503 si funciones no responde a tiempo
     * @throws RuntimeException si funciones responde un error
     */
    public function proponer(string $funcionId, int $minimoAsistentes, array $franjas): array
    {
        if (!Uuid::esValido($funcionId)) {
            throw new InvalidArgumentException('El ID de la función no es válido.');
        }

        if ($minimoAsistentes <= 0) {
            throw new InvalidArgumentException('El mínimo de asistentes debe ser mayor a cero.');
        }

        if ($franjas === []) {
            throw new InvalidArgumentException('Debe proponer al menos una franja horaria.');
        }

        $respuesta = $this->cliente->post('funciones', '/interno/acuerdos', [
            'funcion_id' => $funcionId,
            'minimo_asistentes' => $minimoAsistentes,
            'franjas' => $franjas,
        ]);

        if (!is_array($respuesta) || !isset($respuesta['id'])) {
            throw new RuntimeException('funciones no respondió el acuerdo creado a POST /interno/acuerdos.');
        }

        return $respuesta;
    }

    /**
     * Consulta el acuerdo de fecha de una función (E3-13.6 / IC-158).
     *
     * @return array<string, mixed>|null null si no hay acuerdo para esta función
     */
    public function obtenerPorFuncion(string $funcionId, ?string $usuarioId = null): ?array
    {
        if (!Uuid::esValido($funcionId)) {
            return null;
        }

        $parametros = $usuarioId !== null ? ['usuario_id' => $usuarioId] : [];
        $respuesta = $this->cliente->get('funciones', "/interno/acuerdos/por-funcion/{$funcionId}", $parametros);

        return is_array($respuesta) ? $respuesta : null;
    }

    /**
     * Emite o cambia el voto por una franja (E3-13.3 / IC-155).
     *
     * @return array<string, mixed>
     */
    public function votar(string $acuerdoId, string $franjaId, string $usuarioId): array
    {
        $respuesta = $this->cliente->post('funciones', "/interno/acuerdos/{$acuerdoId}/votar", [
            'franja_id' => $franjaId,
            'usuario_id' => $usuarioId,
        ]);

        return is_array($respuesta) ? $respuesta : [];
    }

    /**
     * Confirma la franja ganadora cuando alcanza el mínimo (E3-13.4 / IC-156).
     *
     * @return array<string, mixed>
     */
    public function confirmar(string $acuerdoId): array
    {
        $respuesta = $this->cliente->post('funciones', "/interno/acuerdos/{$acuerdoId}/confirmar");

        if (!is_array($respuesta)) {
            throw new RuntimeException('funciones no respondió al confirmar el acuerdo.');
        }

        return $respuesta;
    }

    /**
     * Cierra el acuerdo sin alcanzar el mínimo (E3-13.5 / IC-157).
     *
     * @return array<string, mixed>
     */
    public function cerrarSinMinimo(string $acuerdoId): array
    {
        $respuesta = $this->cliente->post('funciones', "/interno/acuerdos/{$acuerdoId}/cerrar-sin-minimo");

        if (!is_array($respuesta)) {
            throw new RuntimeException('funciones no respondió al cerrar el acuerdo sin mínimo.');
        }

        return $respuesta;
    }
}
