<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\AvisosDeReservas;
use IndieCinema\Funciones\Modelo\Calificacion;
use IndieCinema\Funciones\Servicios\PagosDeReservas;
use IndieCinema\Funciones\Servicios\ServicioDeCalificaciones;
use IndieCinema\Funciones\Servicios\ServicioDeReservas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * La reserva de una función, como el wireframe de la E2 (reserva-pago-comprobante). Con el cobro
 * prendido (X-01), la de una función paga pasa por el paso «Pago»: la reserva queda pendiente y el
 * comprobante muestra el enlace a Mercado Pago. Es un enlace y no una redirección después del POST
 * porque la CSP del sitio (form-action 'self') no deja que un formulario termine en otro sitio.
 * Con el cobro apagado, o a la gorra, se confirma sin cobrar.
 *
 * El formulario está en la ficha de la función, que es de programación, y llega acá por POST con
 * el token CSRF de la cookie del sitio. La página de GET /reservar/{id} es la misma con los
 * errores, y la que se ve si se llega escribiendo la dirección.
 */
final class Reservas extends Controlador
{
    /**
     * La hora del sitio, como en los dos paneles (ReservasDeLaFuncion, ReservasDelAdministrador).
     * Fija y no la del sistema: la base guarda UTC, y una imagen sin date.timezone le mostraría al
     * espectador tres horas más que las que dice su reserva.
     */
    private const ZONA = 'America/Argentina/Buenos_Aires';

    public function __construct(
        private readonly ServicioDeReservas $servicio,
        private readonly AvisosDeReservas $avisos,
        private readonly PagosDeReservas $pagos,
        private readonly LoggerInterface $log,
        private readonly ServicioDeCalificaciones $calificaciones,
    ) {
    }

    /**
     * GET /reservas: «Mis reservas» (E4-04.1), con ?pagina=. Una página que no existe es 404, como
     * en el panel del administrador. Si programación no responde, o responde algo que no son
     * funciones, la lista sale igual, con los códigos y sin los datos de las funciones.
     */
    public function mias(Peticion $peticion): Respuesta
    {
        $espectador = self::usuario($peticion);
        $numero = filter_var($peticion->consulta('pagina', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($numero === false) {
            throw ExcepcionHttp::noEncontrada();
        }
        [$reservas, $total] = $this->servicio->pagina($espectador, $numero);
        $paginas = max(1, (int) ceil($total / ServicioDeReservas::POR_PAGINA));
        if ($numero > $paginas) {
            throw ExcepcionHttp::noEncontrada();
        }
        try {
            $funciones = $this->servicio->funcionesDe($reservas);
            $sinProgramacion = false;
        } catch (ExcepcionHttp) {
            // ClienteInterno ya dejó en el log que programación no respondió.
            $funciones = [];
            $sinProgramacion = true;
        } catch (RuntimeException $error) {
            $this->log->error('Sin las funciones de «Mis reservas»: {mensaje}', ['mensaje' => $error->getMessage()]);
            $funciones = [];
            $sinProgramacion = true;
        }

        // Las funciones que ya calificó, para no ofrecer el botón otra vez (EF-04.2).
        $calificadas = $this->calificaciones->deLasReservas($espectador, $reservas);

        // Por qué se canceló cada una de las canceladas (EF-06.3), en una sola consulta.
        $motivos = $this->servicio->motivosDeCancelacion($reservas);

        return $this->vista('mis_reservas.html.twig', [
            'reservas' => array_map(static function (Reserva $reserva) use ($funciones, $sinProgramacion, $calificadas, $motivos): array {
                $funcion = $funciones[$reserva->funcionId] ?? null;

                return [
                    ...self::datosDeReserva($reserva, $funcion, $motivos[$reserva->id] ?? null),
                    'estado_etiqueta' => $reserva->estado->etiqueta(),
                    // Sin programación no se sabe si empezó: el botón queda en el comprobante.
                    'cancelable' => !$sinProgramacion && ServicioDeReservas::seCancela($reserva, $funcion),
                    // Sin programación tampoco se sabe si terminó: Calificacion lo decide con la función.
                    'calificable' => !isset($calificadas[$reserva->funcionId]) && Calificacion::motivoParaNoCalificar($reserva, $funcion) === null,
                    // Lo que calificó (EF-04.5), en la reserva con la que calificó: si fue con dos
                    // reservas a la misma función, no se repite en las dos filas.
                    'calificacion' => isset($calificadas[$reserva->funcionId]) && $calificadas[$reserva->funcionId]->reservaId === $reserva->id
                        ? ['puntaje' => $calificadas[$reserva->funcionId]->puntaje, 'comentario' => $calificadas[$reserva->funcionId]->comentario]
                        : null,
                    'funcion' => $funcion === null ? null : self::datosDeFuncion($funcion),
                ];
            }, $reservas),
            'sin_programacion' => $sinProgramacion,
            'calificada' => $peticion->consulta('calificada') === '1',
            'paginado' => [
                'numero' => $numero,
                'total_paginas' => $paginas,
                'total' => $total,
                'anterior' => $numero > 1 ? self::urlDePagina($numero - 1) : null,
                'siguiente' => $numero < $paginas ? self::urlDePagina($numero + 1) : null,
                'paginas' => array_map(
                    static fn (int $n): array => ['numero' => $n, 'url' => self::urlDePagina($n), 'actual' => $n === $numero],
                    range(1, $paginas),
                ),
            ],
        ]);
    }

    /**
     * Relativa a la página: así no depende del prefijo de funciones, que pone nginx.
     */
    private static function urlDePagina(int $numero): string
    {
        return $numero > 1 ? "?pagina={$numero}" : '?';
    }

    public function formulario(Peticion $peticion): Respuesta
    {
        $funcion = $this->servicio->funcion((string) $peticion->parametro('id'));

        return $this->pantalla($funcion, ['cantidad' => '1'], usuario: self::usuario($peticion));
    }

    public function reservar(Peticion $peticion): Respuesta
    {
        $funcion = $this->servicio->funcion((string) $peticion->parametro('id'));
        $usuario = self::usuario($peticion);
        $enJson = $peticion->aceptaJson();
        $valor = $peticion->campo('cantidad');
        $valor = is_string($valor) ? trim($valor) : '';

        // Entero y dentro del cupo, como el min y el max del campo: el navegador controla lo mismo.
        // El mensaje nombra el tope por persona y no el cupo: es lo más que se puede pedir, y si
        // pide de más, el servicio le dice cuánto le queda (IC-463).
        $cantidad = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $funcion->cupo]]);
        if ($cantidad === false) {
            $mensaje = 'Ingresá entre 1 y ' . ServicioDeReservas::maximoPorPersona($funcion) . ' lugares.';

            return $enJson
                ? $this->json(['errores' => ['cantidad' => $mensaje], ...$this->lugaresDe($funcion, $usuario)], 422)
                : $this->pantalla($funcion, ['cantidad' => $valor], ['cantidad' => $mensaje], usuario: $usuario, estado: 422);
        }

        // Un error de configuración, antes de guardar nada.
        $this->avisos->comprobarConfiguracion();
        $this->pagos->comprobarConfiguracion();
        try {
            $reserva = $this->servicio->reservar($usuario, $funcion->id, $cantidad);
        } catch (ReglaIncumplida $error) {
            // El mensaje de la regla es para el espectador («Quedan 2 lugares.»), no interno.
            return $enJson
                ? $this->json(['error' => $error->getMessage(), ...$this->lugaresDe($funcion, $usuario)], 422)
                : $this->pantalla($funcion, ['cantidad' => $valor], error: $error->getMessage(), usuario: $usuario, estado: 422);
        }

        // Pendiente de pago: al paso «Pago», en el comprobante. El correo sale recién cuando llega
        // el pago verificado (X-01.4).
        if ($reserva->estado === EstadoReserva::PendientePago) {
            return $enJson
                ? $this->json([
                    'reserva' => self::datosDeReserva($reserva, $funcion),
                    'url' => $this->ruta("/reservas/{$reserva->id}"),
                    ...$this->lugaresDe($funcion),
                ], 201)
                : $this->redirigir("/reservas/{$reserva->id}");
        }

        // 201 con el link al comprobante: lo mismo que la redirección, sin recargar (E4-05.1). El
        // correo es el mismo que el de la página; sale con alTerminar() para no hacer esperar a
        // quien reservó, que con fetch está mirando el número de lugares, no una página nueva.
        if ($enJson) {
            return $this->json([
                'reserva' => self::datosDeReserva($reserva, $funcion),
                'url' => $this->ruta("/reservas/{$reserva->id}"),
                ...$this->lugaresDe($funcion, $usuario),
            ], 201)->alTerminar(fn (): bool => $this->avisos->reservaConfirmada($reserva, $funcion));
        }
        // Ya confirmada: si el correo no sale, la reserva queda igual y el aviso queda en el log.
        $this->avisos->reservaConfirmada($reserva, $funcion);

        // 303: recargar la confirmación no vuelve a reservar.
        return $this->redirigir("/reservas/{$reserva->id}");
    }

    public function confirmada(Peticion $peticion): Respuesta
    {
        [$reserva, $funcion] = $this->servicio->delEspectador(self::usuario($peticion), (string) $peticion->parametro('id'));

        return $this->vista('reserva_confirmada.html.twig', [
            'reserva' => self::datosDeReserva($reserva, $funcion, $this->motivoDe($reserva)),
            'funcion' => $funcion === null ? null : self::datosDeFuncion($funcion),
            // Los tres pasos sólo si esta reserva se cobra; las demás siguen con dos.
            'con_pago' => $reserva->preferenciaId !== null,
            // El aviso al volver de cancelarla: sólo si de verdad quedó cancelada.
            'cancelada' => $peticion->consulta('cancelada') === '1' && $reserva->estado === EstadoReserva::Cancelada,
        ]);
    }

    /**
     * GET /reservas/{id}/regreso: adónde vuelve el espectador desde Mercado Pago (X-01.3). Es el
     * mismo comprobante, con un aviso según en qué quedó la reserva **en la base**.
     *
     * Sólo informa: no cambia nada, y no lee lo que trae la dirección (?status=approved,
     * ?payment_id=…), porque cualquiera la puede escribir a mano. La reserva la confirma sólo el
     * aviso del pago verificado (X-01.4), que suele llegar antes que el espectador; si todavía no
     * llegó, la página lo dice y la reserva sigue pendiente.
     */
    public function regreso(Peticion $peticion): Respuesta
    {
        [$reserva, $funcion] = $this->servicio->delEspectador(self::usuario($peticion), (string) $peticion->parametro('id'));

        return $this->vista('reserva_confirmada.html.twig', [
            'reserva' => self::datosDeReserva($reserva, $funcion, $this->motivoDe($reserva)),
            'funcion' => $funcion === null ? null : self::datosDeFuncion($funcion),
            'con_pago' => $reserva->preferenciaId !== null,
            'regreso' => match ($reserva->estado) {
                EstadoReserva::Confirmada, EstadoReserva::Asistio, EstadoReserva::Ausente => 'acreditado',
                EstadoReserva::PendientePago => $reserva->vencida() ? 'vencido' : 'esperando',
                EstadoReserva::Cancelada => 'cancelada',
            },
        ]);
    }

    /**
     * La pantalla que confirma la cancelación (E4-01.5). Si ya no se puede cancelar es 404, como
     * la de cancelar una función: el comprobante no muestra el botón, así que sólo se llega
     * escribiendo la dirección.
     */
    public function confirmarCancelacion(Peticion $peticion): Respuesta
    {
        [$reserva, $funcion] = $this->servicio->delEspectador(self::usuario($peticion), (string) $peticion->parametro('id'));
        if (!ServicioDeReservas::seCancela($reserva, $funcion)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->pantallaDeCancelacion($reserva, $funcion);
    }

    public function cancelar(Peticion $peticion): Respuesta
    {
        $usuario = self::usuario($peticion);
        $id = (string) $peticion->parametro('id');
        $enJson = $peticion->aceptaJson();
        try {
            $reserva = $this->servicio->cancelar($usuario, $id);
        } catch (ReglaIncumplida $error) {
            // Por ejemplo, empezó la función o registraron la asistencia con la pantalla abierta.
            [$reserva, $funcion] = $this->servicio->delEspectador($usuario, $id);

            return $enJson
                ? $this->json(['error' => $error->getMessage(), 'reserva' => self::datosDeReserva($reserva, $funcion, $this->motivoDe($reserva))], 422)
                : $this->pantallaDeCancelacion($reserva, $funcion, $error->getMessage(), 422);
        }

        // La reserva con los mismos datos que en el 422, sin la función: cancelada ya no se cancela,
        // así que la fecha no cambia «cancelable» y no hace falta otro pedido a programación. Sin
        // los lugares libres: después se va al comprobante, que no los muestra.
        if ($enJson) {
            return $this->json([
                // Sin el motivo: la canceló el espectador, que está mirando la pantalla.
                'reserva' => self::datosDeReserva($reserva, null),
                'url' => $this->ruta("/reservas/{$reserva->id}?cancelada=1"),
            ]);
        }

        // 303: recargar no la vuelve a mandar.
        return $this->redirigir("/reservas/{$reserva->id}?cancelada=1");
    }

    /**
     * Los lugares que quedan después de reservar, para que la ficha actualice el número sin
     * volver a preguntar (E4-05.1), y lo que le queda al espectador según el tope por persona.
     *
     * @return array{lugares_libres: int, lugares_restantes_usuario?: int, maximo_reserva?: int, maximo_por_persona?: int}
     */
    private function lugaresDe(FuncionDeProgramacion $funcion, ?Usuario $usuario = null): array
    {
        $libres = $this->servicio->lugaresLibres($funcion);
        $datos = ['lugares_libres' => $libres];
        if ($usuario !== null) {
            $restantes = $this->servicio->lugaresRestantesPara($funcion, $usuario->id);
            $datos['lugares_restantes_usuario'] = $restantes;
            $datos['maximo_reserva'] = min($libres, $restantes);
            $datos['maximo_por_persona'] = ServicioDeReservas::maximoPorPersona($funcion);
        }

        return $datos;
    }

    private function pantallaDeCancelacion(Reserva $reserva, ?FuncionDeProgramacion $funcion, ?string $error = null, int $estado = 200): Respuesta
    {
        $datos = [
            'reserva' => self::datosDeReserva($reserva, $funcion, $this->motivoDe($reserva)),
            'funcion' => $funcion === null ? null : self::datosDeFuncion($funcion),
        ];
        // La plantilla lo muestra si la variable existe: sólo se pasa cuando hay algo que decir.
        if ($error !== null) {
            $datos['error_general'] = $error;
        }

        return $this->vista('cancelar_reserva.html.twig', $datos, $estado);
    }

    /**
     * El motivo de la cancelación de una sola reserva (EF-06.3). Una consulta más, y sólo si está
     * cancelada: motivosDeCancelacion() no pregunta nada por las demás.
     */
    private function motivoDe(Reserva $reserva): ?string
    {
        return $this->servicio->motivosDeCancelacion([$reserva])[$reserva->id] ?? null;
    }

    /**
     * enlace_de_pago y vence sólo en una pendiente de pago que todavía está a tiempo: después ya
     * no hay qué pagar.
     *
     * motivo y reembolsada_en son de una cancelada (EF-06.3): por qué se canceló —«Venció el plazo
     * para pagar.», «Se canceló la función.»— y cuándo se le devolvió la plata. Así una cancelada
     * no queda en un «Cancelada» pelado, que no dice si el espectador tiene que esperar algo.
     *
     * @param ?string $motivo el del historial, de ServicioDeReservas::motivosDeCancelacion()
     *
     * @return array{id: string, codigo: string, cantidad: int, total: string, estado: string, cancelable: bool, enlace_de_pago: ?string, vence: ?string, motivo: ?string, reembolsada_en: ?string}
     */
    private static function datosDeReserva(Reserva $reserva, ?FuncionDeProgramacion $funcion, ?string $motivo = null): array
    {
        $aTiempo = $reserva->estado === EstadoReserva::PendientePago && !$reserva->vencida();

        return [
            'id' => $reserva->id,
            'codigo' => $reserva->codigo,
            'cantidad' => $reserva->cantidad,
            'total' => TextoParaMostrar::monto((float) $reserva->total),
            'estado' => $reserva->estado->value,
            'cancelable' => ServicioDeReservas::seCancela($reserva, $funcion),
            'enlace_de_pago' => $aTiempo ? $reserva->enlaceDePago : null,
            'vence' => $aTiempo && $reserva->venceEn !== null ? self::hora($reserva->venceEn) : null,
            'motivo' => $reserva->estado === EstadoReserva::Cancelada ? $motivo : null,
            'reembolsada_en' => $reserva->reembolsadaEn === null ? null : self::fecha($reserva->reembolsadaEn),
        ];
    }

    /**
     * «21:15», en la hora del sitio: la base la guarda en UTC.
     */
    private static function hora(DateTimeImmutable $momento): string
    {
        return $momento->setTimezone(new DateTimeZone(self::ZONA))->format('H:i');
    }

    /**
     * «19 sep · 20:30», en la hora del sitio: la base la guarda en UTC.
     */
    private static function fecha(DateTimeImmutable $momento): string
    {
        return TextoParaMostrar::dia($momento->setTimezone(new DateTimeZone(self::ZONA)));
    }

    /**
     * @param array<string, string> $valores
     * @param array<string, string> $errores
     */
    private function pantalla(
        FuncionDeProgramacion $funcion,
        array $valores,
        array $errores = [],
        ?string $error = null,
        ?Usuario $usuario = null,
        int $estado = 200,
    ): Respuesta {
        $motivo = $funcion->motivoParaNoReservar();
        $maximoPorPersona = ServicioDeReservas::maximoPorPersona($funcion);
        $restantesUsuario = $usuario !== null ? $this->servicio->lugaresRestantesPara($funcion, $usuario->id) : $maximoPorPersona;
        $libres = $this->servicio->lugaresLibres($funcion);
        $maximoCampo = min($libres, $restantesUsuario);

        if ($error === null && $motivo === null && $usuario !== null && $restantesUsuario === 0) {
            $motivo = "Ya tenés reservados los {$maximoPorPersona} lugares máximos por persona para esta función.";
        }

        $datos = [
            'funcion' => self::datosDeFuncion($funcion),
            // Los tres pasos sólo si esta función se cobra.
            'con_pago' => $this->pagos->activos() && $funcion->precioBase > 0,
            // Si ya no se puede, la página lo dice en lugar del formulario.
            'motivo' => $motivo,
            'reglas' => [
                'cantidad' => [
                    'minimo' => 1,
                    'maximo' => max(1, $maximoCampo),
                    'maximo_por_persona' => $maximoPorPersona,
                ],
            ],
            'valores' => $valores,
            'errores' => $errores,
        ];
        // La plantilla lo muestra si la variable existe: sólo se pasa cuando hay algo que decir.
        if ($error !== null) {
            $datos['error_general'] = $error;
        }

        return $this->vista('reservar.html.twig', $datos, $estado);
    }

    /**
     * @return array{id: string, titulo: string, url: string, fecha: ?string, precio: string, sala: string, localidad: string}
     */
    private static function datosDeFuncion(FuncionDeProgramacion $funcion): array
    {
        return [
            'id' => $funcion->id,
            'titulo' => $funcion->titulo(),
            // La ficha es de programación: va sin el prefijo de funciones.
            'url' => "/cartelera/{$funcion->id}",
            'fecha' => $funcion->fechaHora === null ? null : TextoParaMostrar::fecha($funcion->fechaHora),
            'precio' => TextoParaMostrar::monto($funcion->precioBase),
            'sala' => $funcion->salaNombre,
            'localidad' => $funcion->salaLocalidad,
        ];
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        // La ruta pide sesión: si llegó acá, hay usuario.
        return $peticion->usuario() ?? throw new LogicException('Ruta de espectador sin usuario.');
    }
}
