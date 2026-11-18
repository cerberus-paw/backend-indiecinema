<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Recaudacion\Modelo\Liquidacion;
use IndieCinema\Recaudacion\Modelo\Mes;
use IndieCinema\Recaudacion\Modelo\Monto;
use IndieCinema\Recaudacion\Modelo\ParteLiquidada;
use IndieCinema\Recaudacion\Servicios\ServicioDeLiquidaciones;
use LogicException;

/**
 * «Mis liquidaciones», en /recaudacion/liquidaciones (EF-08.3): lo que le toca al organizador por
 * sus salas y al dueño por sus obras, mes por mes, y si ya se le transfirió.
 *
 * Cada uno ve sólo lo suyo: las liquidaciones se buscan por el id del usuario en sesión, nunca por
 * algo que venga en la URL. Con ?mes=AAAA-MM se abre el detalle de ese mes, función por función;
 * sin él, el del mes más nuevo.
 */
final class Liquidaciones extends Controlador
{
    private const PLANTILLA = 'liquidaciones.html.twig';

    public function __construct(private readonly ServicioDeLiquidaciones $liquidaciones)
    {
    }

    /** GET /recaudacion/liquidaciones */
    public function mias(Peticion $peticion): Respuesta
    {
        $usuario = self::usuario($peticion);
        $liquidaciones = $this->liquidaciones->delBeneficiario($usuario->id);
        $elegido = self::mesElegido($peticion, $liquidaciones);

        return $this->vista(self::PLANTILLA, [
            'meses' => array_map(
                fn (Liquidacion $liquidacion): array => self::comoFila($liquidacion, $elegido),
                $liquidaciones,
            ),
            'total' => Monto::comoPesos(array_sum(array_map(
                static fn (Liquidacion $liquidacion): int => $liquidacion->centavos,
                $liquidaciones,
            ))),
            'pendiente' => Monto::comoPesos(array_sum(array_map(
                static fn (Liquidacion $liquidacion): int => $liquidacion->estaTransferida() ? 0 : $liquidacion->centavos,
                $liquidaciones,
            ))),
            'mes' => $elegido?->nombre(),
            'detalle' => $elegido === null ? [] : array_map(
                self::comoLinea(...),
                $this->liquidaciones->detalle($usuario->id, $elegido),
            ),
        ]);
    }

    /**
     * El mes del detalle: el de ?mes=, si el usuario tiene una liquidación de ese mes, o el más
     * nuevo que tenga. Null si no tiene ninguna, y entonces no hay detalle que mostrar.
     *
     * @param list<Liquidacion> $liquidaciones
     */
    private static function mesElegido(Peticion $peticion, array $liquidaciones): ?Mes
    {
        $pedido = $peticion->consulta('mes');
        $mes = is_string($pedido) ? Mes::desdeTexto($pedido) : null;
        foreach ($liquidaciones as $liquidacion) {
            if ($mes !== null && $liquidacion->periodo->comoTexto() === $mes->comoTexto()) {
                return $liquidacion->periodo;
            }
        }

        // Vienen del mes más nuevo al más viejo.
        return $liquidaciones === [] ? null : $liquidaciones[0]->periodo;
    }

    /**
     * @return array<string, mixed>
     */
    private static function comoFila(Liquidacion $liquidacion, ?Mes $elegido): array
    {
        $mes = $liquidacion->periodo->comoTexto();

        return [
            'mes' => $mes,
            'nombre' => $liquidacion->periodo->nombre(),
            'monto' => Monto::comoPesos($liquidacion->centavos),
            'transferida' => $liquidacion->estaTransferida(),
            'estado' => $liquidacion->estado->comoTexto(),
            'transferida_en' => $liquidacion->transferidaEn === null ? null : self::fecha($liquidacion->transferidaEn),
            'nota' => $liquidacion->nota,
            'url' => "/recaudacion/liquidaciones?mes={$mes}",
            'actual' => $elegido !== null && $elegido->comoTexto() === $mes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function comoLinea(ParteLiquidada $parte): array
    {
        return [
            'fecha' => $parte->fechaHora === null ? null : self::fecha($parte->fechaHora),
            'sala' => $parte->salaNombre,
            'titulos' => $parte->titulos,
            'cobrado' => Monto::comoPesos($parte->cobradoCentavos),
            'rol' => $parte->rol->comoTexto(),
            'monto' => Monto::comoPesos($parte->centavos),
        ];
    }

    /** «16 de noviembre de 2026». */
    private static function fecha(DateTimeImmutable $momento): string
    {
        return "{$momento->format('j')} de " . Mes::de($momento)->nombre();
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
