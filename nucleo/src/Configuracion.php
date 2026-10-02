<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

use RuntimeException;

/**
 * Configuración del subsistema, leída de su config.ini.
 *
 * El archivo no se versiona: es lo único que cambia entre desarrollo y el VPS, y es donde viven
 * los secretos de cada subsistema.
 */
final class Configuracion
{
    /** Lo que dicen los config.ejemplo.ini en cada clave y token. */
    public const VALOR_DE_EJEMPLO = 'cambiar';

    /**
     * @param array<string, array<string, mixed>> $secciones
     */
    public function __construct(private readonly array $secciones)
    {
    }

    public static function desdeArchivo(string $ruta): self
    {
        if (!is_file($ruta)) {
            throw new RuntimeException("No existe {$ruta}: hay que copiar config.ejemplo.ini a config.ini.");
        }

        // Con INI_SCANNER_TYPED, true, false y los números llegan con su tipo y no como texto.
        $secciones = parse_ini_file($ruta, true, INI_SCANNER_TYPED);
        if ($secciones === false) {
            throw new RuntimeException("{$ruta} no es un archivo ini válido.");
        }

        return new self($secciones);
    }

    /**
     * El valor de «seccion.clave», o $porDefecto si no está.
     */
    public function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        [$seccion, $nombre] = array_pad(explode('.', $clave, 2), 2, '');

        return $this->secciones[$seccion][$nombre] ?? $porDefecto;
    }

    /**
     * Un valor obligatorio. Si falta, el subsistema no arranca, en lugar de fallar más adelante
     * en medio de una petición.
     */
    public function requerir(string $clave): mixed
    {
        $valor = $this->obtener($clave);
        if ($valor === null || $valor === '') {
            throw new RuntimeException("Falta «{$clave}» en config.ini.");
        }

        return $valor;
    }

    public function enDesarrollo(): bool
    {
        return $this->obtener('app.entorno') === 'desarrollo';
    }

    /**
     * Fuera de desarrollo, el subsistema no arranca si quedó algún valor del ejemplo: así una
     * clave o un secreto de mentira, que están en el repositorio, no llegan al VPS.
     *
     * @throws RuntimeException con las claves que faltan cambiar, nunca con sus valores
     */
    public function verificarQueNoQuedenValoresDeEjemplo(): void
    {
        if ($this->enDesarrollo()) {
            return;
        }

        $deEjemplo = [];
        foreach ($this->secciones as $seccion => $valores) {
            foreach ((array) $valores as $nombre => $valor) {
                if ($valor === self::VALOR_DE_EJEMPLO) {
                    $deEjemplo[] = "{$seccion}.{$nombre}";
                }
            }
        }
        if ($deEjemplo !== []) {
            throw new RuntimeException('Quedan valores de ejemplo en config.ini: ' . implode(', ', $deEjemplo) . '.');
        }
    }
}
