<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Ruteo;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use LogicException;

/**
 * Asocia método + ruta con el controlador que la atiende. Cada subsistema declara sus rutas en
 * su rutas.php.
 */
final class Router
{
    /** @var list<Ruta> */
    private array $rutas = [];

    /**
     * @param array{0: class-string, 1: string} $accion
     */
    public function get(string $patron, array $accion, Rol $rolMinimo = Rol::Visitante): void
    {
        $this->agregar(new Ruta('GET', $patron, $accion, $rolMinimo));
    }

    /**
     * @param array{0: class-string, 1: string} $accion
     */
    public function post(string $patron, array $accion, Rol $rolMinimo = Rol::Visitante): void
    {
        $this->agregar(new Ruta('POST', $patron, $accion, $rolMinimo));
    }

    /**
     * Una ruta de la API interna. No pide rol: la autoriza el token del subsistema que llama, que
     * tiene que estar entre sus $llamadores (E2: cada ruta interna declara qué llamadores acepta).
     *
     * @param array{0: class-string, 1: string} $accion
     * @param list<string>                      $llamadores
     */
    public function interna(string $metodo, string $patron, array $accion, array $llamadores): void
    {
        if (!str_starts_with($patron, '/interno/')) {
            throw new LogicException("La ruta interna {$patron} tiene que empezar con /interno/.");
        }
        $this->agregar(new Ruta(strtoupper($metodo), $patron, $accion, Rol::Visitante, $llamadores));
    }

    public function agregar(Ruta $ruta): void
    {
        if (!str_starts_with($ruta->patron, '/')) {
            throw new LogicException("La ruta {$ruta->patron} tiene que empezar con /.");
        }
        // Una ruta /interno/ sin llamadores quedaría abierta a cualquiera de la red interna.
        if ($ruta->esInterna() && $ruta->llamadores === []) {
            throw new LogicException("La ruta {$ruta->patron} es interna: se declara con interna() y sus llamadores.");
        }
        $this->rutas[] = $ruta;
    }

    /**
     * @return array{0: Ruta, 1: array<string, string>} la ruta y sus parámetros
     *
     * @throws ExcepcionHttp 404 si no existe la ruta, 405 si existe con otro método
     */
    public function resolver(string $metodo, string $ruta): array
    {
        // HEAD es un GET sin cuerpo: lo atiende la misma ruta.
        $buscado = $metodo === 'HEAD' ? 'GET' : $metodo;
        $otrosMetodos = [];

        foreach ($this->rutas as $candidata) {
            $parametros = $candidata->coincide($ruta);
            if ($parametros === null) {
                continue;
            }
            if ($candidata->metodo === $buscado) {
                return [$candidata, $parametros];
            }
            $otrosMetodos[] = $candidata->metodo;
        }

        if ($otrosMetodos !== []) {
            throw ExcepcionHttp::metodoNoPermitido(array_values(array_unique($otrosMetodos)));
        }

        throw ExcepcionHttp::noEncontrada();
    }
}
