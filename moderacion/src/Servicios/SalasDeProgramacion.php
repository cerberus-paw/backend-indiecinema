<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use RuntimeException;

/**
 * Las salas, que son de programación: moderación las crea, las habilita o las suspende y las lee
 * por su API interna (EF-07.1), sin tocar su esquema.
 */
final class SalasDeProgramacion
{
    public function __construct(
        private readonly ClienteInterno $cliente,
        private readonly ArchivosDeSolicitudes $archivos,
    ) {
    }

    /**
     * Crea la sala de una solicitud aprobada, habilitada, con el id que guardó la solicitud, o
     * completa y habilita la que ya existía (las que se mudaron de programación). Va con la foto
     * si la solicitud tiene una; una mudada no tiene, y su sala se queda con la suya. Repetirlo no
     * crea otra.
     *
     * Moderación validó los datos con las mismas reglas: si programación los rechaza igual, es un
     * error nuestro y corta como cualquier otro.
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otro error
     */
    public function crearParaSolicitud(SolicitudDeSala $solicitud): void
    {
        $datos = $solicitud->datos;
        $campos = [
            'id' => $solicitud->salaId,
            'organizador_id' => $solicitud->usuarioId,
            'nombre' => $datos->nombre,
            'descripcion' => $datos->descripcion,
            'direccion' => $datos->direccion,
            'localidad' => $datos->localidad,
            'capacidad' => (string) $datos->capacidad,
            'peliculas_por_funcion' => (string) $datos->peliculasPorFuncion,
            'duracion_funcion' => (string) $datos->duracionFuncion,
            'tiempo_entre_funciones' => (string) $datos->tiempoEntreFunciones,
        ];
        $respuesta = $solicitud->imagen === null
            ? $this->cliente->post('programacion', '/interno/salas', $campos)
            : $this->cliente->postConArchivo('programacion', '/interno/salas', $campos, 'imagen', $this->archivos->rutaDe($solicitud->imagen));
        if (!is_array($respuesta) || ($respuesta['id'] ?? null) !== $solicitud->salaId) {
            throw new RuntimeException("programación no respondió la sala {$solicitud->salaId} a POST /interno/salas.");
        }
    }

    /**
     * Cómo está la sala en el sitio: 'publicada', 'retirada', o 'dada_de_baja' si programación ya
     * no la devuelve (su organizador la dio de baja).
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function enElSitio(string $salaId): string
    {
        $salas = $this->cliente->get('programacion', '/interno/salas', ['ids' => $salaId]);
        if (!is_array($salas)) {
            throw new RuntimeException('programación no respondió las salas a GET /interno/salas.');
        }
        $sala = $salas[$salaId] ?? null;
        if (!is_array($sala)) {
            return 'dada_de_baja';
        }

        return ($sala['habilitada'] ?? null) === true ? 'publicada' : 'retirada';
    }

    /**
     * La publica o la retira del sitio. El motivo y quién lo hizo quedan en el registro de
     * programación.
     *
     * @throws ExcepcionHttp    404 si programación ya no la tiene; 503 si no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function cambiarHabilitacion(string $salaId, bool $habilitada, string $motivo, string $quien): void
    {
        $respuesta = $this->cliente->post('programacion', "/interno/salas/{$salaId}/habilitacion", [
            'habilitada' => $habilitada,
            'motivo' => $motivo,
            'moderador_id' => $quien,
        ]);
        if ($respuesta === null) {
            throw ExcepcionHttp::noEncontrada();
        }
    }
}
