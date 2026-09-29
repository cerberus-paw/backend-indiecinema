<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Ruteo;

use IndieCinema\Nucleo\Seguridad\Rol;

/**
 * Una ruta del subsistema: método, patrón, la acción que la atiende y quién puede entrar.
 */
final readonly class Ruta
{
    private string $expresion;

    /**
     * @param string                       $patron     con parámetros entre llaves: «/salas/{id}»
     * @param array{0: class-string, 1: string} $accion controlador y método
     * @param list<string>                 $llamadores para las rutas /interno/: los subsistemas que pueden llamarla
     */
    public function __construct(
        public string $metodo,
        public string $patron,
        public array $accion,
        public Rol $rolMinimo = Rol::Visitante,
        public array $llamadores = [],
    ) {
        $segmentos = array_map(
            static fn (string $segmento): string => preg_match('/^\{(\w+)\}$/', $segmento, $coincidencia) === 1
                ? "(?P<{$coincidencia[1]}>[^/]+)"
                : preg_quote($segmento, '#'),
            explode('/', $patron),
        );
        $this->expresion = '#^' . implode('/', $segmentos) . '$#';
    }

    public function esInterna(): bool
    {
        return str_starts_with($this->patron, '/interno/');
    }

    /**
     * Los parámetros de la ruta si coincide con $ruta, o null si no coincide.
     *
     * @return array<string, string>|null
     */
    public function coincide(string $ruta): ?array
    {
        if (preg_match($this->expresion, $ruta, $coincidencias) !== 1) {
            return null;
        }

        return array_filter($coincidencias, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
