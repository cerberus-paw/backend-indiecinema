<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\EstadoObra;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Servicios\ServicioDeObras;

/**
 * La API interna de obras para moderación (EF-07.7), que revisa las obras nuevas en su bandeja y
 * no tiene acceso al esquema de programación: lee las pendientes, o una por su id, y las aprueba o
 * las rechaza. Sólo la llama moderación, con su token: lo controla la ruta.
 */
final class ObrasInternas extends Controlador
{
    /** Como la columna obra.motivo. */
    private const MAXIMO_MOTIVO = 1000;
    private const RESOLUCIONES = ['aprobar' => EstadoObra::Aprobada, 'rechazar' => EstadoObra::Rechazada];

    public function __construct(private readonly ServicioDeObras $obras)
    {
    }

    /**
     * GET /interno/obras/pendientes: las pendientes, primero la más vieja, con todo lo que el
     * moderador revisa. creada_en va en la hora del sitio.
     */
    public function pendientes(Peticion $peticion): Respuesta
    {
        $generos = $this->obras->mapaGeneros();

        return $this->json(array_map(
            static fn (array $pendiente): array => self::datos($pendiente[0], $generos) + ['creada_en' => str_replace(' ', 'T', $pendiente[1])],
            $this->obras->pendientes(),
        ));
    }

    /**
     * GET /interno/obras/{id}: una obra en cualquier estado, con los mismos datos que una
     * pendiente, para que el moderador rechace una aprobada (la denuncia de EF-07.8).
     *
     * @throws ExcepcionHttp 404 si no existe o se dio de baja
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        [$obra, $creada] = $this->obras->paraRevisar((string) $peticion->parametro('id'));

        return $this->json(self::datos($obra, $this->obras->mapaGeneros()) + ['creada_en' => str_replace(' ', 'T', $creada)]);
    }

    /**
     * POST /interno/obras/{id}/revision con {resolucion: aprobar|rechazar, motivo, moderador_id}:
     * la deja aprobada o rechazada con el motivo. cambio dice si estaba en otro estado: el
     * reintento responde lo mismo sin tocar nada.
     *
     * @throws ExcepcionHttp 404 si no existe o se dio de baja
     */
    public function revisar(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }
        $resolucion = $peticion->campo('resolucion');
        $motivo = $peticion->campo('motivo');
        $moderador = $peticion->campo('moderador_id');
        if (!is_string($resolucion) || !isset(self::RESOLUCIONES[$resolucion])) {
            return $this->json(['error' => '«resolucion» es aprobar o rechazar.'], 400);
        }
        if (!is_string($motivo) || trim($motivo) === '' || mb_strlen(trim($motivo)) > self::MAXIMO_MOTIVO) {
            return $this->json(['error' => 'Falta el motivo, de hasta ' . self::MAXIMO_MOTIVO . ' caracteres.'], 400);
        }
        if (!is_string($moderador) || !Uuid::esValido($moderador)) {
            return $this->json(['error' => '«moderador_id» es el UUID del moderador.'], 400);
        }

        [$obra, $cambio] = $this->obras->revisar($id, self::RESOLUCIONES[$resolucion], trim($motivo), $moderador);

        return $this->json([
            'id' => $obra->id,
            'titulo' => $obra->titulo,
            'dueno_id' => $obra->duenoId,
            'estado' => $obra->estado->value,
            'cambio' => $cambio,
        ]);
    }

    /**
     * Lo que el moderador revisa de una obra.
     *
     * @param array<string, string> $generos id => nombre
     *
     * @return array<string, mixed>
     */
    private static function datos(Obra $obra, array $generos): array
    {
        return [
            'id' => $obra->id,
            'titulo' => $obra->titulo,
            'tipo' => $obra->tipo->value,
            'anio' => $obra->anio,
            'director' => $obra->director,
            'duracion' => $obra->duracionMinutos,
            'sinopsis' => $obra->sinopsis,
            'generos' => array_map(static fn (string $id): string => $generos[$id] ?? $id, $obra->generosIds),
            'origen_copia' => $obra->origenCopia->value,
            'afiche' => $obra->afiche !== null,
            'dueno_id' => $obra->duenoId,
            'estado' => $obra->estado->value,
            'motivo' => $obra->motivo,
        ];
    }
}
