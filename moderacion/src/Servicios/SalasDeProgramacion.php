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
     * crea otra. Con la solicitud, programación le aplica a una sala habilitada un cambio de
     * dirección aprobado (EF-07.6), y reconoce el reintento.
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
            // EF-07.6: programación la recuerda, así un cambio aprobado se aplica una sola vez.
            'solicitud_id' => $solicitud->id,
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
        $sala = $this->datosDe($salaId);
        if ($sala === null) {
            return 'dada_de_baja';
        }

        return $sala['habilitada'] ? 'publicada' : 'retirada';
    }

    /**
     * Las habilitadas que no se dieron de baja, con el id como clave (EF-07.5).
     *
     * @return array<string, array{nombre: string, organizador_id: string}>
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function habilitadas(): array
    {
        $salas = $this->cliente->get('programacion', '/interno/salas', ['habilitadas' => '1']);
        if (!is_array($salas)) {
            throw new RuntimeException('programación no respondió las salas habilitadas a GET /interno/salas.');
        }
        $habilitadas = [];
        foreach ($salas as $id => $sala) {
            if (is_array($sala)) {
                $habilitadas[(string) $id] = ['nombre' => (string) ($sala['nombre'] ?? ''), 'organizador_id' => (string) ($sala['organizador_id'] ?? '')];
            }
        }

        return $habilitadas;
    }

    /**
     * Lo que programación cuenta de la sala, o null si ya no la tiene (se dio de baja).
     *
     * @return ?array{nombre: string, organizador_id: string, habilitada: bool}
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function datosDe(string $salaId): ?array
    {
        return $this->datosDeVarias([$salaId])[$salaId] ?? null;
    }

    /**
     * Lo mismo de varias, con el id como clave; las que programación ya no tiene no están.
     *
     * @param list<string> $ids hasta 50, lo que admite programación por pedido
     *
     * @return array<string, array{nombre: string, organizador_id: string, habilitada: bool}>
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function datosDeVarias(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $salas = $this->cliente->get('programacion', '/interno/salas', ['ids' => implode(',', array_values(array_unique($ids)))]);
        if (!is_array($salas)) {
            throw new RuntimeException('programación no respondió las salas a GET /interno/salas.');
        }
        $datos = [];
        foreach ($salas as $id => $sala) {
            if (is_array($sala)) {
                $datos[(string) $id] = [
                    'nombre' => (string) ($sala['nombre'] ?? ''),
                    'organizador_id' => (string) ($sala['organizador_id'] ?? ''),
                    'habilitada' => ($sala['habilitada'] ?? null) === true,
                ];
            }
        }

        return $datos;
    }

    /**
     * La publica o la retira del sitio. El motivo y quién lo hizo quedan en el registro de
     * programación; sin quién, lo hizo la tarea de vencimientos (EF-07.5).
     *
     * @throws ExcepcionHttp    404 si programación ya no la tiene; 503 si no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function cambiarHabilitacion(string $salaId, bool $habilitada, string $motivo, ?string $quien): void
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
