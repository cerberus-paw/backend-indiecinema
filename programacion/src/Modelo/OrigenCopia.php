<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Con qué derecho se proyecta la copia: el dueño la hizo, ya no tiene derechos de autor o el
 * dueño compró la licencia.
 */
enum OrigenCopia: string
{
    case ObraPropia = 'obra_propia';
    case DominioPublico = 'dominio_publico';
    case LicenciaAdquirida = 'licencia_adquirida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ObraPropia => 'Obra propia',
            self::DominioPublico => 'Dominio público',
            self::LicenciaAdquirida => 'Licencia adquirida',
        };
    }

    /**
     * @return list<array{valor: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return [
            ['valor' => self::ObraPropia->value, 'etiqueta' => 'Obra propia'],
            ['valor' => self::DominioPublico->value, 'etiqueta' => 'Dominio público'],
            ['valor' => self::LicenciaAdquirida->value, 'etiqueta' => 'Licencia adquirida'],
        ];
    }
}
