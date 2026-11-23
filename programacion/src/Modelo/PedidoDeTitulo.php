<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Pedido de una obra que todavía no está en el catálogo de IndieCinema, con sus adhesiones (EF-11).
 */
final readonly class PedidoDeTitulo
{
    public function __construct(
        public string $id,
        public string $titulo,
        public ?int $anio,
        public string $director,
        public string $creadoPor,
        public EstadoPedido $estado,
        public int $adhesiones,
        public bool $adheridoPorUsuario,
        public DateTimeImmutable $creadoEn,
        public ?DateTimeImmutable $activadoEn = null,
        public ?string $obraId = null,
        public ?string $obraTitulo = null,
        public ?string $tomadoPor = null,
        public ?DateTimeImmutable $tomadoEn = null,
        public string $tituloNormalizado = '',
    ) {
    }
}
