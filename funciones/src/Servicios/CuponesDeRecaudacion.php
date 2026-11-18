<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use Psr\Log\LoggerInterface;

/**
 * Los cupones de descuento, que da recaudación al canjear puntos (EF-17.1). Funciones sólo los
 * consulta al reservar: de quién es, cuánto descuenta y hasta cuándo vale. Que no se use dos veces
 * lo controla funciones, que es quien guarda las reservas.
 */
class CuponesDeRecaudacion
{
    private ?ClienteInterno $cliente = null;

    public function __construct(
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return ?array{usuario_id: string, porcentaje: int, vence_en: DateTimeImmutable} null si no existe
     *
     * @throws ExcepcionHttp 503 si recaudación no está configurado o no responde
     */
    public function buscar(string $codigo): ?array
    {
        if ($this->configuracion->obtener('subsistemas.recaudacion') === null || $this->configuracion->obtener('interno.token') === null) {
            throw ExcepcionHttp::servicioNoDisponible();
        }
        $this->cliente ??= ClienteInterno::desdeConfiguracion($this->configuracion, $this->log);

        // null si no existe (404); si recaudación no responde, el cliente ya lanza el 503.
        $datos = $this->cliente->get('recaudacion', '/interno/cupones/' . rawurlencode($codigo));
        if ($datos === null) {
            return null;
        }
        if (!is_array($datos) || !is_string($datos['usuario_id'] ?? null) || !is_int($datos['porcentaje'] ?? null) || !is_string($datos['vence_en'] ?? null)) {
            $this->log->error('recaudacion devolvió un formato inesperado en GET /interno/cupones/{codigo}.');

            throw ExcepcionHttp::servicioNoDisponible();
        }

        return [
            'usuario_id' => $datos['usuario_id'],
            'porcentaje' => $datos['porcentaje'],
            'vence_en' => new DateTimeImmutable($datos['vence_en']),
        ];
    }
}
