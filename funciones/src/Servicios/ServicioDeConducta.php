<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\Conducta;
use IndieCinema\Funciones\Repositorios\RepositorioDeConducta;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;

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

    /**
     * Puntaje de conducta y resumen de actividad para «Mis reservas» (EF-13.3).
     *
     * @return array{
     *     puntaje: int,
     *     puntaje_inicial: int,
     *     umbral: int,
     *     bajo_umbral: bool,
     *     tope_bajo_umbral: int,
     *     asistencias: int,
     *     ausencias: int,
     *     conducta_positivas: int,
     *     conducta_negativas: int,
     *     negativas_vigentes: int,
     *     calificaciones_funciones: int,
     * }
     */
    public function actividadYConducta(string $usuarioId, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        $desde = $ahora->modify("-{$this->mesesVigencia} months");
        $resumen = $this->conductas->resumenDeActividad($usuarioId, $desde);
        $puntaje = $this->calcularPuntaje($resumen['negativas_vigentes'], $resumen['ausencias']);

        return [
            'puntaje' => $puntaje,
            'puntaje_inicial' => $this->puntajeInicial,
            'umbral' => $this->umbral,
            'bajo_umbral' => $puntaje <= $this->umbral,
            'tope_bajo_umbral' => $this->topeBajoUmbral,
            ...$resumen,
        ];
    }

    /**
     * Conducta en lote por usuario, para «Usuarios y roles» del administrador en cuentas (EF-13.3).
     *
     * @param list<string> $usuariosIds
     *
     * @return array<string, array{puntaje: int, bajo_umbral: bool, negativas: int, ausencias: int}>
     */
    public function resumenDeVarios(array $usuariosIds, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        $validos = array_values(array_unique(array_filter($usuariosIds, Uuid::esValido(...))));
        if ($validos === []) {
            return [];
        }

        $desde = $ahora->modify("-{$this->mesesVigencia} months");
        $negativas = $this->conductas->negativasVigentesDeVarios($validos, $desde);
        $ausencias = $this->conductas->ausenciasDeVarios($validos);

        $porUsuario = [];
        foreach ($validos as $id) {
            $neg = $negativas[$id] ?? 0;
            $aus = $ausencias[$id] ?? 0;
            $puntaje = $this->calcularPuntaje($neg, $aus);
            $porUsuario[$id] = [
                'puntaje' => $puntaje,
                'bajo_umbral' => $puntaje <= $this->umbral,
                'negativas' => $neg,
                'ausencias' => $aus,
            ];
        }

        return $porUsuario;
    }

    private function calcularPuntaje(int $negativasVigentes, int $ausencias): int
    {
        $puntaje = $this->puntajeInicial - ($negativasVigentes * $this->descuentoNegativa) - ($ausencias * $this->descuentoAusencia);

        return max(0, $puntaje);
    }

    private static function entero(Configuracion $configuracion, string $clave, int $porDefecto): int
    {
        $valor = $configuracion->obtener($clave, $porDefecto);

        return is_int($valor) && $valor >= 0 ? $valor : $porDefecto;
    }
}
