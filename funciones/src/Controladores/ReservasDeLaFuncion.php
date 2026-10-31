<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use DateTimeZone;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\ServicioDePanelDeReservas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * Las reservas de una función para su organizador (E4-03.1, .2 y .5), como la sección «Reservas
 * de la función» del wireframe panel-organizador: los números de arriba, el registro de la
 * asistencia con el código y la tabla, con la descarga en CSV.
 *
 * Que la función sea del organizador lo controla el servicio: la de otro es 404.
 */
final class ReservasDeLaFuncion extends Controlador
{
    /**
     * La hora de las reservas para el organizador: la base las guarda en UTC y las salas están en
     * la Argentina.
     */
    private const ZONA = 'America/Argentina/Buenos_Aires';

    public function __construct(private readonly ServicioDePanelDeReservas $servicio)
    {
    }

    /** GET /organizador/funciones/{id}/reservas */
    public function listar(Peticion $peticion): Respuesta
    {
        $asistencia = $peticion->consulta('asistencia');

        return $this->pantalla($peticion, registrada: is_string($asistencia) ? $asistencia : null);
    }

    /**
     * POST /organizador/funciones/{id}/asistencia, con el código que tipea el organizador o el
     * botón de una fila. Vuelve a la lista con 303 y el código registrado, así recargar no lo
     * vuelve a mandar.
     */
    public function registrarAsistencia(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $codigo = $peticion->campo('codigo');
        try {
            $reserva = $this->servicio->registrarAsistencia(self::usuario($peticion), $id, is_string($codigo) ? $codigo : '');
        } catch (ReglaIncumplida $error) {
            return $this->pantalla($peticion, error: $error->getMessage(), codigo: is_string($codigo) ? $codigo : '', estado: 422);
        }

        return $this->redirigir("/organizador/funciones/{$id}/reservas?asistencia={$reserva->codigo}");
    }

    /** GET /organizador/funciones/{id}/reservas.csv */
    public function descargar(Peticion $peticion): Respuesta
    {
        [$funcion, $reservas] = $this->servicio->deLaFuncion(self::usuario($peticion), (string) $peticion->parametro('id'));

        return (new Respuesta(CsvDeReservas::armar($reservas, new DateTimeZone(self::ZONA))))
            ->conCabecera('Content-Type', 'text/csv; charset=utf-8')
            ->conCabecera('Content-Disposition', "attachment; filename=\"reservas-{$funcion->id}.csv\"");
    }

    private function pantalla(Peticion $peticion, ?string $registrada = null, ?string $error = null, string $codigo = '', int $estado = 200): Respuesta
    {
        [$funcion, $reservas] = $this->servicio->deLaFuncion(self::usuario($peticion), (string) $peticion->parametro('id'));
        $resumen = ServicioDePanelDeReservas::resumen($funcion, $reservas);
        $datos = [
            'funcion' => self::datosDeFuncion($funcion),
            'resumen' => [...$resumen, 'recaudado' => TextoParaMostrar::monto($resumen['recaudado'])],
            'reservas' => array_map(self::fila(...), $reservas),
            // Sólo se registra la asistencia de una función que sigue en pie.
            'registra_asistencia' => $funcion->estado !== 'cancelada',
            'codigo' => $codigo,
            // El aviso al volver de registrar una: sólo si de verdad está entre las de la función.
            'registrada' => in_array($registrada, array_column($reservas, 'codigo'), true) ? $registrada : null,
        ];
        // La plantilla lo muestra si la variable existe: sólo se pasa cuando hay algo que decir.
        if ($error !== null) {
            $datos['error_general'] = $error;
        }

        return $this->vista('reservas_de_la_funcion.html.twig', $datos, $estado);
    }

    /**
     * @return array{id: string, titulo: string, url: string, fecha: ?string, sala: string, estado: string, cupo: int}
     */
    private static function datosDeFuncion(FuncionDeProgramacion $funcion): array
    {
        return [
            'id' => $funcion->id,
            'titulo' => $funcion->titulo(),
            // La ficha es de programación: va sin el prefijo de funciones.
            'url' => "/cartelera/{$funcion->id}",
            'fecha' => $funcion->fechaHora === null ? null : TextoParaMostrar::fecha($funcion->fechaHora),
            'sala' => $funcion->salaNombre,
            'estado' => $funcion->estado,
            'cupo' => $funcion->cupo,
        ];
    }

    /**
     * @return array{id: string, codigo: string, cantidad: int, total: string, estado: string, estado_etiqueta: string, creada: string, registrable: bool}
     */
    private static function fila(Reserva $reserva): array
    {
        return [
            'id' => $reserva->id,
            'codigo' => $reserva->codigo,
            'cantidad' => $reserva->cantidad,
            'total' => TextoParaMostrar::monto((float) $reserva->total),
            'estado' => $reserva->estado->value,
            'estado_etiqueta' => $reserva->estado->etiqueta(),
            'creada' => TextoParaMostrar::fecha($reserva->creadaEn->setTimezone(new DateTimeZone(self::ZONA))),
            'registrable' => $reserva->estado->puedePasarA(EstadoReserva::Asistio),
        ];
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        // La ruta pide sesión de organizador: si llegó acá, hay usuario.
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
