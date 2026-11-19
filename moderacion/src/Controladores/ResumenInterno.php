<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Repositorios\RepositorioDelResumen;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * GET /interno/resumen?desde=…&hasta=…: la moderación del período para los reportes del
 * administrador (EF-22.4). Las fechas, «AAAA-MM-DD HH:MM:SS» en UTC; hasta no se incluye. Sólo
 * la llama recaudación, con su token: lo controla la ruta.
 *
 * Devuelve {denuncias: [{tipo, objeto_id, estado, cantidad}], suspendidas: [{sala_id, causa,
 * desde}], solicitudes: {estado: cantidad}}. Lo que se denunció va por id: recaudación sabe de qué
 * sala es cada función, y moderación no.
 */
final class ResumenInterno extends Controlador
{
    public function __construct(private readonly RepositorioDelResumen $resumen)
    {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $desde = self::fecha($peticion->consulta('desde'));
        $hasta = self::fecha($peticion->consulta('hasta'));
        if ($desde === null || $hasta === null || $hasta < $desde) {
            return $this->json(['error' => 'desde y hasta son fechas «AAAA-MM-DD HH:MM:SS» en UTC, en ese orden.'], 400);
        }

        return $this->json([
            'denuncias' => $this->resumen->denuncias($desde, $hasta),
            'suspendidas' => $this->resumen->suspendidas(),
            'solicitudes' => (object) $this->resumen->solicitudes($desde, $hasta),
        ]);
    }

    private static function fecha(mixed $texto): ?DateTimeImmutable
    {
        if (!is_string($texto)) {
            return null;
        }
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $texto, new DateTimeZone('UTC'));

        return $fecha === false || $fecha->format('Y-m-d H:i:s') !== $texto ? null : $fecha;
    }
}
