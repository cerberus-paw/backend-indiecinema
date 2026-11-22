<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDenuncias;
use IndieCinema\Nucleo\Configuracion;

/**
 * Las reglas de las denuncias (EF-22.11): por sala, cuántos denunciantes distintos tienen una
 * denuncia media resuelta (con cualquier acción, también tomada), de la sala o de sus funciones,
 * creada en los últimos `dias`. Con `aviso_cantidad`, se le avisa al organizador; con
 * `cartelera_cantidad`, la sala baja al final de la cartelera mientras siga ahí. Las bajas no
 * cuentan, y las altas van por «Para suspender». Las cantidades y el período, en [denuncias] de
 * config.ini.
 */
final class ReglasDeDenuncias
{
    public function __construct(
        private readonly RepositorioDeDenuncias $denuncias,
        private readonly Configuracion $configuracion,
    ) {
    }

    public function avisoCantidad(): int
    {
        return (int) $this->configuracion->requerir('denuncias.aviso_cantidad');
    }

    public function carteleraCantidad(): int
    {
        return (int) $this->configuracion->requerir('denuncias.cartelera_cantidad');
    }

    public function dias(): int
    {
        return (int) $this->configuracion->requerir('denuncias.dias');
    }

    public function conteo(string $salaId): int
    {
        return $this->conteos([$salaId])[$salaId] ?? 0;
    }

    /**
     * @param list<string> $salaIds
     *
     * @return array<string, int> sala => conteo, sólo las que tienen alguna
     */
    public function conteos(array $salaIds): array
    {
        return $this->denuncias->mediasResueltasPorSala($salaIds, $this->desde());
    }

    /**
     * Las salas que bajan en la cartelera hoy. El castigo no se guarda: se levanta solo cuando las
     * denuncias salen del período.
     *
     * @return array<string, int> sala => conteo
     */
    public function castigadas(): array
    {
        return $this->denuncias->salasConMediasResueltas($this->carteleraCantidad(), $this->desde());
    }

    /**
     * Desde cuándo cuentan: hace `dias` días, en UTC como creada_en.
     */
    private function desde(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new DateInterval('P' . $this->dias() . 'D'));
    }
}
