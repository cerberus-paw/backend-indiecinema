<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use DateTimeZone;
use IndieCinema\Funciones\Modelo\Conducta;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\ServicioDePanelDeReservas;
use IndieCinema\Funciones\Servicios\UsuariosDeCuentas;
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

    public function __construct(
        private readonly ServicioDePanelDeReservas $servicio,
        private readonly UsuariosDeCuentas $usuarios,
    ) {
    }

    /** GET /organizador/funciones/{id}/reservas */
    public function listar(Peticion $peticion): Respuesta
    {
        $asistencia = $peticion->consulta('asistencia');
        $conducta = $peticion->consulta('conducta');

        return $this->pantalla(
            $peticion,
            registrada: is_string($asistencia) ? $asistencia : null,
            conductaCalificada: is_string($conducta) ? $conducta : null,
        );
    }

    /**
     * POST /organizador/funciones/{id}/asistencia, con el código que tipea el organizador o el
     * botón de una fila. Vuelve a la lista con 303 y el código registrado, así recargar no lo
     * vuelve a mandar.
     *
     * En JSON contesta la fila como quedó y el resumen de arriba, para que el panel los cambie
     * sin recargar la lista (E4-05.5). El resumen va también porque registrar una asistencia
     * mueve «Asistentes registrados»: sin él la fila diría una cosa y el número de arriba otra.
     */
    public function registrarAsistencia(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $enJson = $peticion->aceptaJson();
        $codigo = $peticion->campo('codigo');
        try {
            $reserva = $this->servicio->registrarAsistencia(self::usuario($peticion), $id, is_string($codigo) ? $codigo : '');
        } catch (ReglaIncumplida $error) {
            // El mensaje de la regla es para el organizador («No hay ninguna reserva con ese código.»).
            return $enJson
                ? $this->json(['error' => $error->getMessage()], 422)
                : $this->pantalla($peticion, error: $error->getMessage(), codigo: is_string($codigo) ? $codigo : '', estado: 422);
        }

        if ($enJson) {
            [$funcion, $reservas] = $this->servicio->deLaFuncion(self::usuario($peticion), $id);
            $conductas = $this->servicio->conductasDeLaFuncion($funcion->id);
            $nombres = $this->usuarios->nombresDe([$reserva->usuarioId]);
            $resumen = ServicioDePanelDeReservas::resumen($funcion, $reservas);

            return $this->json([
                'reserva' => self::fila($reserva, $nombres[$reserva->usuarioId] ?? null, $conductas[$reserva->id] ?? null, $funcion),
                'resumen' => [...$resumen, 'recaudado' => self::recaudado($funcion, $resumen['recaudado'])],
            ]);
        }

        return $this->redirigir("/organizador/funciones/{$id}/reservas?asistencia={$reserva->codigo}");
    }

    /**
     * POST /organizador/funciones/{id}/conducta: califica positiva o negativamente (con motivo)
     * la conducta de un espectador con asistencia dentro de los 7 días de la función (EF-13.1).
     */
    public function calificarConducta(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $enJson = $peticion->aceptaJson();
        $reservaId = $peticion->campo('reserva_id');
        $positiva = $peticion->campo('positiva');
        $motivo = $peticion->campo('motivo');

        if ($positiva !== '1' && $positiva !== '0') {
            $mensaje = 'Indicá si la conducta fue positiva o negativa.';

            return $enJson
                ? $this->json(['error' => $mensaje], 422)
                : $this->pantalla($peticion, error: $mensaje, tituloError: 'No se pudo calificar la conducta', estado: 422);
        }

        try {
            [$reserva, $conducta] = $this->servicio->calificarConducta(
                self::usuario($peticion),
                $id,
                is_string($reservaId) ? $reservaId : '',
                $positiva === '1',
                is_string($motivo) ? $motivo : null,
            );
        } catch (ReglaIncumplida $error) {
            return $enJson
                ? $this->json(['error' => $error->getMessage()], 422)
                : $this->pantalla($peticion, error: $error->getMessage(), tituloError: 'No se pudo calificar la conducta', estado: 422);
        }

        if ($enJson) {
            [$funcion, $reservas] = $this->servicio->deLaFuncion(self::usuario($peticion), $id);
            $nombres = $this->usuarios->nombresDe([$reserva->usuarioId]);
            $resumen = ServicioDePanelDeReservas::resumen($funcion, $reservas);

            return $this->json([
                'reserva' => self::fila($reserva, $nombres[$reserva->usuarioId] ?? null, $conducta, $funcion),
                'resumen' => [...$resumen, 'recaudado' => TextoParaMostrar::monto($resumen['recaudado'])],
            ]);
        }

        return $this->redirigir("/organizador/funciones/{$id}/reservas?conducta={$reserva->codigo}");
    }

    /** GET /organizador/funciones/{id}/reservas.csv */
    public function descargar(Peticion $peticion): Respuesta
    {
        [$funcion, $reservas] = $this->servicio->deLaFuncion(self::usuario($peticion), (string) $peticion->parametro('id'));
        $nombres = $this->usuarios->nombresDe(array_map(static fn (Reserva $r): string => $r->usuarioId, $reservas));

        return (new Respuesta(CsvDeReservas::armar($reservas, new DateTimeZone(self::ZONA), $nombres)))
            ->conCabecera('Content-Type', 'text/csv; charset=utf-8')
            ->conCabecera('Content-Disposition', "attachment; filename=\"reservas-{$funcion->id}.csv\"");
    }

    private function pantalla(
        Peticion $peticion,
        ?string $registrada = null,
        ?string $error = null,
        string $codigo = '',
        int $estado = 200,
        ?string $conductaCalificada = null,
        ?string $tituloError = null,
    ): Respuesta {
        [$funcion, $reservas] = $this->servicio->deLaFuncion(self::usuario($peticion), (string) $peticion->parametro('id'));
        $nombres = $this->usuarios->nombresDe(array_map(static fn (Reserva $r): string => $r->usuarioId, $reservas));
        $conductas = $this->servicio->conductasDeLaFuncion($funcion->id);
        $resumen = ServicioDePanelDeReservas::resumen($funcion, $reservas);
        $codigos = array_column($reservas, 'codigo');
        $datos = [
            'funcion' => self::datosDeFuncion($funcion),
            'resumen' => [...$resumen, 'recaudado' => self::recaudado($funcion, $resumen['recaudado'])],
            'reservas' => array_map(
                fn (Reserva $r): array => self::fila($r, $nombres[$r->usuarioId] ?? null, $conductas[$r->id] ?? null, $funcion),
                $reservas,
            ),
            // Sólo se registra la asistencia de una función que sigue en pie.
            'registra_asistencia' => $funcion->estado !== 'cancelada',
            'codigo' => $codigo,
            // El aviso al volver de registrar una: sólo si de verdad está entre las de la función.
            'registrada' => in_array($registrada, $codigos, true) ? $registrada : null,
            'conducta_calificada' => in_array($conductaCalificada, $codigos, true) ? $conductaCalificada : null,
        ];
        // La plantilla lo muestra si la variable existe: sólo se pasa cuando hay algo que decir.
        if ($error !== null) {
            $datos['error_general'] = $error;
            if ($tituloError !== null) {
                $datos['titulo_error'] = $tituloError;
            }
        }

        return $this->vista('reservas_de_la_funcion.html.twig', $datos, $estado);
    }

    /**
     * Lo recaudado, escrito para el panel (EF-06.5). «A la gorra» es de la función y no del número:
     * una función que cobra entrada y todavía no cobró nada muestra «$ 0», que no es lo mismo. Con
     * monto() cualquier total en cero se leía «A la gorra», y un panel sin pagos parecía una
     * función a la gorra.
     */
    private static function recaudado(FuncionDeProgramacion $funcion, float $recaudado): string
    {
        return $funcion->precioBase > 0 ? TextoParaMostrar::suma($recaudado) : TextoParaMostrar::A_LA_GORRA;
    }

    /**
     * @return array{id: string, titulo: string, url: string, sala_id: string, fecha: ?string, sala: string, estado: string, cupo: int}
     */
    private static function datosDeFuncion(FuncionDeProgramacion $funcion): array
    {
        return [
            'id' => $funcion->id,
            'titulo' => $funcion->titulo(),
            // La ficha es de programación: va sin el prefijo de funciones.
            'url' => "/cartelera/{$funcion->id}",
            'sala_id' => $funcion->salaId,
            'fecha' => $funcion->fechaHora === null ? null : TextoParaMostrar::fecha($funcion->fechaHora),
            'sala' => $funcion->salaNombre,
            'estado' => $funcion->estado,
            'cupo' => $funcion->cupo,
        ];
    }

    /**
     * @return array{id: string, codigo: string, usuario_id: string, espectador: ?string, cantidad: int, total: string, estado: string, estado_etiqueta: string, creada: string, registrable: bool, conducta: ?array{positiva: bool, etiqueta: string, motivo: ?string}, calificable_conducta: bool}
     */
    private static function fila(
        Reserva $reserva,
        ?string $espectador = null,
        ?Conducta $conducta = null,
        ?FuncionDeProgramacion $funcion = null,
    ): array {
        return [
            'id' => $reserva->id,
            'codigo' => $reserva->codigo,
            'usuario_id' => $reserva->usuarioId,
            'espectador' => $espectador,
            'cantidad' => $reserva->cantidad,
            'total' => TextoParaMostrar::monto((float) $reserva->total),
            'estado' => $reserva->estado->value,
            'estado_etiqueta' => $reserva->estado->etiqueta(),
            'creada' => TextoParaMostrar::fecha($reserva->creadaEn->setTimezone(new DateTimeZone(self::ZONA))),
            'registrable' => $reserva->estado->puedePasarA(EstadoReserva::Asistio),
            'conducta' => $conducta === null ? null : [
                'positiva' => $conducta->positiva,
                'etiqueta' => $conducta->positiva ? 'Positiva' : 'Negativa',
                'motivo' => $conducta->motivo,
            ],
            'calificable_conducta' => $conducta === null
                && $funcion !== null
                && Conducta::motivoParaNoCalificar($reserva, $funcion) === null,
        ];
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        // La ruta pide sesión de organizador: si llegó acá, hay usuario.
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
