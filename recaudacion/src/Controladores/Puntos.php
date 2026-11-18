<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Recaudacion\Modelo\Cupon;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeCupones;
use IndieCinema\Recaudacion\Servicios\PuntosDelEspectador;
use LogicException;

/**
 * Los puntos del espectador y sus cupones (EF-17.1 / IC-596).
 *
 * - GET /recaudacion/puntos: «Mis puntos», en Mi cuenta: el saldo, los movimientos y los cupones.
 * - POST /recaudacion/puntos/canjear: un cupón por 100 puntos.
 * - GET /interno/cupones/{codigo}: para funciones, que lo aplica al reservar.
 */
final class Puntos extends Controlador
{
    private const ZONA = 'America/Argentina/Buenos_Aires';

    public function __construct(
        private readonly PuntosDelEspectador $puntos,
        private readonly RepositorioDeCupones $cupones,
    ) {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $usuario = $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');

        return $this->pantalla($usuario->id, canjeado: $peticion->consulta('canjeado'));
    }

    public function canjear(Peticion $peticion): Respuesta
    {
        $usuario = $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');
        try {
            $cupon = $this->puntos->canjear($usuario->id);
        } catch (ReglaIncumplida $regla) {
            return $this->pantalla($usuario->id, error: $regla->getMessage(), estado: 422);
        }

        return $this->redirigir('/puntos?canjeado=' . rawurlencode($cupon->codigo));
    }

    /**
     * GET /interno/cupones/{codigo}: de quién es, cuánto descuenta y hasta cuándo vale. Si se usó
     * lo sabe funciones, que es quien pregunta.
     */
    public function interno(Peticion $peticion): Respuesta
    {
        $codigo = (string) $peticion->parametro('codigo');
        $cupon = preg_match('/^[A-Z0-9]{8}$/', $codigo) === 1 ? $this->cupones->buscar($codigo) : null;
        if ($cupon === null) {
            return $this->json(['error' => 'No existe ese cupón.'], 404);
        }

        return $this->json([
            'codigo' => $cupon->codigo,
            'usuario_id' => $cupon->usuarioId,
            'porcentaje' => $cupon->porcentaje,
            'vence_en' => $cupon->venceEn->format(DATE_ATOM),
        ]);
    }

    private function pantalla(string $usuarioId, ?string $error = null, mixed $canjeado = null, int $estado = 200): Respuesta
    {
        $resumen = $this->puntos->resumen($usuarioId);
        $fecha = static fn (DateTimeImmutable $momento): string => $momento->setTimezone(new DateTimeZone(self::ZONA))->format('d/m/Y');

        return $this->vista('puntos.html.twig', [
            'saldo' => $resumen['saldo'],
            'por_asistencia' => Cupon::PUNTOS_POR_ASISTENCIA,
            'por_cupon' => Cupon::PUNTOS_POR_CUPON,
            'porcentaje' => Cupon::PORCENTAJE,
            'dias' => Cupon::DIAS_DE_VIGENCIA,
            'faltan' => max(0, Cupon::PUNTOS_POR_CUPON - $resumen['saldo']),
            'movimientos' => array_map(static fn (array $movimiento): array => [
                'fecha' => $fecha($movimiento['fecha']),
                'puntos' => $movimiento['puntos'],
                'motivo' => $movimiento['motivo'],
                'detalle' => $movimiento['detalle'],
            ], $resumen['movimientos']),
            'cupones' => array_map(static fn (array $item): array => [
                'codigo' => $item['cupon']->codigo,
                'porcentaje' => $item['cupon']->porcentaje,
                'vence' => $fecha($item['cupon']->venceEn),
                'estado' => $item['estado'],
            ], $resumen['cupones']),
            'canjeado' => is_string($canjeado) && preg_match('/^[A-Z0-9]{8}$/', $canjeado) === 1 ? $canjeado : null,
            'error' => $error,
        ], $estado);
    }
}
