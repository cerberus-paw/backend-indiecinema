<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;

/**
 * El nombre y la localidad de las salas que sigue un espectador, desde programación por su API
 * interna (EF-15.4).
 *
 * Es para la lista de «Mi cuenta»: si programación no está configurado o no responde, devuelve
 * null y la página lo avisa sin romperse.
 */
final class SalasDeProgramacion
{
    private const MAXIMO_POR_PEDIDO = 100;

    private ?ClienteInterno $cliente = null;

    public function __construct(
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return ?array<string, array{nombre: string, localidad: string}> las que existen, por id; null si programación no responde
     */
    public function deVarias(array $ids): ?array
    {
        $ids = array_slice(array_values(array_unique(array_filter($ids, Uuid::esValido(...)))), 0, self::MAXIMO_POR_PEDIDO);
        if ($ids === []) {
            return [];
        }
        if ($this->configuracion->obtener('subsistemas.programacion') === null || $this->configuracion->obtener('interno.token') === null) {
            return null;
        }
        $this->cliente ??= ClienteInterno::desdeConfiguracion($this->configuracion, $this->log);

        try {
            $datos = $this->cliente->get('programacion', '/interno/salas', ['ids' => implode(',', $ids)]);
        } catch (ExcepcionHttp) {
            // ClienteInterno ya dejó en el log que programación no respondió.
            return null;
        }
        if (!is_array($datos)) {
            $this->log->error('programacion devolvió un formato inesperado en GET /interno/salas.');

            return null;
        }

        $salas = [];
        foreach ($datos as $id => $sala) {
            if (is_string($id) && is_array($sala) && is_string($sala['nombre'] ?? null)) {
                $salas[$id] = ['nombre' => $sala['nombre'], 'localidad' => is_string($sala['localidad'] ?? null) ? $sala['localidad'] : ''];
            }
        }

        return $salas;
    }
}
