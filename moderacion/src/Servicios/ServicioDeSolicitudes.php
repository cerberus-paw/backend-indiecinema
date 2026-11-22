<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\DatosDeSala;
use IndieCinema\Moderacion\Modelo\Devolucion;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\DocumentoSubido;
use IndieCinema\Moderacion\Modelo\EstadoDocumento;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Modelo\ImagenSubida;
use IndieCinema\Moderacion\Modelo\RequisitoDeSala;
use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Moderacion\Modelo\UsuarioDeCuentas;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDocumentos;
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
use RuntimeException;
use Throwable;

/**
 * La solicitud de sala, como el recorrido de la E2 (sección 7), ya en moderación (EF-07.2): un
 * usuario pide abrir una sala, el equipo recibe el aviso por correo y un moderador la aprueba o
 * la rechaza con un motivo. La solicitud guarda la sala que propone; programación no se entera
 * hasta que se aprueba.
 */
final class ServicioDeSolicitudes
{
    /** Las que muestra la bandeja: se resuelven a medida que llegan, y más no entran en la pantalla. */
    private const EN_LA_BANDEJA = 50;

    public function __construct(
        private readonly RepositorioDeSolicitudes $solicitudes,
        private readonly ArchivosDeSolicitudes $archivos,
        private readonly ArchivosDeDocumentos $documentos,
        private readonly RepositorioDeDocumentos $papeles,
        private readonly UsuariosDeCuentas $usuarios,
        private readonly SalasDeProgramacion $salas,
        private readonly ServicioDeSuspensiones $suspensiones,
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
     * va a crear al aprobarla. Si el correo falla, la solicitud queda igual: el moderador la ve
     * en la bandeja, y el correo es sólo el aviso. El motivo queda en el log.
     *
     * El seguro y la habilitación (EF-07.4) se guardan con la solicitud, pendientes, en la misma
     * transacción; si algo falla, los PDF que ya se movieron al volumen se borran. Al corregir, el
     * que no se vuelve a subir apunta al archivo que mandó con la anterior.
     *
     * @param ?ImagenSubida          $imagen     obligatoria para una sala nueva; al corregir, sin una
     *                                           queda la que tenía
     * @param list<DocumentoSubido>  $documentos los que mandó, ya validados
     *
     * @throws SolicitudPendiente si ya tiene una sin resolver (pendiente o en revisión manual)
     */
    public function solicitar(Usuario $usuario, DatosDeSala $datos, ?ImagenSubida $imagen, array $documentos = []): SolicitudDeSala
    {
        $ultima = $this->solicitudes->ultimaDe($usuario->id);
        if ($ultima?->estaAbierta()) {
            throw new SolicitudPendiente('Ya tiene una solicitud de sala sin resolver.');
        }
        $corregida = $ultima?->estado === EstadoSolicitud::Rechazada ? $ultima : null;
        if ($corregida === null && $imagen === null) {
            throw new LogicException('Una sala nueva necesita su foto: el formulario la pide.');
        }

        // Corregir un pedido de cambio de dirección (EF-07.6) sigue siendo un pedido de cambio.
        return $corregida === null
            ? $this->guardar($usuario, Uuid::nuevo(), $datos, $imagen, $documentos, null, false)
            : $this->guardar($usuario, $corregida->salaId, $datos, $imagen, $documentos, $corregida, $corregida->cambio);
    }

    /**
     * Lo que programación tiene de una sala publicada, para pedir el cambio de dirección (EF-07.6):
     * sólo su organizador, y si la sala no tiene otra solicitud abierta. Para los demás, la sala no
     * existe.
     *
     * @return DatosDeSala los datos que tiene ahora
     *
     * @throws ExcepcionHttp 404; 503 si programación no responde
     */
    public function salaParaCambiar(Usuario $usuario, string $salaId): DatosDeSala
    {
        $sala = Uuid::esValido($salaId) ? $this->salas->completa($salaId) : null;
        if ($sala === null || !$sala['habilitada'] || !hash_equals($sala['organizador_id'], $usuario->id) || $this->solicitudes->hayAbiertaDe($salaId)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $sala['datos'];
    }

    /**
     * Guarda el pedido de cambio de dirección de una sala publicada (EF-07.6): una solicitud de esa
     * sala, marcada como cambio, con los PDF del lugar nuevo y, si mandó una, otra foto. La sala
     * sigue publicada con la dirección vieja hasta que se aprueba.
     *
     * @param list<DocumentoSubido> $documentos el seguro y la habilitación, ya validados
     *
     * @throws ExcepcionHttp      404 si no la puede pedir (salaParaCambiar()); 503 si programación
     *                            no responde
     * @throws SolicitudPendiente si la sala ya tiene una abierta
     */
    public function pedirCambio(Usuario $usuario, string $salaId, DatosDeSala $datos, ?ImagenSubida $imagen, array $documentos): SolicitudDeSala
    {
        $this->salaParaCambiar($usuario, $salaId);

        return $this->guardar($usuario, $salaId, $datos, $imagen, $documentos, null, true);
    }

    /**
     * @param list<DocumentoSubido> $documentos
     * @param ?SolicitudDeSala      $corregida  la rechazada que corrige: sin foto o sin un PDF
     *                                          nuevos, quedan los de ella
     */
    private function guardar(Usuario $usuario, string $salaId, DatosDeSala $datos, ?ImagenSubida $imagen, array $documentos, ?SolicitudDeSala $corregida, bool $cambio): SolicitudDeSala
    {
        $fotoNueva = $imagen === null ? null : $this->archivos->guardar($imagen);
        $pdfsNuevos = [];
        $fotoAnterior = $corregida?->imagen;
        $solicitud = new SolicitudDeSala(
            Uuid::nuevo(),
            $salaId,
            $usuario->id,
            $usuario->nombre,
            $datos,
            $fotoNueva ?? $fotoAnterior,
            EstadoSolicitud::Pendiente,
            self::ahora(),
            cambio: $cambio,
        );
        try {
            $papeles = [];
            $anteriores = [];
            foreach ($corregida === null ? [] : $this->papeles->deSolicitud($corregida->id) as $anterior) {
                $anteriores[$anterior->tipo->value] = $anterior->archivo;
            }
            foreach ($documentos as $documento) {
                if ($documento->rutaTemporal === null) {
                    // Al corregir, sin un PDF nuevo queda el que mandó: el mismo archivo.
                    $archivo = $anteriores[$documento->tipo->value] ?? throw new LogicException('Sin un PDF anterior, el formulario pide uno nuevo.');
                } else {
                    $archivo = $this->documentos->guardar($documento->rutaTemporal);
                    $pdfsNuevos[] = $archivo;
                }
                $papeles[] = new Documento(
                    Uuid::nuevo(),
                    $salaId,
                    $solicitud->id,
                    $documento->tipo,
                    $archivo,
                    $documento->venceEn,
                    $solicitud->enviadaEn,
                    EstadoDocumento::Pendiente,
                );
            }
            $this->solicitudes->agregar($solicitud, $papeles);
        } catch (Throwable $error) {
            // Que no queden en el volumen una foto ni unos PDF que nadie usa.
            if ($fotoNueva !== null) {
                $this->archivos->borrar($fotoNueva);
            }
            array_map($this->documentos->borrar(...), $pdfsNuevos);
            throw $error;
        }
        $this->log->info($cambio
            ? 'El usuario {usuario} pidió cambiar la dirección de la sala {sala} (solicitud {solicitud}).'
            : 'El usuario {usuario} pidió abrir la sala {sala} (solicitud {solicitud}).', [
            'usuario' => $usuario->id,
            'sala' => $solicitud->salaId,
            'solicitud' => $solicitud->id,
        ]);
        $this->avisarAlEquipo($solicitud, $usuario, $corregida !== null);

        return $solicitud;
    }

    /**
     * Las abiertas (pendientes y en revisión manual), primero la que lleva más tiempo; o el
     * historial de decisiones, primero la última que se resolvió.
     *
     * @return list<SolicitudDeSala>
     */
    public function lista(bool $abiertas, ?string $texto = null): array
    {
        return $this->solicitudes->lista($abiertas, $texto, self::EN_LA_BANDEJA);
    }

    public function cuantas(bool $abiertas, ?string $texto = null): int
    {
        return $this->solicitudes->cuantas($abiertas, $texto);
    }

    /**
     * Cuántas mandó el usuario en total, contando ésta.
     */
    public function cuantasDe(string $usuarioId): int
    {
        return $this->solicitudes->cuantasDe($usuarioId);
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
     * Le da el rol de organizador a quien la mandó, crea su sala en programación (o completa la que
     * ya existía) y recién ahí la deja aprobada. Si cuentas o programación no responden, la
     * solicitud sigue pendiente y se puede reintentar: el rol que ya estaba no cambia nada, y la
     * sala tiene el id que guardó la solicitud, así que no se crea otra. Al revés, quedaría una
     * aprobada sin sala, o una sala publicada cuyo dueño no puede entrar a «Mis salas». Se aprueba
     * igual una pendiente que una en revisión manual.
     *
     * Aprobarla exige el checklist completo (EF-07.9), que queda registrado con la aprobación. El
     * controlador ya lo validó: acá se vuelve a controlar porque es la regla, no el formulario.
     *
     * @param list<RequisitoDeSala> $tildados
     *
     * @return bool false si ya estaba resuelta: no cambia nada
     *
     * @throws ExcepcionHttp 404 si no existe; 403 si es del moderador; 503 si cuentas o
     *                       programación no responden
     */
    public function aprobar(string $id, Usuario $moderador, string $motivo, array $tildados): bool
    {
        $solicitud = $this->buscar($id);
        $this->controlarQueLaPuedeResolver($solicitud, $moderador);
        if (!$solicitud->estaPorResolver()) {
            return false;
        }
        if (!RequisitoDeSala::completo($tildados) || $this->requisitosVencidos($solicitud) !== []) {
            throw new LogicException('Aprobar una solicitud exige los cuatro puntos del checklist, sin papeles vencidos: el controlador lo controla.');
        }
        $this->usuarios->darOrganizador($solicitud->usuarioId, $moderador->id);
        $this->salas->crearParaSolicitud($solicitud);

        return $this->resolver($solicitud, EstadoSolicitud::Aprobada, $moderador, $motivo, $tildados);
    }

    /**
     * Quien la mandó ve el motivo para corregirla. Programación no se entera: la sala no existe
     * todavía, o, si se mudó con la solicitud, sigue sin publicar.
     *
     * @param list<RequisitoDeSala> $tildados los puntos del checklist que cumplía (EF-07.9)
     *
     * @return bool false si ya estaba resuelta: no cambia nada
     *
     * @throws ExcepcionHttp 404 si no existe; 403 si es del moderador
     */
    public function rechazar(string $id, Usuario $moderador, string $motivo, array $tildados): bool
    {
        $solicitud = $this->buscar($id);
        $this->controlarQueLaPuedeResolver($solicitud, $moderador);

        return $this->resolver($solicitud, EstadoSolicitud::Rechazada, $moderador, $motivo, $tildados);
    }

    /**
     * La pasa a revisión manual (EF-07.6), por ejemplo para coordinar una visita al espacio: sigue
     * abierta, y quien la mandó ve el motivo. Después se aprueba o se rechaza como una pendiente.
     *
     * @param list<RequisitoDeSala> $tildados los puntos del checklist que ya controló (EF-07.9)
     *
     * @return bool false si no estaba pendiente: no cambia nada
     *
     * @throws ExcepcionHttp 404 si no existe; 403 si es del moderador
     */
    public function pasarARevision(string $id, Usuario $moderador, string $motivo, array $tildados): bool
    {
        $solicitud = $this->buscar($id);
        $this->controlarQueLaPuedeResolver($solicitud, $moderador);
        if (!$this->solicitudes->pasarARevision($solicitud->id, $moderador->id, $motivo, self::ahora(), $tildados)) {
            return false;
        }
        $this->registrar($solicitud, EstadoSolicitud::EnRevisionManual, $moderador);
        $this->avisarAlSolicitante($solicitud, EstadoSolicitud::EnRevisionManual, $motivo);

        return true;
    }

    /**
     * Se la devuelve a quien la mandó porque un documento no sirve (EF-22.10): queda devuelta, con
     * los documentos de esos tipos rechazados con el motivo y el checklist que tildó, y tiene
     * plazo_devolucion_dias para completarla, contados desde hoy en Argentina. Sigue siendo la
     * misma solicitud. Se puede marcar un tipo que no tiene (una mudada): lo sube nuevo. Después le
     * llega el correo con qué falta, por qué y hasta cuándo; si falla, la devolución queda igual.
     *
     * @param list<TipoDocumento>   $faltantes al menos uno: el controlador lo controla
     * @param list<RequisitoDeSala> $tildados  los puntos del checklist que controló (EF-07.9)
     *
     * @return bool false si no estaba pendiente ni en revisión manual: no cambia nada
     *
     * @throws ExcepcionHttp 404 si no existe; 403 si es del moderador
     */
    public function devolver(string $id, Usuario $moderador, string $motivo, array $faltantes, array $tildados): bool
    {
        $solicitud = $this->buscar($id);
        $this->controlarQueLaPuedeResolver($solicitud, $moderador);
        if ($faltantes === []) {
            throw new LogicException('Devolver una solicitud exige marcar qué documento falta: el controlador lo controla.');
        }
        $plazo = (int) $this->configuracion->requerir('solicitudes.plazo_devolucion_dias');
        $devolucion = new Devolucion($faltantes, $motivo, $moderador->id, self::ahora(), self::hoy()->modify("+{$plazo} days"));
        if (!$this->solicitudes->devolver($solicitud->id, $devolucion, $tildados)) {
            return false;
        }
        $this->registrar($solicitud, EstadoSolicitud::Devuelta, $moderador);
        $this->avisarAlSolicitante($this->buscar($solicitud->id), EstadoSolicitud::Devuelta, $motivo);

        return true;
    }

    /**
     * Las que esperan al solicitante (EF-22.10), primero la que vence antes.
     *
     * @return list<SolicitudDeSala>
     */
    public function devueltas(): array
    {
        return $this->solicitudes->devueltas();
    }

    /**
     * Lo que tildó cada moderador en cada resolución (EF-07.9), del registro más viejo al más
     * nuevo. Las resueltas antes del checklist no tienen.
     *
     * @return list<array{resolucion: EstadoSolicitud, moderador_id: string, revisado_en: DateTimeImmutable, tildados: list<RequisitoDeSala>}>
     */
    public function revisionesDe(SolicitudDeSala $solicitud): array
    {
        return $this->solicitudes->revisionesDe($solicitud->id);
    }

    /**
     * El nombre de un moderador, según cuentas, o null si ya no existe.
     *
     * @throws ExcepcionHttp 503 si cuentas no responde
     */
    public function nombreDe(string $usuarioId): ?string
    {
        return $this->usuarios->buscar($usuarioId)?->nombre;
    }

    /**
     * Los puntos del checklist cuyo papel está vencido (EF-07.9): no cuentan aunque se tilden, así
     * que no se puede aprobar. Una solicitud sin documentos (las mudadas) no tiene.
     *
     * @return list<RequisitoDeSala>
     */
    public function requisitosVencidos(SolicitudDeSala $solicitud): array
    {
        $hoy = new DateTimeImmutable('today');
        $vencidos = array_map(
            static fn (Documento $documento): TipoDocumento => $documento->tipo,
            array_values(array_filter($this->documentosDe($solicitud), static fn (Documento $documento): bool => $documento->vencidoAl($hoy))),
        );

        return array_values(array_filter(RequisitoDeSala::cases(), static fn (RequisitoDeSala $requisito): bool => in_array($requisito->documento(), $vencidos, true)));
    }

    /**
     * El seguro y la habilitación que mandó con la solicitud (EF-07.4), primero el seguro. Las que
     * se mandaron antes de que se pidieran no tienen.
     *
     * @return list<Documento>
     */
    public function documentosDe(SolicitudDeSala $solicitud): array
    {
        return $this->papeles->deSolicitud($solicitud->id);
    }

    /**
     * Un documento, si lo puede bajar: quien mandó su solicitud (o, en una renovación, el
     * organizador de la sala, según programación) y la moderación. Para los demás no existe.
     *
     * @throws ExcepcionHttp 404; 503 si programación no responde
     */
    public function documentoParaBajar(string $id, Usuario $usuario): Documento
    {
        $documento = Uuid::esValido($id) ? $this->papeles->buscar($id) : null;
        if ($documento === null) {
            throw ExcepcionHttp::noEncontrada();
        }
        if ($usuario->alcanza(Rol::Moderador)) {
            return $documento;
        }
        if ($documento->solicitudId === null) {
            $duenio = $this->salas->datosDe($documento->salaId)['organizador_id'] ?? null;
        } else {
            $duenio = $this->solicitudes->buscar($documento->solicitudId)?->usuarioId;
        }
        if ($duenio === null || !hash_equals($duenio, $usuario->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $documento;
    }

    /**
     * El moderador no resuelve lo suyo (EF-07.3): ni su solicitud ni la sala que salió de ella. La
     * resuelve otro moderador o el administrador, que sí puede.
     */
    public function laPuedeResolver(SolicitudDeSala $solicitud, Usuario $moderador): bool
    {
        return !hash_equals($solicitud->usuarioId, $moderador->id) || $moderador->alcanza(Rol::Administrador);
    }

    /**
     * @throws ExcepcionHttp 403 si es del moderador
     */
    public function controlarQueLaPuedeResolver(SolicitudDeSala $solicitud, Usuario $moderador): void
    {
        if (!$this->laPuedeResolver($solicitud, $moderador)) {
            throw ExcepcionHttp::prohibida();
        }
    }

    /**
     * La dirección y la localidad que tiene ahora la sala de un pedido de cambio abierto (EF-07.6),
     * según programación, para mostrarla al lado de la nueva. null si no es un cambio abierto o si
     * programación ya no la tiene.
     *
     * @return ?array{direccion: string, localidad: string}
     *
     * @throws ExcepcionHttp 503 si programación no responde
     */
    public function direccionActual(SolicitudDeSala $solicitud): ?array
    {
        if (!$solicitud->cambio || !$solicitud->estaAbierta()) {
            return null;
        }
        $datos = $this->salas->completa($solicitud->salaId)['datos'] ?? null;

        return $datos === null ? null : ['direccion' => $datos->direccion, 'localidad' => $datos->localidad];
    }

    /**
     * Cómo está en el sitio la sala de una aprobada, según programación: 'publicada', 'retirada'
     * o 'dada_de_baja'; null si la solicitud no está aprobada, porque su sala no se publicó.
     *
     * @throws ExcepcionHttp 503 si programación no responde
     */
    public function salaEnElSitio(SolicitudDeSala $solicitud): ?string
    {
        return $solicitud->estado === EstadoSolicitud::Aprobada ? $this->salas->enElSitio($solicitud->salaId) : null;
    }

    /**
     * Retira del sitio, o vuelve a publicar, la sala de una solicitud aprobada: sus funciones dejan
     * de verse en la cartelera mientras está retirada. Retirarla es una suspensión con causa
     * «retiro» (EF-07.5), y volver a publicarla la levanta: si la sala tiene otra suspensión
     * activa, sigue apagada.
     *
     * @throws ExcepcionHttp 404 si la solicitud no existe o no está aprobada; 403 si es del
     *                       moderador; 503 si programación no responde
     */
    public function cambiarLaSala(string $id, bool $habilitada, Usuario $moderador, string $motivo): SolicitudDeSala
    {
        $solicitud = $this->buscar($id);
        $this->controlarQueLaPuedeResolver($solicitud, $moderador);
        if ($solicitud->estado !== EstadoSolicitud::Aprobada) {
            throw ExcepcionHttp::noEncontrada();
        }
        if ($habilitada) {
            $this->suspensiones->levantar($solicitud->salaId, CausaSuspension::Retiro, null, $motivo, $moderador->id);
        } else {
            $this->suspensiones->abrir($solicitud->salaId, CausaSuspension::Retiro, null, $motivo, $moderador->id);
        }

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
     * El nombre de la foto de la solicitud, si la puede ver: quien la mandó y el moderador.
     * Para los demás no existe, y una que se mudó de programación no tiene foto propia.
     *
     * @throws ExcepcionHttp 404
     */
    public function fotoParaMostrar(string $id, Usuario $usuario): string
    {
        $solicitud = $this->buscar($id);
        if ($solicitud->imagen === null || (!hash_equals($solicitud->usuarioId, $usuario->id) && !$usuario->alcanza(Rol::Moderador))) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $solicitud->imagen;
    }

    /**
     * @param list<RequisitoDeSala> $tildados
     */
    private function resolver(SolicitudDeSala $solicitud, EstadoSolicitud $estado, Usuario $moderador, string $motivo, array $tildados): bool
    {
        if (!$this->solicitudes->resolver($solicitud->id, $estado, $moderador->id, $motivo, self::ahora(), $tildados)) {
            return false;
        }
        $this->registrar($solicitud, $estado, $moderador);
        $this->avisarAlSolicitante($solicitud, $estado, $motivo);

        return true;
    }

    /**
     * Es el registro de auditoría: quién resolvió qué.
     */
    private function registrar(SolicitudDeSala $solicitud, EstadoSolicitud $estado, Usuario $moderador): void
    {
        $this->log->notice('{moderador} resolvió la solicitud de sala {solicitud}: {estado}.', [
            'moderador' => $moderador->id,
            'solicitud' => $solicitud->id,
            'estado' => $estado->value,
        ]);
    }

    /**
     * El correo a quien la mandó, con el motivo y dónde verla (EF-07.6). Su dirección la da
     * cuentas. Es sólo el aviso: si cuentas no responde o el correo falla, lo que se resolvió queda
     * igual, y el motivo lo ve en /solicitar-sala. Queda una advertencia en el log.
     */
    private function avisarAlSolicitante(SolicitudDeSala $solicitud, EstadoSolicitud $estado, string $motivo): void
    {
        try {
            $usuario = $this->usuarios->buscar($solicitud->usuarioId) ?? throw new RuntimeException('cuentas ya no tiene al usuario.');
            [$asunto, $texto] = self::textoParaElSolicitante($solicitud, $estado, $motivo);
            $this->correo->enviar(new Correo($usuario->correo, $asunto, $texto));
        } catch (Throwable $error) {
            $this->log->warning('La solicitud de sala {solicitud} quedó {estado}, pero no se le avisó a quien la mandó: {mensaje}', [
                'solicitud' => $solicitud->id,
                'estado' => $estado->value,
                'mensaje' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @return array{string, string} el asunto y el texto
     */
    private static function textoParaElSolicitante(SolicitudDeSala $solicitud, EstadoSolicitud $estado, string $motivo): array
    {
        $sala = $solicitud->datos->nombre;
        $cambio = $solicitud->cambio;
        if ($estado === EstadoSolicitud::Devuelta) {
            $devolucion = $solicitud->devolucion ?? throw new LogicException('Una devuelta tiene su devolución.');

            return [$cambio
                ? "Te devolvimos el cambio de dirección de «{$sala}» para que lo completes"
                : "Te devolvimos la solicitud de «{$sala}» para que la completes", implode("\n", [
                ($cambio ? "Revisamos el cambio de dirección de «{$sala}»" : "Revisamos la solicitud de «{$sala}»")
                    . " y te pedimos volver a subir {$devolucion->queFalta()}, por este motivo:",
                $motivo,
                '',
                'Tenés hasta el ' . $devolucion->vence->format('d/m/Y') . ' para ' . ($cambio ? 'completarlo' : 'completarla')
                    . ' en /solicitar-sala: sólo te pedimos esos documentos. Si se pasa la fecha, se cierra y hay que pedir'
                    . ($cambio ? ' el cambio' : ' la sala') . ' de nuevo.',
                ...($cambio ? ['', 'La sala sigue publicada con la dirección de antes.'] : []),
            ])];
        }
        if ($cambio && $estado !== EstadoSolicitud::EnRevisionManual) {
            $aprobado = $estado === EstadoSolicitud::Aprobada;

            return [($aprobado ? 'Aprobamos' : 'Rechazamos') . " el cambio de dirección de «{$sala}»", implode("\n", [
                $aprobado
                    ? "Aprobamos el cambio de dirección de «{$sala}»: la sala ya figura en el sitio con la dirección nueva."
                    : "Rechazamos el cambio de dirección de «{$sala}»: la sala sigue publicada con la dirección de antes.",
                '',
                'Lo que anotó la moderación:',
                $motivo,
                '',
                $aprobado ? 'El pedido sigue a la vista en /solicitar-sala.' : 'Podés corregirlo y volver a mandarlo en /solicitar-sala.',
            ])];
        }

        return match ($estado) {
            EstadoSolicitud::Aprobada => ["Aprobamos tu sala «{$sala}»", implode("\n", [
                "Aprobamos la solicitud de «{$sala}»: la sala ya se ve en IndieCinema y sos organizador.",
                'Desde «Mis salas» le cargás las películas y programás las funciones.',
                '',
                'Lo que anotó la moderación:',
                $motivo,
                '',
                'La solicitud sigue a la vista en /solicitar-sala.',
            ])],
            EstadoSolicitud::EnRevisionManual => ["Tu solicitud de «{$sala}» pasó a revisión manual", implode("\n", [
                "La moderación necesita revisar «{$sala}» más de cerca antes de resolverla:",
                $motivo,
                '',
                'Te escribimos de nuevo cuando la apruebe o la rechace. Mientras tanto la ves en /solicitar-sala.',
            ])],
            EstadoSolicitud::Rechazada => ["Rechazamos la solicitud de «{$sala}»", implode("\n", [
                "Rechazamos la solicitud de «{$sala}», por este motivo:",
                $motivo,
                '',
                'Podés corregirla y volver a mandarla en /solicitar-sala.',
            ])],
            default => throw new LogicException("Una solicitud {$estado->value} no lleva correo."),
        };
    }

    private function avisarAlEquipo(SolicitudDeSala $solicitud, Usuario $usuario, bool $corregida): void
    {
        try {
            $this->correo->enviar(new Correo(
                (string) $this->configuracion->requerir('solicitudes.destino'),
                ($corregida ? 'Solicitud de sala corregida: ' : ($solicitud->cambio ? 'Cambio de dirección: ' : 'Solicitud de sala: ')) . $solicitud->datos->nombre,
                self::texto($solicitud->datos, $usuario, $corregida, $solicitud->cambio),
            ));
        } catch (CorreoNoEnviado) {
            $this->log->warning('La solicitud de sala {solicitud} quedó guardada, pero no se le avisó al equipo por correo.', ['solicitud' => $solicitud->id]);
        }
    }

    private static function texto(DatosDeSala $datos, Usuario $usuario, bool $corregida, bool $cambio): string
    {
        return implode("\n", [
            ($usuario->nombre ?? 'Un usuario') . match (true) {
                $corregida => ' corrigió la sala que le rechazaron y la volvió a mandar.',
                $cambio => ' pidió cambiar la dirección de la sala, que sigue publicada con la de antes.',
                default => ' pidió abrir una sala en IndieCinema.',
            },
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

    /**
     * Hoy en Argentina, el mismo día que usa la tarea que vence las devueltas (EF-22.10).
     */
    public static function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }
}
