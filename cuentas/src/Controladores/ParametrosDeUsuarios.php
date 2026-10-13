<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\FiltrosDeUsuarios;
use IndieCinema\Nucleo\Seguridad\Rol;

/**
 * Valida la búsqueda, los filtros y la página del listado de usuarios del administrador, que
 * llegan en la URL. Como en la cartelera, un filtro que no sirve no rompe la página: se deja
 * afuera y el aviso dice por qué. Una página que no es un número positivo no existe, y la decide
 * el controlador con un 404.
 *
 * Los nombres son los del formulario de cuentas/administracion_usuarios en el paquete front.
 */
final class ParametrosDeUsuarios
{
    /** La ruta con el prefijo de cuentas: los links del paginado van al navegador, no a nginx. */
    public const RUTA = '/cuenta/administracion/usuarios';

    /** Como la columna usuario.correo, la más larga de las dos donde se busca. */
    private const LARGO_TEXTO = 254;
    /** Hasta acá, una página más no cambia nada: sólo evita pasar un número enorme a la base. */
    private const PAGINA = '/^[1-9][0-9]{0,8}$/D';

    public private(set) FiltrosDeUsuarios $filtros;

    /**
     * Lo que se aplicó, como lo manda el formulario, para volver a completarlo y para los links
     * del paginado. Falta el filtro que no se aplicó.
     *
     * @var array<string, string>
     */
    public private(set) array $valores = [];

    /** La página pedida, o null si no es un número de página. */
    public private(set) ?int $pagina = 1;

    /** @var list<string> */
    public private(set) array $avisos = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $consulta los parámetros de la URL
     */
    public static function desdeConsulta(array $consulta): self
    {
        $parametros = new self();
        $texto = $parametros->texto($consulta['q'] ?? null);
        // Visitante no es un rol de una cuenta: es quien no tiene.
        $rol = $parametros->opcion($consulta['rol'] ?? null, 'El rol', static fn (string $valor): ?Rol => ($rol = Rol::tryFrom($valor)) === Rol::Visitante ? null : $rol);
        $estado = $parametros->opcion($consulta['estado'] ?? null, 'El estado', EstadoCuenta::tryFrom(...));
        $parametros->filtros = new FiltrosDeUsuarios($texto, $rol, $estado);
        $parametros->valores = array_filter(
            ['q' => $texto, 'rol' => $rol?->value, 'estado' => $estado?->value],
            static fn (?string $valor): bool => $valor !== null,
        );

        $pagina = $consulta['pagina'] ?? null;
        if ($pagina !== null && $pagina !== '') {
            $parametros->pagina = is_string($pagina) && preg_match(self::PAGINA, $pagina) === 1 ? (int) $pagina : null;
        }

        return $parametros;
    }

    /**
     * La URL de otra página con la misma búsqueda y los mismos filtros. La primera va sin el
     * número, así es la misma que arma el formulario.
     */
    public function urlDePagina(int $numero): string
    {
        $consulta = http_build_query($this->valores + ($numero > 1 ? ['pagina' => $numero] : []), '', '&', PHP_QUERY_RFC3986);

        return self::RUTA . ($consulta === '' ? '' : "?{$consulta}");
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        // Un «q[]=…» llega como arreglo, y un texto que no es UTF-8 no se puede comparar.
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            $this->avisos[] = 'La búsqueda no es un texto válido: se muestran los usuarios sin ese filtro.';

            return null;
        }
        $texto = trim($valor);
        if (mb_strlen($texto) > self::LARGO_TEXTO) {
            $this->avisos[] = 'La búsqueda puede tener hasta ' . self::LARGO_TEXTO . ' caracteres: se muestran los usuarios sin ese filtro.';

            return null;
        }

        return $texto === '' ? null : $texto;
    }

    /**
     * Un valor de un desplegable: vacío es «todos»; uno que no es de la lista se avisa.
     *
     * @template T
     *
     * @param callable(string): ?T $desde
     *
     * @return ?T
     */
    private function opcion(mixed $valor, string $etiqueta, callable $desde): mixed
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $opcion = is_string($valor) ? $desde($valor) : null;
        if ($opcion === null) {
            $this->avisos[] = "{$etiqueta} no es válido: se muestran los usuarios sin ese filtro.";
        }

        return $opcion;
    }
}
