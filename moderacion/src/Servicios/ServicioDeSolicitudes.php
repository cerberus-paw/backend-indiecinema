<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\DatosDeSala;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Modelo\ImagenSubida;
use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSolicitudes;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use IndieCinema\Nucleo\Correo\CorreoNoEnviado;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La solicitud de sala, como el recorrido de la E2 (sección 7), ya en moderación (EF-07.2): un
 * usuario pide abrir una sala, el equipo recibe el aviso por correo y el administrador la aprueba o
 * la rechaza con un motivo. La solicitud guarda la sala que propone; programación no se entera
 * hasta que se aprueba.
 */
final class ServicioDeSolicitudes
{
    public function __construct(
        private readonly RepositorioDeSolicitudes $solicitudes,
        private readonly ArchivosDeSolicitudes $archivos,
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
     * Guarda la solicitud con la sala que propone y le avisa al equipo. Si la última que mandó la
     * rechazaron, es la misma sala corregida: una solicitud nueva con el mismo id de sala, y sin
     * foto nueva, la de la anterior. Una sala nueva lleva un id nuevo, con el que programación la
     * va a crear al aprobarla. Si el correo falla, la solicitud queda igual: el administrador la ve
     * en la bandeja, y el correo es sólo el aviso. El motivo queda en el log.
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
        $corregida = $ultima?->estado === EstadoSolicitud::Rechazada ? $ultima : null;
        if ($corregida === null && $imagen === null) {
            throw new LogicException('Una sala nueva necesita su foto: el formulario la pide.');
        }

        $fotoNueva = $imagen === null ? null : $this->archivos->guardar($imagen);
        [$salaId, $fotoAnterior] = $corregida === null ? [Uuid::nuevo(), null] : [$corregida->salaId, $corregida->imagen];
        $solicitud = new SolicitudDeSala(
            Uuid::nuevo(),
            $salaId,
            $usuario->id,
            $usuario->nombre,
            $datos,
            $fotoNueva ?? $fotoAnterior,
            EstadoSolicitud::Pendiente,
            self::ahora(),
        );
        try {
            $this->solicitudes->agregar($solicitud);
        } catch (Throwable $error) {
            // Que no quede en el volumen una foto que nadie usa.
            if ($fotoNueva !== null) {
                $this->archivos->borrar($fotoNueva);
            }
            throw $error;
        }
        $this->log->info('El usuario {usuario} pidió abrir la sala {sala} (solicitud {solicitud}).', [
            'usuario' => $usuario->id,
            'sala' => $solicitud->salaId,
            'solicitud' => $solicitud->id,
        ]);
        $this->avisarAlEquipo($solicitud, $usuario, $corregida !== null);

        return $solicitud;
    }

    /**
     * @throws ExcepcionHttp 404 si no existe, también si el id no tiene forma de id
     */
    public function buscar(string $id): SolicitudDeSala
    {
        return (Uuid::esValido($id) ? $this->solicitudes->buscar($id) : null) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * El nombre de la foto de la solicitud, si la puede ver: quien la mandó y el administrador.
     * Para los demás no existe, y una que se mudó de programación no tiene foto propia.
     *
     * @throws ExcepcionHttp 404
     */
    public function fotoParaMostrar(string $id, Usuario $usuario): string
    {
        $solicitud = $this->buscar($id);
        if ($solicitud->imagen === null || (!hash_equals($solicitud->usuarioId, $usuario->id) && !$usuario->alcanza(Rol::Administrador))) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $solicitud->imagen;
    }

    private function avisarAlEquipo(SolicitudDeSala $solicitud, Usuario $usuario, bool $corregida): void
    {
        try {
            $this->correo->enviar(new Correo(
                (string) $this->configuracion->requerir('solicitudes.destino'),
                ($corregida ? 'Solicitud de sala corregida: ' : 'Solicitud de sala: ') . $solicitud->datos->nombre,
                self::texto($solicitud->datos, $usuario, $corregida),
            ));
        } catch (CorreoNoEnviado) {
            $this->log->warning('La solicitud de sala {solicitud} quedó guardada, pero no se le avisó al equipo por correo.', ['solicitud' => $solicitud->id]);
        }
    }

    private static function texto(DatosDeSala $datos, Usuario $usuario, bool $corregida): string
    {
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
            'Se resuelve en «Solicitudes de sala», en la sección de moderación (/moderacion/solicitudes):',
            'aprobarla publica la sala y le da el rol de organizador.',
        ]);
    }

    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
