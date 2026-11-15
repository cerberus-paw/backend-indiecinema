<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use IndieCinema\Moderacion\Modelo\Denuncia;
use IndieCinema\Moderacion\Modelo\Denunciado;
use IndieCinema\Moderacion\Modelo\TipoDenuncia;
use IndieCinema\Moderacion\Servicios\ServicioDeDenuncias;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * La pestaña «Denuncias» de la bandeja de moderación, en /moderacion/denuncias (EF-07.8): lo
 * denunciado con denuncias abiertas, primero lo que lleva más tiempo esperando. La elegida
 * (?tipo=&id=) muestra lo denunciado, como lo da hoy su subsistema, y cada denuncia con quién la
 * hizo; se resuelven todas juntas con un motivo. Las rutas piden el rol de moderador, que el
 * administrador alcanza.
 */
final class BandejaDeDenuncias extends Controlador
{
    /** Como la columna denuncia.motivo. */
    private const MAXIMO_MOTIVO = 1000;
    private const ACCIONES = ['desestimar' => 'Desestimada', 'suspender' => 'Sala suspendida', 'rechazar' => 'Obra rechazada', 'ocultar' => 'Comentario oculto'];

    public function __construct(private readonly ServicioDeDenuncias $servicio)
    {
    }

    /**
     * @throws ExcepcionHttp 404 si lo elegido no tiene denuncias abiertas
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $tipo = TipoDenuncia::tryFrom((string) $peticion->consulta('tipo', ''));
        $id = $peticion->consulta('id');
        $elegida = $tipo !== null && is_string($id) ? [$tipo, $id] : null;
        if ($elegida === null && $peticion->consulta('ver') === 'historial') {
            return $this->historial();
        }

        return $this->bandeja($peticion, $elegida, mensaje: $this->mensaje($peticion));
    }

    /**
     * POST /moderacion/denuncias/{tipo}/{id}/resolver con {accion, motivo}: cierra todas las
     * abiertas de lo denunciado.
     *
     * @throws ExcepcionHttp 404 si el tipo no existe; 403 si es del moderador o denunció él; 503 si
     *                       el subsistema dueño no responde
     */
    public function resolver(Peticion $peticion): Respuesta
    {
        $tipo = TipoDenuncia::tryFrom((string) $peticion->parametro('tipo')) ?? throw ExcepcionHttp::noEncontrada();
        $id = (string) $peticion->parametro('id');
        $moderador = self::moderador($peticion);
        $denuncias = $this->servicio->abiertasDe($tipo, $id);
        if ($denuncias === []) {
            return $this->redirigir('/denuncias?' . http_build_query(['ya_resueltas' => $id]));
        }
        $denunciado = $this->servicio->denunciado($tipo, $id);
        $publicado = $denunciado !== null && $denunciado->publicado ? $denunciado : null;
        // Antes que los errores del formulario: lo suyo no lo resuelve, ni con el motivo mal.
        if (!$this->servicio->lasPuedeResolver($denunciado, $denuncias, $moderador)) {
            throw ExcepcionHttp::prohibida();
        }
        $accion = $peticion->campo('accion');
        $motivo = self::motivo($peticion->campo('motivo'));
        $errores = [];
        if (!is_string($accion) || !in_array($accion, array_column(self::acciones($publicado), 'valor'), true)) {
            $errores['accion'] = 'Elegí qué hacer con las denuncias.';
        }
        if ($motivo === '') {
            $errores['motivo'] = 'Escribí el motivo: queda en el historial.';
        } elseif (mb_strlen($motivo) > self::MAXIMO_MOTIVO) {
            $errores['motivo'] = 'El motivo puede tener hasta ' . self::MAXIMO_MOTIVO . ' caracteres.';
        }
        if ($errores !== [] || !is_string($accion)) {
            return $this->bandeja($peticion, [$tipo, $id], ['accion' => is_string($accion) ? $accion : '', 'motivo' => $motivo], $errores, estado: 422);
        }

        $aviso = match (true) {
            !$this->servicio->resolver($tipo, $id, $publicado, $accion, $motivo, $moderador) => 'ya_resueltas',
            $accion === 'desestimar' => 'desestimadas',
            default => 'resueltas',
        };

        // 303: recargar no las vuelve a resolver, y sigue lo próximo.
        return $this->redirigir('/denuncias?' . http_build_query([$aviso => $id]));
    }

    /**
     * «Ver historial»: las cerradas, primero la última.
     */
    private function historial(): Respuesta
    {
        return $this->vista('administracion_denuncias.html.twig', [
            'historial' => true,
            'grupos' => [],
            'elegida' => null,
            'valores' => [],
            'errores' => [],
            'maximo_motivo' => self::MAXIMO_MOTIVO,
            'cerradas' => array_map(static fn (array $cerrada): array => [
                'tipo_etiqueta' => $cerrada['tipo']->etiqueta(),
                'titulo' => $cerrada['titulo'],
                'categoria' => $cerrada['categoria']->etiqueta(),
                'desestimada' => $cerrada['estado'] === 'desestimada',
                'accion' => self::ACCIONES[$cerrada['accion'] ?? 'desestimar'],
                'motivo' => $cerrada['motivo'],
                'resuelta' => FechaParaMostrar::diaYHora($cerrada['resuelta_en']),
            ], $this->servicio->historial()),
        ]);
    }

    /**
     * El aviso de lo que se acaba de hacer, con el título que quedó en el historial.
     */
    private function mensaje(Peticion $peticion): ?string
    {
        $avisos = [
            'desestimadas' => 'Desestimaste las denuncias de «%s».',
            'resueltas' => 'Resolviste las denuncias de «%s».',
            'ya_resueltas' => 'Ya no había denuncias abiertas: no se cambió nada.',
        ];
        foreach ($avisos as $clave => $texto) {
            $id = $peticion->consulta($clave);
            if (!is_string($id)) {
                continue;
            }
            if ($clave === 'ya_resueltas') {
                return $texto;
            }
            foreach ($this->servicio->historial() as $cerrada) {
                if ($cerrada['objeto_id'] === $id) {
                    return sprintf($texto, $cerrada['titulo']);
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Conserva los renglones y saca los demás caracteres de control.
     */
    private static function motivo(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }

        return trim((string) preg_replace('/(?!\n)\p{Cc}/u', '', str_replace(["\r\n", "\r"], "\n", $valor)));
    }

    /**
     * @param ?array{TipoDenuncia, string} $elegidaPedida
     * @param array<string, string>        $valores       lo que mandó, si hubo errores
     * @param array<string, string>        $errores       campo => qué está mal
     * @param ?string                      $mensaje       el aviso de lo que se acaba de hacer
     *
     * @throws ExcepcionHttp 404 si lo elegido no tiene denuncias abiertas
     */
    private function bandeja(Peticion $peticion, ?array $elegidaPedida, array $valores = [], array $errores = [], ?string $mensaje = null, int $estado = 200): Respuesta
    {
        $abiertas = $this->servicio->abiertas();
        $elegida = $abiertas[0] ?? null;
        if ($elegidaPedida !== null) {
            $elegida = array_values(array_filter($abiertas, static fn (array $grupo): bool => $grupo['tipo'] === $elegidaPedida[0] && $grupo['objeto_id'] === $elegidaPedida[1]))[0]
                ?? throw ExcepcionHttp::noEncontrada();
        }

        $datos = [
            'historial' => false,
            'cerradas' => [],
            'grupos' => array_map(static fn (array $grupo): array => [
                'tipo' => $grupo['tipo']->value,
                'tipo_etiqueta' => $grupo['tipo']->etiqueta(),
                'id' => $grupo['objeto_id'],
                'titulo' => $grupo['titulo'],
                'cantidad' => $grupo['cantidad'],
                'desde' => FechaParaMostrar::diaYHora($grupo['primera']),
                'elegida' => $grupo === $elegida,
            ], $abiertas),
            'elegida' => $elegida === null ? null : $this->detalle($elegida['tipo'], $elegida['objeto_id'], $elegida['titulo'], self::moderador($peticion)),
            'valores' => $valores,
            'errores' => $errores,
            'maximo_motivo' => self::MAXIMO_MOTIVO,
        ];
        // La plantilla muestra el mensaje si la variable existe.
        if ($mensaje !== null) {
            $datos['mensaje_exito'] = $mensaje;
        }

        return $this->vista('administracion_denuncias.html.twig', $datos, $estado);
    }

    /**
     * @return array<string, mixed>
     */
    private function detalle(TipoDenuncia $tipo, string $objetoId, string $titulo, Usuario $moderador): array
    {
        try {
            $denunciado = $this->servicio->denunciado($tipo, $objetoId);
            $consultado = true;
        } catch (ExcepcionHttp) {
            $denunciado = null;
            $consultado = false;
        }
        $denuncias = $this->servicio->abiertasDe($tipo, $objetoId);

        return [
            'tipo' => $tipo->value,
            'tipo_etiqueta' => $tipo->etiqueta(),
            'id' => $objetoId,
            'titulo' => $denunciado->titulo ?? $titulo,
            // false: su subsistema no respondió; null: ya no está publicado.
            'denunciado' => match (true) {
                !$consultado => false,
                $denunciado === null || !$denunciado->publicado => null,
                default => ['url' => $denunciado->url] + $denunciado->datos,
            },
            // Lo que se puede hacer depende de que siga publicado (Q15).
            'denuncias' => array_map(fn (Denuncia $denuncia): array => [
                'categoria' => $denuncia->categoria->etiqueta(),
                'detalle' => $denuncia->detalle,
                'cuando' => FechaParaMostrar::diaYHora($denuncia->creadaEn),
                'quien' => $this->quien($denuncia->usuarioId),
            ], $denuncias),
            'propia' => !$this->servicio->lasPuedeResolver($denunciado, $denuncias, $moderador),
            'acciones' => self::acciones($denunciado !== null && $denunciado->publicado ? $denunciado : null),
        ];
    }

    /**
     * Lo que se puede hacer con lo denunciado, si sigue publicado. Desestimar, siempre.
     *
     * @return list<array{valor: string, etiqueta: string}>
     */
    private static function acciones(?Denunciado $denunciado): array
    {
        return [['valor' => 'desestimar', 'etiqueta' => 'Desestimarlas: no hay nada que hacer']];
    }

    /**
     * @return array{nombre: string, correo: string}|false|null false si cuentas no respondió;
     *                                                          null si el usuario ya no existe
     */
    private function quien(string $usuarioId): array|false|null
    {
        try {
            $usuario = $this->servicio->quien($usuarioId);
        } catch (ExcepcionHttp) {
            return false;
        }

        return $usuario === null ? null : ['nombre' => $usuario->nombre, 'correo' => $usuario->correo];
    }

    private static function moderador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de moderador: sin sesión no llega acá.');
    }
}
