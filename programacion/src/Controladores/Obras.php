<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Servicios\CalificacionesDeFunciones;
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
        // El promedio y las últimas calificaciones de la obra, que viven en funciones (EF-04.3).
        private readonly CalificacionesDeFunciones $calificaciones,
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
        // En un pedido, el promedio de la obra y el de las salas donde se da (EF-04.3 y EF-04.4).
        $resumen = $this->calificaciones->resumen([
            'obras' => [$obra->id],
            'salas' => array_values(array_unique(array_map(static fn (FuncionEnCartelera $funcion): string => $funcion->salaId, $funciones))),
        ]);
        $funciones = self::porReputacionDeLaSala($funciones, $resumen['salas'] ?? []);
        $tarjetasFunciones = TarjetaDeFuncion::todas($funciones, $this->cartelera->acuerdosDe($funciones));

        $deLaObra = $resumen['obras'][$obra->id] ?? null;
        $ultimas = $deLaObra === null ? [] : $this->calificaciones->ultimas('obra', $obra->id);
        $calificaciones = CalificacionesParaMostrar::datos($resumen !== null && $ultimas !== null, $deLaObra, $ultimas);

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
                'calificaciones' => $calificaciones,
            ]);
        }

        return $this->vista(self::PLANTILLA_FICHA, [
            'obra' => $datosObra,
            'funciones' => $tarjetasFunciones,
            'calificaciones' => $calificaciones,
        ]);
    }

    /**
     * Las próximas funciones de la obra, primero las de las salas mejor calificadas (EF-04.4): al
     * buscar un título, se elige también dónde verlo. Las de salas sin calificaciones van después,
     * y entre las de una misma reputación sigue el orden por fecha (usort es estable).
     *
     * @param list<FuncionEnCartelera>                            $funciones por fecha
     * @param array<string, array{promedio: float, cantidad: int}> $salas     las calificadas
     *
     * @return list<FuncionEnCartelera>
     */
    private static function porReputacionDeLaSala(array $funciones, array $salas): array
    {
        usort($funciones, static fn (FuncionEnCartelera $una, FuncionEnCartelera $otra): int => [
            isset($salas[$otra->salaId]), $salas[$otra->salaId]['promedio'] ?? 0.0, $salas[$otra->salaId]['cantidad'] ?? 0,
        ] <=> [
            isset($salas[$una->salaId]), $salas[$una->salaId]['promedio'] ?? 0.0, $salas[$una->salaId]['cantidad'] ?? 0,
        ]);

        return $funciones;
    }
}
