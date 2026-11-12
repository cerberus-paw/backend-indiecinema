<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;

/**
 * La API interna de salas para moderación (EF-07.1), que tiene las solicitudes de sala y no tiene
 * acceso al esquema de programación: crea la sala de una solicitud aprobada, la habilita o la
 * suspende, y lee varias para su bandeja. Sólo la llama moderación, con su token: lo controla la
 * ruta. Desde afuera nginx ni siquiera las deja llegar.
 */
final class SalasInternas extends Controlador
{
    /** Una página de la bandeja de moderación: un pedido más largo es un error de quien llama. */
    private const MAXIMO_POR_PEDIDO = 50;

    public function __construct(private readonly ServicioDeSalas $salas)
    {
    }

    /**
     * GET /interno/salas?ids=…: nombre, localidad, organizador y si está habilitada de cada una,
     * con su id como clave, como la lectura de varias funciones. Las que no existen o se dieron de
     * baja no vienen. Un id que no es UUID es un error de moderación, que los saca de sus
     * solicitudes: 400.
     */
    public function varias(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('ids');
        $ids = is_string($lista) && $lista !== '' ? array_values(array_unique(explode(',', $lista))) : [];
        if ($ids === [] || count($ids) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden entre 1 y ' . self::MAXIMO_POR_PEDIDO . ' salas por vez.'], 400);
        }
        if (array_filter($ids, static fn (string $id): bool => !Uuid::esValido($id)) !== []) {
            return $this->json(['error' => 'Los ids de las salas son UUID.'], 400);
        }

        // Un objeto aunque esté vacío: json_encode() escribiría [] como lista.
        return $this->json((object) array_map(self::datos(...), $this->salas->varias($ids)));
    }

    /**
     * POST /interno/salas/{id}/habilitacion con {habilitada, motivo, moderador_id}: publica la sala
     * o la suspende. Una suspendida deja de verse, y sus funciones en la cartelera, y no programa
     * funciones nuevas. El motivo y el moderador quedan en el log; el historial es de moderación.
     *
     * @throws ExcepcionHttp 404 si la sala no existe o se dio de baja
     */
    public function habilitacion(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }
        $habilitada = $peticion->campo('habilitada');
        $motivo = $peticion->campo('motivo');
        $moderador = $peticion->campo('moderador_id');
        if (!is_bool($habilitada)) {
            return $this->json(['error' => '«habilitada» es true o false.'], 400);
        }
        if (!is_string($motivo) || trim($motivo) === '') {
            return $this->json(['error' => 'Falta el motivo.'], 400);
        }
        if (!is_string($moderador) || !Uuid::esValido($moderador)) {
            return $this->json(['error' => '«moderador_id» es el UUID del moderador.'], 400);
        }

        $sala = $this->salas->cambiarHabilitacion($id, $habilitada, $moderador, trim($motivo));

        return $this->json(['id' => $sala->id, 'habilitada' => $sala->habilitada]);
    }

    /**
     * Lo que moderación muestra de una sala en su bandeja.
     *
     * @return array{nombre: string, localidad: string, organizador_id: string, habilitada: bool}
     */
    private static function datos(Sala $sala): array
    {
        return [
            'nombre' => $sala->datos->nombre,
            'localidad' => $sala->datos->localidad,
            'organizador_id' => $sala->organizadorId,
            'habilitada' => $sala->habilitada,
        ];
    }
}
