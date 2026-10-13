<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\DatosDeSolicitud;
use IndieCinema\Cuentas\Modelo\EstadoSolicitud;
use IndieCinema\Cuentas\Modelo\Solicitud;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSolicitudes;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use IndieCinema\Nucleo\Correo\CorreoNoEnviado;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;

/**
 * La solicitud para ser organizador: se guarda, le llega por correo al equipo (a [solicitudes]
 * destino de config.ini, y responder le escribe al contacto que dejó el espectador) y el
 * administrador la resuelve desde su bandeja. Aprobarla le da el rol.
 */
final class ServicioDeSolicitudes
{
    /** Las que muestra la bandeja: se resuelven a medida que llegan, y más no entran en la pantalla. */
    private const EN_LA_BANDEJA = 50;

    public function __construct(
        private readonly RepositorioDeSolicitudes $solicitudes,
        private readonly ServicioDeUsuarios $usuarios,
        private readonly BaseDeDatos $base,
        private readonly ClienteSmtp $correo,
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * La guarda y le avisa al equipo. Si el correo falla, la solicitud queda igual: el
     * administrador la ve en la bandeja, y el correo es sólo el aviso. El motivo queda en el log.
     *
     * @throws SolicitudPendiente si ya tiene una sin resolver
     */
    public function enviar(DatosDeSolicitud $datos, Usuario $usuario): void
    {
        $id = Uuid::nuevo();
        $this->solicitudes->agregar($id, $usuario->id, $datos, self::ahora());
        // Los ids y no los datos de contacto: el log no guarda datos personales.
        $this->log->info('El usuario {usuario} pidió ser organizador (solicitud {solicitud}).', ['usuario' => $usuario->id, 'solicitud' => $id]);

        try {
            $this->correo->enviar(new Correo(
                (string) $this->configuracion->requerir('solicitudes.destino'),
                "Solicitud para ser organizador: {$datos->sala}",
                self::texto($datos, $usuario),
                $datos->correo,
            ));
        } catch (CorreoNoEnviado) {
            $this->log->warning('La solicitud {solicitud} quedó guardada, pero no se le avisó al equipo por correo.', ['solicitud' => $id]);
        }
    }

    /**
     * La última que mandó, para mostrarle en qué quedó, o null si nunca mandó una.
     */
    public function ultimaDe(Usuario $usuario): ?Solicitud
    {
        return $this->solicitudes->ultimaDe($usuario->id);
    }

    /**
     * @return list<Solicitud> las que esperan, primero la que lleva más tiempo
     */
    public function pendientes(): array
    {
        return $this->solicitudes->pendientes(self::EN_LA_BANDEJA);
    }

    public function cuantasPendientes(): int
    {
        return $this->solicitudes->cuantasPendientes();
    }

    /**
     * @throws ExcepcionHttp 404 si no existe, también si el id no tiene forma de id
     */
    public function buscar(string $id): Solicitud
    {
        return (Uuid::esValido($id) ? $this->solicitudes->buscar($id) : null) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * La aprueba y le da el rol de organizador en la misma transacción: no queda una aprobada sin
     * el rol, ni el rol sin la solicitud resuelta. darOrganizador() cierra sus sesiones (tiene el
     * rol en su próximo ingreso) y deja el cambio de rol en el log.
     *
     * @return bool false si ya estaba resuelta: no cambia nada
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function aprobar(string $id, Usuario $administrador, string $motivo): bool
    {
        return $this->base->enTransaccion(function () use ($id, $administrador, $motivo): bool {
            $solicitud = $this->buscar($id);
            if (!$this->resolver($solicitud, EstadoSolicitud::Aprobada, $administrador, $motivo)) {
                return false;
            }
            $this->usuarios->darOrganizador($solicitud->solicitante->id, $administrador);

            return true;
        });
    }

    /**
     * @return bool false si ya estaba resuelta: no cambia nada
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function rechazar(string $id, Usuario $administrador, string $motivo): bool
    {
        return $this->resolver($this->buscar($id), EstadoSolicitud::Rechazada, $administrador, $motivo);
    }

    private function resolver(Solicitud $solicitud, EstadoSolicitud $estado, Usuario $administrador, string $motivo): bool
    {
        if (!$this->solicitudes->resolver($solicitud->id, $estado, $administrador->id, $motivo, self::ahora())) {
            return false;
        }
        $this->log->notice('{administrador} resolvió la solicitud {solicitud}: {estado}.', [
            'administrador' => $administrador->id,
            'solicitud' => $solicitud->id,
            'estado' => $estado->value,
        ]);

        return true;
    }

    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function texto(DatosDeSolicitud $datos, Usuario $usuario): string
    {
        return implode("\n", [
            ($usuario->nombre ?? 'Un espectador') . ' pidió ser organizador para abrir una sala en IndieCinema.',
            '',
            "Sala: {$datos->sala}",
            "Localidad: {$datos->localidad}",
            "Correo de contacto: {$datos->correo}",
            'Teléfono: ' . ($datos->telefono ?? 'no lo dejó'),
            '',
            'Presentación:',
            $datos->presentacion,
            '',
            'Declaró que el espacio está habilitado y se comprometió a tener los derechos de exhibición.',
            '',
            'Se resuelve en «Solicitudes», en el panel del administrador (/cuenta/administracion/solicitudes):',
            'aprobarla le da el rol de organizador. Respondiendo este correo le escribís a su contacto.',
        ]);
    }
}
