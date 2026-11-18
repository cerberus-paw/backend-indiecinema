<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\Calificacion;
use IndieCinema\Funciones\Repositorios\RepositorioDeCalificaciones;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\UsuariosDeCuentas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;

/**
 * Las calificaciones para las fichas de programación (EF-04.3): los promedios de las salas, las
 * obras, los organizadores y las funciones, y las últimas que dejaron los espectadores. Las pide
 * programación, con su token: lo controla la ruta. Los promedios los pide también recaudación,
 * para la reportería (EF-09).
 */
final class CalificacionesInternas extends Controlador
{
    /** Por tipo: más que una página de la cartelera. Un pedido más largo es un error de quien llama. */
    private const MAXIMO_POR_TIPO = 100;

    /** Las últimas que muestra una ficha. */
    private const ULTIMAS = 10;

    public function __construct(
        private readonly RepositorioDeCalificaciones $calificaciones,
        private readonly RepositorioDeReservas $reservas,
        private readonly UsuariosDeCuentas $usuarios,
    ) {
    }

    /**
     * GET /interno/calificaciones/resumen?salas=…&obras=…&organizadores=…&funciones=…: el promedio
     * (con un decimal) y la cantidad de cada uno que tenga calificaciones; los que no tienen no
     * vienen. Las funciones traen también cuántos entraron, para el historial de la sala. Los ids
     * que no son UUID se ignoran, como en la ocupación.
     */
    public function resumen(Peticion $peticion): Respuesta
    {
        $ids = [];
        foreach (['salas', 'obras', 'organizadores', 'funciones'] as $tipo) {
            $lista = $peticion->consulta($tipo);
            $ids[$tipo] = is_string($lista) ? array_values(array_unique(array_filter(explode(',', $lista), Uuid::esValido(...)))) : [];
            if (count($ids[$tipo]) > self::MAXIMO_POR_TIPO) {
                return $this->json(['error' => 'Se piden hasta ' . self::MAXIMO_POR_TIPO . " {$tipo} por vez."], 400);
            }
        }

        $resumen = $this->calificaciones->resumen($ids);

        // Objetos aunque estén vacíos: json_encode() escribiría [] como lista.
        return $this->json([
            ...array_map(static fn (array $porId): object => (object) $porId, $resumen),
            'asistentes' => (object) $this->reservas->asistentesDe($ids['funciones']),
        ]);
    }

    /**
     * GET /interno/calificaciones?sala=… o ?obra=…: las últimas diez, de la más nueva a la más
     * vieja, con el nombre de quien la dejó. Si cuentas no responde, salen sin el nombre.
     *
     * @throws ExcepcionHttp 400 sin una sala o una obra válida
     */
    public function ultimas(Peticion $peticion): Respuesta
    {
        $sala = $peticion->consulta('sala');
        $obra = $peticion->consulta('obra');
        if (is_string($sala) && Uuid::esValido($sala)) {
            $calificaciones = $this->calificaciones->ultimas('sala', $sala, self::ULTIMAS);
        } elseif (is_string($obra) && Uuid::esValido($obra)) {
            $calificaciones = $this->calificaciones->ultimas('obra', $obra, self::ULTIMAS);
        } else {
            throw ExcepcionHttp::solicitudInvalida('Se piden las calificaciones de una sala (?sala=) o de una obra (?obra=).');
        }
        $nombres = $this->usuarios->nombresDe(array_map(static fn (Calificacion $calificacion): string => $calificacion->usuarioId, $calificaciones));

        return $this->json(['calificaciones' => array_map(static fn (Calificacion $calificacion): array => [
            // Para denunciar el comentario (EF-07.8).
            'id' => $calificacion->id,
            'funcion_id' => $calificacion->funcionId,
            'puntaje' => $calificacion->puntaje,
            // El que ocultó la moderación no se muestra (EF-07.8).
            'comentario' => $calificacion->comentarioOculto ? null : $calificacion->comentario,
            // Sólo el nombre: el correo y el id del espectador no salen de funciones.
            'espectador' => $nombres[$calificacion->usuarioId] ?? null,
            'fecha' => $calificacion->creadaEn->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d'),
        ], $calificaciones)]);
    }

    /**
     * GET /interno/calificaciones/{id}, para moderación (EF-07.8): el comentario denunciado, con su
     * autor (que no denuncia lo suyo) y si ya está oculto.
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $calificacion = $this->calificacionDe($peticion);

        return $this->json([
            'id' => $calificacion->id,
            'funcion_id' => $calificacion->funcionId,
            'sala_id' => $calificacion->salaId,
            'usuario_id' => $calificacion->usuarioId,
            'espectador' => $this->usuarios->nombresDe([$calificacion->usuarioId])[$calificacion->usuarioId] ?? null,
            'puntaje' => $calificacion->puntaje,
            'comentario' => $calificacion->comentario,
            'fecha' => $calificacion->creadaEn->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d'),
            'oculto' => $calificacion->comentarioOculto,
        ]);
    }

    /**
     * POST /interno/calificaciones/{id}/ocultar con {moderador_id}, para moderación (EF-07.8): el
     * comentario deja de verse en las fichas. cambio dice si estaba a la vista: el reintento
     * responde lo mismo sin tocar nada.
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function ocultar(Peticion $peticion): Respuesta
    {
        $calificacion = $this->calificacionDe($peticion);
        $moderador = $peticion->campo('moderador_id');
        if (!is_string($moderador) || !Uuid::esValido($moderador)) {
            return $this->json(['error' => '«moderador_id» es el UUID del moderador.'], 400);
        }
        $cambio = $this->calificaciones->ocultarComentario($calificacion->id, $moderador, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return $this->json(['id' => $calificacion->id, 'cambio' => $cambio]);
    }

    /**
     * @throws ExcepcionHttp 404 si no existe
     */
    private function calificacionDe(Peticion $peticion): Calificacion
    {
        $id = (string) $peticion->parametro('id');

        return (Uuid::esValido($id) ? $this->calificaciones->buscar($id) : null) ?? throw ExcepcionHttp::noEncontrada();
    }
}
