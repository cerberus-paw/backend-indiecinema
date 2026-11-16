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
    private readonly int $umbral;
    private readonly int $topeBajoUmbral;

    public function __construct(
        private readonly RepositorioDeConducta $conductas,
        Configuracion $configuracion,
    ) {
        $this->puntajeInicial = self::entero($configuracion, 'conducta.puntaje_inicial', Conducta::PUNTAJE_INICIAL);
        $this->descuentoNegativa = self::entero($configuracion, 'conducta.descuento_negativa', Conducta::DESCUENTO_NEGATIVA);
        $this->descuentoAusencia = self::entero($configuracion, 'conducta.descuento_ausencia', Conducta::DESCUENTO_AUSENCIA);
        $this->mesesVigencia = self::entero($configuracion, 'conducta.meses_vigencia', Conducta::MESES_VIGENCIA_NEGATIVA);
        $this->umbral = self::entero($configuracion, 'conducta.umbral', Conducta::UMBRAL);
        $tope = self::entero($configuracion, 'conducta.tope_bajo_umbral', Conducta::TOPE_BAJO_UMBRAL);
        $this->topeBajoUmbral = $tope >= 1 ? $tope : Conducta::TOPE_BAJO_UMBRAL;
    }

    public function puntaje(string $usuarioId, DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        $desde = $ahora->modify("-{$this->mesesVigencia} months");
        $negativas = $this->conductas->negativasVigentesDe($usuarioId, $desde);
        $ausencias = $this->conductas->ausenciasDe($usuarioId);
        $puntaje = $this->puntajeInicial - ($negativas * $this->descuentoNegativa) - ($ausencias * $this->descuentoAusencia);

        return max(0, $puntaje);
    }

    public function umbral(): int
    {
        return $this->umbral;
    }

    public function bajoElUmbral(string $usuarioId, DateTimeImmutable $ahora = new DateTimeImmutable()): bool
    {
        return $this->puntaje($usuarioId, $ahora) <= $this->umbral;
    }

    /**
     * Tope de reservas simultáneas para el usuario si está en el umbral o por debajo (EF-13.2);
     * null cuando su historial no tiene restricciones.
     */
    public function topeSimultaneoPara(string $usuarioId, DateTimeImmutable $ahora = new DateTimeImmutable()): ?int
    {
        return $this->bajoElUmbral($usuarioId, $ahora) ? $this->topeBajoUmbral : null;
    }

    private static function entero(Configuracion $configuracion, string $clave, int $porDefecto): int
    {
        $valor = $configuracion->obtener($clave, $porDefecto);

        return is_int($valor) && $valor >= 0 ? $valor : $porDefecto;
    }
}
