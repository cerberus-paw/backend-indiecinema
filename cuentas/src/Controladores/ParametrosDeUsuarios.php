<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

/**
 * Valida la búsqueda y la página del listado de usuarios del administrador, que llegan en la URL.
 * Como en la cartelera y en el listado de salas, una búsqueda que no sirve no rompe la página: se
 * listan todos y el aviso dice por qué. Una página que no es un número positivo no existe, y la
 * decide el controlador con un 404.
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

    /** Lo que se buscó, sin los espacios de alrededor, o null si no se busca nada. */
    public private(set) ?string $texto = null;

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
        $parametros->texto = $parametros->texto($consulta['q'] ?? null);
        $pagina = $consulta['pagina'] ?? null;
        if ($pagina !== null && $pagina !== '') {
            $parametros->pagina = is_string($pagina) && preg_match(self::PAGINA, $pagina) === 1 ? (int) $pagina : null;
        }

        return $parametros;
    }

    /**
     * La URL de otra página con la misma búsqueda. La primera va sin el número, así es la misma
     * que arma el formulario.
     */
    public function urlDePagina(int $numero): string
    {
        $consulta = http_build_query(
            array_filter(['q' => $this->texto, 'pagina' => $numero > 1 ? $numero : null], static fn (mixed $valor): bool => $valor !== null),
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return self::RUTA . ($consulta === '' ? '' : "?{$consulta}");
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        // Un «q[]=…» llega como arreglo, y un texto que no es UTF-8 no se puede comparar.
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            $this->avisos[] = 'La búsqueda no es un texto válido: se muestran todos los usuarios.';

            return null;
        }
        $texto = trim($valor);
        if (mb_strlen($texto) > self::LARGO_TEXTO) {
            $this->avisos[] = 'La búsqueda puede tener hasta ' . self::LARGO_TEXTO . ' caracteres: se muestran todos los usuarios.';

            return null;
        }

        return $texto === '' ? null : $texto;
    }
}
