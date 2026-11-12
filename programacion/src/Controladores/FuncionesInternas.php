<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\ResumenDeFuncion;
use IndieCinema\Programacion\Servicios\ServicioDeFunciones;

/**
 * GET /interno/funciones/{id} y GET /interno/funciones?ids=…: funciones para el subsistema
 * funciones, que no tiene acceso al esquema de programación (E2, sección 4). Sólo las pide
 * funciones, con su token: lo controla la ruta. Desde afuera nginx ni siquiera las deja llegar.
 */
final class FuncionesInternas extends Controlador
{
    /** Una página de «Mis reservas»: un pedido más largo es un error de quien llama. */
    private const MAXIMO_POR_PEDIDO = 20;

    /**
     * Lo más que se piden las próximas: un día de recordatorios entra de sobra. Con más, quedan
     * para la próxima pasada de la tarea, que corre cada hora.
     */
    private const MAXIMAS_PROXIMAS = 500;

    /** Un rango más largo es un error de quien llama: el recordatorio pide un día. */
    private const DIAS_MAXIMOS = 7;

    public function __construct(private readonly ServicioDeFunciones $servicio)
    {
    }

    /**
     * El JSON lleva los nombres de las columnas, en castellano como el resto del código. La fecha
     * va en ISO 8601 sin zona: es la hora de la sala, como la guarda programación.
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->json(self::datos($this->servicio->resumen((string) $peticion->parametro('id'))));
    }

    /**
     * Las de una página de «Mis reservas» (E4-04.1), con su id como clave y los mismos campos que
     * la de a una. Las que no existen o cuya sala se dio de baja no vienen.
     *
     * A diferencia de la ocupación, un id que no es UUID no se ignora: quien llama es funciones, que
     * los saca de sus reservas, y uno mal formado es un error suyo.
     */
    public function varias(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('ids');
        $ids = is_string($lista) && $lista !== '' ? array_values(array_unique(explode(',', $lista))) : [];
        if ($ids === [] || count($ids) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden entre 1 y ' . self::MAXIMO_POR_PEDIDO . ' funciones por vez.'], 400);
        }
        if (array_filter($ids, static fn (string $id): bool => !Uuid::esValido($id)) !== []) {
            return $this->json(['error' => 'Los ids de las funciones son UUID.'], 400);
        }

        // Un objeto aunque esté vacío: json_encode() escribiría [] como lista.
        return $this->json((object) array_map(self::datos(...), $this->servicio->resumenes($ids)));
    }

    /**
     * GET /interno/funciones/proximas?desde=…&hasta=…: las programadas que empiezan en ese rango,
     * de la más próxima a la última, como una lista con los mismos campos que la de a una. Es lo
     * que pide funciones para el recordatorio del día antes (EF-03.3). Las fechas van como las
     * manda la de a una, en ISO 8601 sin zona.
     */
    public function proximas(Peticion $peticion): Respuesta
    {
        $desde = self::fecha($peticion->consulta('desde'));
        $hasta = self::fecha($peticion->consulta('hasta'));
        if ($desde === null || $hasta === null || $hasta <= $desde || $desde->diff($hasta)->days > self::DIAS_MAXIMOS) {
            return $this->json(['error' => 'Se piden «desde» y «hasta» en ISO 8601, en ese orden y con hasta ' . self::DIAS_MAXIMOS . ' días entre las dos.'], 400);
        }

        return $this->json(array_map(self::datos(...), $this->servicio->programadasEntre($desde, $hasta, self::MAXIMAS_PROXIMAS)));
    }

    private static function fecha(mixed $texto): ?DateTimeImmutable
    {
        $fecha = is_string($texto) ? DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s', $texto) : false;

        return $fecha === false ? null : $fecha;
    }

    /**
     * @return array<string, mixed>
     */
    private static function datos(ResumenDeFuncion $funcion): array
    {
        return [
            'id' => $funcion->id,
            'estado' => $funcion->estado->value,
            'fecha_hora' => $funcion->fechaHora?->format('Y-m-d\TH:i:s'),
            'precio_base' => $funcion->precioBase,
            'cupo' => $funcion->cupo,
            'titulos' => $funcion->titulos,
            'titulos_elegidos' => $funcion->titulosElegidos,
            'sala' => [
                'id' => $funcion->salaId,
                'nombre' => $funcion->salaNombre,
                'localidad' => $funcion->salaLocalidad,
                'habilitada' => $funcion->salaHabilitada,
                // Para el recordatorio de la función (EF-03.3).
                'direccion' => $funcion->salaDireccion,
            ],
            'organizador_id' => $funcion->organizadorId,
            // Las mismas que titulos, por id, en el mismo orden (EF-04.2).
            'obras_ids' => $funcion->obrasIds,
        ];
    }
}
