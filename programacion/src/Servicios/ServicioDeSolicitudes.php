<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use IndieCinema\Nucleo\Correo\CorreoNoEnviado;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\EstadoSolicitud;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\SolicitudDeSala;
use IndieCinema\Programacion\Modelo\UsuarioDeCuentas;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeSolicitudes;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La solicitud de sala, como el recorrido de la E2 (sección 7): un usuario pide abrir una sala, el
 * equipo recibe el aviso por correo y el administrador la aprueba o la rechaza con un motivo.
 * Aprobarla habilita la sala y le pide a cuentas el rol de organizador para quien la mandó: pedir
 * ser organizador es pedir una sala.
 */
final class ServicioDeSolicitudes
{
    /** Las que muestra la bandeja: se resuelven a medida que llegan, y más no entran en la pantalla. */
    private const EN_LA_BANDEJA = 50;

    public function __construct(
        private readonly RepositorioDeSolicitudes $solicitudes,
        private readonly RepositorioDeSalas $salas,
        private readonly ArchivosDeSalas $archivos,
        private readonly UsuariosDeCuentas $usuarios,
        private readonly BaseDeDatos $base,
        private readonly ClienteSmtp $correo,
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * La última que mandó, para mostrarle en qué quedó, o null si nunca mandó una.
     */
    public function ultimaDe(Usuario $usuario): ?SolicitudDeSala
    {
        return $this->solicitudes->ultimaDe($usuario->id);
    }

    /**
     * Guarda la sala sin habilitar con su solicitud y le avisa al equipo. Si la última que mandó
     * la rechazaron, es la misma sala corregida: se actualiza y va una solicitud nueva. Si el
     * correo falla, la solicitud queda igual: el administrador la ve en la bandeja, y el correo es
     * sólo el aviso. El motivo queda en el log.
     *
     * @param ?ImagenSubida $imagen obligatoria para una sala nueva; al corregir, sin una queda la
     *                              que tenía
     *
     * @throws SolicitudPendiente si ya tiene una sin resolver
     */
    public function solicitar(Usuario $usuario, DatosDeSala $datos, ?ImagenSubida $imagen): SolicitudDeSala
    {
        $ultima = $this->solicitudes->ultimaDe($usuario->id);
        if ($ultima?->estaPendiente()) {
            throw new SolicitudPendiente('Ya tiene una solicitud de sala sin resolver.');
        }
        $corregida = $ultima?->estado === EstadoSolicitud::Rechazada ? $ultima->sala : null;
        if ($corregida === null && $imagen === null) {
            throw new LogicException('Una sala nueva necesita su foto: el formulario la pide.');
        }

        $imagenNueva = $imagen === null ? null : $this->archivos->guardar($imagen);
        $anterior = $corregida?->imagen;
        $id = Uuid::nuevo();
        try {
            $sala = $this->base->enTransaccion(function () use ($usuario, $datos, $corregida, $imagenNueva, $id): Sala {
                if ($corregida === null) {
                    $sala = Sala::nueva($usuario->id, $datos, (string) $imagenNueva);
                    $this->salas->agregar($sala);
                } else {
                    $sala = $corregida;
                    $sala->cambiarDatos($datos);
                    if ($imagenNueva !== null) {
                        $sala->cambiarImagen($imagenNueva);
                    }
                    $this->salas->guardar($sala);
                }
                $this->solicitudes->agregar($id, $sala->id, $usuario->id, $usuario->nombre, self::ahora());

                return $sala;
            });
        } catch (Throwable $error) {
            // Que no quede en el volumen una foto que nadie usa.
            if ($imagenNueva !== null) {
                $this->archivos->borrar($imagenNueva);
            }
            throw $error;
        }
        // La anterior se borra recién cuando la base apunta a la nueva.
        if ($imagenNueva !== null && $anterior !== null) {
            $this->archivos->borrar($anterior);
        }
        $this->log->info('El usuario {usuario} pidió abrir la sala {sala} (solicitud {solicitud}).', [
            'usuario' => $usuario->id,
            'sala' => $sala->id,
            'solicitud' => $id,
        ]);
        $this->avisarAlEquipo($id, $sala, $usuario, $corregida !== null);

        return $this->buscar($id);
    }

    /**
     * Las que esperan, primero la que lleva más tiempo; o el historial de decisiones, primero la
     * última que se resolvió.
     *
     * @return list<SolicitudDeSala>
     */
    public function lista(bool $pendientes, ?string $texto = null): array
    {
        return $this->solicitudes->lista($pendientes, $texto, self::EN_LA_BANDEJA);
    }

    public function cuantas(bool $pendientes, ?string $texto = null): int
    {
        return $this->solicitudes->cuantas($pendientes, $texto);
    }

    /**
     * Quién la mandó, según cuentas, o null si el usuario ya no existe.
     *
     * @throws ExcepcionHttp 503 si cuentas no responde
     */
    public function solicitante(SolicitudDeSala $solicitud): ?UsuarioDeCuentas
    {
        return $this->usuarios->buscar($solicitud->usuarioId);
    }

    /**
     * Cuántas mandó el usuario en total, contando ésta.
     */
    public function cuantasDe(string $usuarioId): int
    {
        return $this->solicitudes->cuantasDe($usuarioId);
    }

    /**
     * @throws ExcepcionHttp 404 si no existe, también si el id no tiene forma de id o la sala se
     *                       dio de baja
     */
    public function buscar(string $id): SolicitudDeSala
    {
        return (Uuid::esValido($id) ? $this->solicitudes->buscar($id) : null) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * Le da el rol de organizador a quien la mandó y, si cuentas respondió, la aprueba y habilita la
     * sala en una transacción. Primero el rol: si cuentas no responde, no cambia nada y se puede
     * reintentar; al revés, quedaría una sala habilitada cuyo dueño no puede entrar a «Mis salas».
     * Si el rol ya estaba, cuentas no cambia nada.
     *
     * @return bool false si ya estaba resuelta: no cambia nada
     *
     * @throws ExcepcionHttp 404 si no existe; 503 si cuentas no responde
     */
    public function aprobar(string $id, Usuario $administrador, string $motivo): bool
    {
        $solicitud = $this->buscar($id);
        if (!$solicitud->estaPendiente()) {
            return false;
        }
        $this->usuarios->darOrganizador($solicitud->usuarioId, $administrador->id);

        return $this->base->enTransaccion(function () use ($solicitud, $administrador, $motivo): bool {
            if (!$this->resolver($solicitud, EstadoSolicitud::Aprobada, $administrador, $motivo)) {
                return false;
            }
            $solicitud->sala->cambiarHabilitacion(true);
            $this->salas->guardarHabilitacion($solicitud->sala);

            return true;
        });
    }

    /**
     * La sala queda sin habilitar, y quien la mandó ve el motivo para corregirla.
     *
     * @return bool false si ya estaba resuelta: no cambia nada
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function rechazar(string $id, Usuario $administrador, string $motivo): bool
    {
        return $this->resolver($this->buscar($id), EstadoSolicitud::Rechazada, $administrador, $motivo);
    }

    private function resolver(SolicitudDeSala $solicitud, EstadoSolicitud $estado, Usuario $administrador, string $motivo): bool
    {
        if (!$this->solicitudes->resolver($solicitud->id, $estado, $administrador->id, $motivo, self::ahora())) {
            return false;
        }
        // Es el registro de auditoría: quién resolvió qué.
        $this->log->notice('{administrador} resolvió la solicitud de sala {solicitud}: {estado}.', [
            'administrador' => $administrador->id,
            'solicitud' => $solicitud->id,
            'estado' => $estado->value,
        ]);

        return true;
    }

    private function avisarAlEquipo(string $id, Sala $sala, Usuario $usuario, bool $corregida): void
    {
        try {
            $this->correo->enviar(new Correo(
                (string) $this->configuracion->requerir('solicitudes.destino'),
                ($corregida ? 'Solicitud de sala corregida: ' : 'Solicitud de sala: ') . $sala->datos->nombre,
                self::texto($sala, $usuario, $corregida),
            ));
        } catch (CorreoNoEnviado) {
            $this->log->warning('La solicitud de sala {solicitud} quedó guardada, pero no se le avisó al equipo por correo.', ['solicitud' => $id]);
        }
    }

    private static function texto(Sala $sala, Usuario $usuario, bool $corregida): string
    {
        $datos = $sala->datos;

        return implode("\n", [
            ($usuario->nombre ?? 'Un usuario') . ($corregida
                ? ' corrigió la sala que le rechazaron y la volvió a mandar.'
                : ' pidió abrir una sala en IndieCinema.'),
            '',
            "Sala: {$datos->nombre}",
            "Dirección: {$datos->direccion}, {$datos->localidad}",
            "Capacidad: {$datos->capacidad} lugares",
            '',
            'Descripción:',
            $datos->descripcion,
            '',
            'Declaró que el espacio está habilitado y se comprometió a tener los derechos de exhibición.',
            '',
            'Se resuelve en «Solicitudes de sala», en el panel del administrador (/administracion/solicitudes):',
            'aprobarla habilita la sala y le da el rol de organizador.',
        ]);
    }

    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
