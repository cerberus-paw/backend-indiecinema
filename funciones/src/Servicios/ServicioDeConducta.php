<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\Conducta;
use IndieCinema\Funciones\Repositorios\RepositorioDeConducta;
use IndieCinema\Nucleo\Configuracion;

/**
 * Cálculo de puntaje e historial de conducta de los espectadores (EF-13).
 * Arranca en 100 y descuenta por calificaciones negativas vigentes (últimos 6 meses) y ausencias.
 */
final class ServicioDeConducta
{
    private readonly int $puntajeInicial;
    private readonly int $descuentoNegativa;
    private readonly int $descuentoAusencia;
    private readonly int $mesesVigencia;

    public function __construct(
        private readonly RepositorioDeConducta $conductas,
        Configuracion $configuracion,
    ) {
        $this->puntajeInicial = self::entero($configuracion, 'conducta.puntaje_inicial', Conducta::PUNTAJE_INICIAL);
        $this->descuentoNegativa = self::entero($configuracion, 'conducta.descuento_negativa', Conducta::DESCUENTO_NEGATIVA);
        $this->descuentoAusencia = self::entero($configuracion, 'conducta.descuento_ausencia', Conducta::DESCUENTO_AUSENCIA);
        $this->mesesVigencia = self::entero($configuracion, 'conducta.meses_vigencia', Conducta::MESES_VIGENCIA_NEGATIVA);
    }

    public function puntaje(string $usuarioId, DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        $desde = $ahora->modify("-{$this->mesesVigencia} months");
        $negativas = $this->conductas->negativasVigentesDe($usuarioId, $desde);
        $ausencias = $this->conductas->ausenciasDe($usuarioId);
        $puntaje = $this->puntajeInicial - ($negativas * $this->descuentoNegativa) - ($ausencias * $this->descuentoAusencia);

        return max(0, $puntaje);
    }

    private static function entero(Configuracion $configuracion, string $clave, int $porDefecto): int
    {
        $valor = $configuracion->obtener($clave, $porDefecto);

        return is_int($valor) && $valor >= 0 ? $valor : $porDefecto;
    }
}
