<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Servicios\ServicioDeCartelera;
use IndieCinema\Programacion\Servicios\ServicioDeObras;

/**
 * GET /obras: el listado público del catálogo de obras, con buscador por título, director y género.
 * GET /obras/{id}: la ficha pública de una obra, con sus datos y sus próximas funciones en cartelera.
 */
final class Obras extends Controlador
{
    private const PLANTILLA_LISTADO = 'obras.html.twig';
    private const PLANTILLA_FICHA = 'obra.html.twig';

    public function __construct(
        private readonly ServicioDeObras $servicio,
        private readonly ServicioDeCartelera $cartelera,
    ) {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $parametros = ParametrosDeObras::desdeConsulta(
            ['q' => $peticion->consulta('q'), 'genero' => $peticion->consulta('genero')],
            $this->servicio->generoPorNombre(...),
        );

        $obras = $this->servicio->catalogo($parametros->texto, $parametros->genero?->id);
        $mapaGeneros = $this->servicio->mapaGeneros();

        $tarjetas = array_map(
            static fn (Obra $obra): array => TarjetaDeObra::datos($obra, $mapaGeneros),
            $obras,
        );

        if ($peticion->aceptaJson()) {
            return $this->json([
                'obras' => $tarjetas,
                'filtros' => (object) $parametros->valores,
                'avisos' => $parametros->avisos,
            ]);
        }

        return $this->vista(self::PLANTILLA_LISTADO, [
            'obras' => $tarjetas,
            'filtros' => $parametros->valores,
            'filtrada' => $parametros->valores !== [],
            'generos' => array_map(static fn (Genero $g): array => ['id' => $g->id, 'nombre' => $g->nombre], $this->servicio->generos()),
            'avisos' => $parametros->avisos,
        ]);
    }

    /**
     * @throws ExcepcionHttp 404 si la obra no existe o no es UUID válido
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $obra = $this->servicio->buscar($id);
        $mapaGeneros = $this->servicio->mapaGeneros();

        $usuario = $peticion->usuario();
        $esDueno = $usuario !== null && $usuario->alcanza(Rol::Organizador) && $obra->duenoId === $usuario->id;

        $funciones = $this->servicio->proximasFunciones($id);
        $tarjetasFunciones = TarjetaDeFuncion::todas($funciones, $this->cartelera->acuerdosDe($funciones));

        $datosObra = [
            ...TarjetaDeObra::datos($obra, $mapaGeneros),
            'editable' => $esDueno,
            'url_editar' => "/organizador/obras/{$obra->id}/editar",
            'tiene_funciones_futuras' => $this->servicio->tieneFuncionesFuturas($obra->id),
        ];

        if ($peticion->aceptaJson()) {
            return $this->json([
                'obra' => $datosObra,
                'funciones' => $tarjetasFunciones,
            ]);
        }

        return $this->vista(self::PLANTILLA_FICHA, [
            'obra' => $datosObra,
            'funciones' => $tarjetasFunciones,
        ]);
    }
}
