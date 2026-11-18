<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\Comercio;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeComercios;

/**
 * Los comercios asociados (EF-17.3 / IC-598).
 *
 * - GET /recaudacion/beneficios: la página pública con los activos, sin pedir sesión.
 * - GET /recaudacion/administracion/comercios: el administrador los ve todos, con el alta arriba.
 * - GET y POST /recaudacion/administracion/comercios/{id}: los edita.
 * - POST /recaudacion/administracion/comercios: el alta.
 * - POST /recaudacion/administracion/comercios/{id}/activo: la baja o la reactivación.
 *
 * Las del administrador piden ese rol, declarado en rutas.php.
 */
final class Comercios extends Controlador
{
    public function __construct(private readonly RepositorioDeComercios $comercios)
    {
    }

    public function publicos(Peticion $peticion): Respuesta
    {
        // Agrupados por localidad: quien va a una sala busca lo que tiene cerca. Ya vienen por
        // localidad y nombre.
        $localidades = [];
        foreach ($this->comercios->activos() as $comercio) {
            $localidades[$comercio->localidad] ??= ['localidad' => $comercio->localidad, 'comercios' => []];
            $localidades[$comercio->localidad]['comercios'][] = self::datos($comercio);
        }

        return $this->vista('beneficios.html.twig', ['localidades' => array_values($localidades)]);
    }

    public function listar(Peticion $peticion): Respuesta
    {
        return $this->pantallaDelListado(
            guardado: $peticion->consulta('guardado') === '1',
            cambiado: $peticion->consulta('cambiado') === '1',
        );
    }

    public function crear(Peticion $peticion): Respuesta
    {
        $valores = self::valores($peticion);
        $errores = Comercio::errores($valores);
        if ($errores !== []) {
            return $this->pantallaDelListado($valores, $errores, estado: 422);
        }
        $this->comercios->guardar(new Comercio(Uuid::nuevo(), ...$valores, activo: true));

        return $this->redirigir('/administracion/comercios?guardado=1');
    }

    public function editar(Peticion $peticion): Respuesta
    {
        $comercio = $this->buscar($peticion);

        return $this->vista('administracion_comercio.html.twig', [
            'comercio' => self::datos($comercio),
            'valores' => self::datos($comercio),
            'errores' => [],
        ]);
    }

    public function guardar(Peticion $peticion): Respuesta
    {
        $comercio = $this->buscar($peticion);
        $valores = self::valores($peticion);
        $errores = Comercio::errores($valores);
        if ($errores !== []) {
            return $this->vista('administracion_comercio.html.twig', [
                'comercio' => self::datos($comercio),
                'valores' => $valores,
                'errores' => $errores,
            ], 422);
        }
        $this->comercios->guardar(new Comercio($comercio->id, ...$valores, activo: $comercio->activo));

        return $this->redirigir('/administracion/comercios?guardado=1');
    }

    public function cambiarActivo(Peticion $peticion): Respuesta
    {
        $comercio = $this->buscar($peticion);
        $this->comercios->guardar(new Comercio(
            $comercio->id,
            $comercio->nombre,
            $comercio->beneficio,
            $comercio->direccion,
            $comercio->localidad,
            activo: $peticion->campo('activo') === '1',
        ));

        return $this->redirigir('/administracion/comercios?cambiado=1');
    }

    /**
     * @param array<string, string> $valores
     * @param array<string, string> $errores
     */
    private function pantallaDelListado(
        array $valores = ['nombre' => '', 'beneficio' => '', 'direccion' => '', 'localidad' => ''],
        array $errores = [],
        bool $guardado = false,
        bool $cambiado = false,
        int $estado = 200,
    ): Respuesta {
        return $this->vista('administracion_comercios.html.twig', [
            'comercios' => array_map(self::datos(...), $this->comercios->todos()),
            'valores' => $valores,
            'errores' => $errores,
            'guardado' => $guardado,
            'cambiado' => $cambiado,
        ], $estado);
    }

    /**
     * @throws ExcepcionHttp 404 si el id no es de un comercio
     */
    private function buscar(Peticion $peticion): Comercio
    {
        $id = (string) $peticion->parametro('id');

        return (Uuid::esValido($id) ? $this->comercios->buscar($id) : null) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * @return array{nombre: string, beneficio: string, direccion: string, localidad: string}
     */
    private static function valores(Peticion $peticion): array
    {
        $texto = static fn (mixed $valor): string => is_string($valor) ? trim($valor) : '';

        return [
            'nombre' => $texto($peticion->campo('nombre')),
            'beneficio' => $texto($peticion->campo('beneficio')),
            'direccion' => $texto($peticion->campo('direccion')),
            'localidad' => $texto($peticion->campo('localidad')),
        ];
    }

    /**
     * @return array{id: string, nombre: string, beneficio: string, direccion: string, localidad: string, activo: bool}
     */
    private static function datos(Comercio $comercio): array
    {
        return [
            'id' => $comercio->id,
            'nombre' => $comercio->nombre,
            'beneficio' => $comercio->beneficio,
            'direccion' => $comercio->direccion,
            'localidad' => $comercio->localidad,
            'activo' => $comercio->activo,
        ];
    }
}
