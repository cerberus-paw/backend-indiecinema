<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\FuncionFinalizada;
use IndieCinema\Programacion\Modelo\ObraVotada;
use IndieCinema\Programacion\Modelo\ResumenDeFuncion;
use IndieCinema\Programacion\Servicios\ServicioDeFunciones;

/**
 * GET /interno/funciones/{id} y GET /interno/funciones?ids=…: funciones para el subsistema
 * funciones, que no tiene acceso al esquema de programación (E2, sección 4). Sólo las pide
 * funciones, con su token: lo controla la ruta. Desde afuera nginx ni siquiera las deja llegar.
 * Las finalizadas de un período son para recaudación, que hace los reportes (EF-09).
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

    /**
     * Las finalizadas de un período: más de lo que hace un organizador en un año. Si hay más,
     * vienen las primeras y «completas» en false, para que la reportería lo avise.
     */
    private const MAXIMAS_FINALIZADAS = 2000;

    /** Un año y un día: el período más largo que pide la reportería. */
    private const DIAS_MAXIMOS_DE_UN_PERIODO = 366;

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

    /**
     * GET /interno/funciones/finalizadas?desde=…&hasta=…, para la reportería de recaudación
     * (EF-09): las finalizadas que empezaron desde el primer día hasta el último, los dos incluidos,
     * en AAAA-MM-DD. Con ?organizador=, sólo las de sus salas; con ?dueno=, las que proyectaron
     * alguna obra suya; sin ninguno, todas (el administrador). Cada una trae sus obras con los
     * votos, también las que perdieron, y quiénes votaron.
     */
    public function finalizadas(Peticion $peticion): Respuesta
    {
        $desde = self::dia($peticion->consulta('desde'));
        $hasta = self::dia($peticion->consulta('hasta'));
        if ($desde === null || $hasta === null || $hasta < $desde || $desde->diff($hasta)->days > self::DIAS_MAXIMOS_DE_UN_PERIODO) {
            return $this->json(['error' => 'Se piden «desde» y «hasta» en AAAA-MM-DD, en ese orden y con hasta ' . self::DIAS_MAXIMOS_DE_UN_PERIODO . ' días entre los dos.'], 400);
        }
        $organizador = $peticion->consulta('organizador');
        $dueno = $peticion->consulta('dueno');
        foreach ([$organizador, $dueno] as $id) {
            if ($id !== null && (!is_string($id) || !Uuid::esValido($id))) {
                return $this->json(['error' => 'El organizador y el dueño van por su id, que es un UUID.'], 400);
            }
        }

        // Una de más, para saber si quedaron afuera.
        $funciones = $this->servicio->finalizadasEntre(
            $desde,
            $hasta->modify('+1 day'),
            is_string($organizador) ? $organizador : null,
            is_string($dueno) ? $dueno : null,
            self::MAXIMAS_FINALIZADAS + 1,
        );

        return $this->json([
            'funciones' => array_map(static fn (FuncionFinalizada $funcion): array => [
                'id' => $funcion->id,
                'fecha_hora' => $funcion->fechaHora->format('Y-m-d\TH:i:s'),
                'cupo' => $funcion->cupo,
                'precio_base' => $funcion->precioBase,
                'sala' => ['id' => $funcion->salaId, 'nombre' => $funcion->salaNombre],
                'organizador_id' => $funcion->organizadorId,
                'obras' => array_map(static fn (ObraVotada $obra): array => [
                    'id' => $obra->id,
                    'titulo' => $obra->titulo,
                    'dueno_id' => $obra->duenoId,
                    'elegida' => $obra->elegida,
                    'votos' => $obra->votos,
                ], $funcion->obras),
                'votantes_ids' => $funcion->votantesIds,
            ], array_slice($funciones, 0, self::MAXIMAS_FINALIZADAS)),
            'completas' => count($funciones) <= self::MAXIMAS_FINALIZADAS,
        ]);
    }

    private static function dia(mixed $texto): ?DateTimeImmutable
    {
        $dia = is_string($texto) ? DateTimeImmutable::createFromFormat('!Y-m-d', $texto) : false;

        // createFromFormat() acepta el 31 de febrero y lo pasa a marzo: así no.
        return $dia === false || $dia->format('Y-m-d') !== $texto ? null : $dia;
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
