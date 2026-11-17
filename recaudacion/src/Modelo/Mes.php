<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;

/**
 * Un mes, que es el período de las liquidaciones (EF-08.3). Viaja y se guarda como «2026-11», que
 * es como ordena bien de texto y como entra en la columna CHAR(7).
 */
final readonly class Mes
{
    private const NOMBRES = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    private function __construct(public int $anio, public int $numero)
    {
    }

    /** El mes en el que cae ese momento. */
    public static function de(DateTimeImmutable $momento): self
    {
        return new self((int) $momento->format('Y'), (int) $momento->format('n'));
    }

    /** «2026-11»; null si no es un mes. */
    public static function desdeTexto(string $texto): ?self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($texto), $partes) !== 1) {
            return null;
        }
        $numero = (int) $partes[2];

        return $numero >= 1 && $numero <= 12 ? new self((int) $partes[1], $numero) : null;
    }

    /** «2026-11», como se guarda y como va en la URL. */
    public function comoTexto(): string
    {
        return sprintf('%04d-%02d', $this->anio, $this->numero);
    }

    /** «noviembre de 2026», como se lee en la pantalla. */
    public function nombre(): string
    {
        return self::NOMBRES[$this->numero] . " de {$this->anio}";
    }

    /** El primer momento del mes, para buscar entre él y el del siguiente. */
    public function empieza(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->comoTexto() . '-01 00:00:00');
    }

    /** El primer momento del mes que viene: el final del mes, sin incluirlo. */
    public function termina(): DateTimeImmutable
    {
        return $this->empieza()->modify('+1 month');
    }
}
