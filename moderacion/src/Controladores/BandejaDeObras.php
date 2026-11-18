<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\ObraDeProgramacion;
use IndieCinema\Moderacion\Servicios\ServicioDeObras;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use LogicException;

/**
 * La pestaña «Obras» de la bandeja de moderación, en /moderacion/obras (EF-07.7): las obras que
 * cargan los organizadores, pendientes en programación, de la más vieja a la más nueva. La elegida
 * (?id=) muestra sus datos, el afiche y quién la cargó, y se aprueba o se rechaza con un motivo
 * obligatorio. Por su id se abre también una que no está pendiente: una aprobada se rechaza (Q16,
 * la denuncia de EF-07.8). «Ver historial» (?ver=historial) lista las resueltas, de la tabla de
 * moderación. Las rutas piden el rol de moderador, que el administrador alcanza.
 */
final class BandejaDeObras extends Controlador
{
    /** Como la columna revision_obra.motivo. */
    private const MAXIMO_MOTIVO = 1000;
    private const RESOLUCIONES = ['aprobar', 'rechazar'];
    private const TIPOS = ['pelicula' => 'Película', 'cortometraje' => 'Cortometraje'];
    private const ORIGENES = ['obra_propia' => 'Obra propia', 'dominio_publico' => 'Dominio público', 'licencia_adquirida' => 'Licencia adquirida'];

    public function __construct(private readonly ServicioDeObras $servicio)
    {
    }

    /**
     * @throws ExcepcionHttp 404 si la obra de ?id= no existe
     */
    public function listar(Peticion $peticion): Respuesta
    {
        return $this->bandeja($peticion, self::elegidaDe($peticion), mensaje: $this->mensaje($peticion));
    }

    /**
     * @throws ExcepcionHttp 403 si es del moderador; 503 si programación no responde
     */
    public function resolver(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $moderador = self::moderador($peticion);
        $obra = $this->servicio->obra($id);
        if ($obra === null || $obra->resoluciones() === []) {
            return $this->redirigir('/obras?' . http_build_query(['ya_resuelta' => $id]));
        }
        // Antes que los errores del formulario: la suya no la resuelve, ni con el motivo mal.
        if (!$this->servicio->laPuedeResolver($obra, $moderador)) {
            throw ExcepcionHttp::prohibida();
        }
        $resolucion = $peticion->campo('resolucion');
        $motivo = self::motivo($peticion->campo('motivo'));
        $errores = [];
        if (!in_array($resolucion, self::RESOLUCIONES, true)) {
            $errores['resolucion'] = 'Elegí si la aprobás o la rechazás.';
        }
        if ($motivo === '') {
            $errores['motivo'] = 'Escribí el motivo: se lo mandamos a su dueño.';
        } elseif (mb_strlen($motivo) > self::MAXIMO_MOTIVO) {
            $errores['motivo'] = 'El motivo puede tener hasta ' . self::MAXIMO_MOTIVO . ' caracteres.';
        }
        if ($errores !== []) {
            return $this->bandeja($peticion, $id, ['resolucion' => is_string($resolucion) ? $resolucion : '', 'motivo' => $motivo], $errores, estado: 422);
        }

        $aprobar = $resolucion === 'aprobar';
        $aviso = match (true) {
            !$this->servicio->resolver($id, $aprobar, $motivo, $moderador) => 'ya_resuelta',
            $aprobar => 'aprobada',
            default => 'rechazada',
        };

        // 303: recargar no la vuelve a resolver, y sigue la próxima.
        return $this->redirigir('/obras?' . http_build_query([$aviso => $id]));
    }

    /**
     * @param array<string, string> $valores lo que mandó, si hubo errores
     * @param array<string, string> $errores campo => qué está mal
     */
    private function bandeja(Peticion $peticion, ?string $elegidaId, array $valores = [], array $errores = [], ?string $mensaje = null, int $estado = 200): Respuesta
    {
        $historial = $elegidaId === null && $peticion->consulta('ver') === 'historial';
        $datos = [
            'historial' => $historial,
            'sin_programacion' => false,
            'obras' => [],
            'resueltas' => [],
            'elegida' => null,
            'valores' => $valores,
            'errores' => $errores,
            'maximo_motivo' => self::MAXIMO_MOTIVO,
        ];
        if ($historial) {
            $datos['resueltas'] = array_map(static fn (array $revision): array => [
                'titulo' => $revision['titulo'],
                'resolucion' => $revision['resolucion'],
                'motivo' => $revision['motivo'],
                'resuelta' => FechaParaMostrar::diaYHora($revision['resuelta_en']),
            ], $this->servicio->historial());
        } else {
            try {
                $pendientes = $this->servicio->pendientes();
            } catch (ExcepcionHttp $error) {
                if ($elegidaId !== null) {
                    throw $error;
                }
                $pendientes = null;
            }
            if ($pendientes === null) {
                $datos['sin_programacion'] = true;
            } else {
                $elegida = $pendientes[0] ?? null;
                if ($elegidaId !== null) {
                    // Una que no está pendiente se abre por su id, para rechazarla (Q16).
                    $elegida = array_values(array_filter($pendientes, static fn (ObraDeProgramacion $obra): bool => $obra->id === $elegidaId))[0]
                        ?? (Uuid::esValido($elegidaId) ? $this->servicio->obra($elegidaId) : null)
                        ?? throw ExcepcionHttp::noEncontrada();
                }
                $datos['obras'] = array_map(static fn (ObraDeProgramacion $obra): array => [
                    'id' => $obra->id,
                    'titulo' => $obra->titulo,
                    'tipo' => self::TIPOS[$obra->tipo] ?? $obra->tipo,
                    'anio' => $obra->anio,
                    'cargada' => self::cargada($obra),
                    'elegida' => $obra->id === $elegida?->id,
                ], $pendientes);
                $datos['elegida'] = $elegida === null ? null : $this->detalle($elegida, self::moderador($peticion));
            }
        }
        // La plantilla muestra el mensaje si la variable existe.
        if ($mensaje !== null) {
            $datos['mensaje_exito'] = $mensaje;
        }

        return $this->vista('administracion_obras.html.twig', $datos, $estado);
    }

    /**
     * @return array<string, mixed>
     */
    private function detalle(ObraDeProgramacion $obra, Usuario $moderador): array
    {
        try {
            $dueno = $this->servicio->dueno($obra);
        } catch (ExcepcionHttp) {
            $dueno = false;
        }

        return [
            'id' => $obra->id,
            'titulo' => $obra->titulo,
            'tipo' => self::TIPOS[$obra->tipo] ?? $obra->tipo,
            'anio' => $obra->anio,
            'director' => $obra->director,
            'duracion' => $obra->duracion,
            'sinopsis' => $obra->sinopsis,
            'generos' => $obra->generos,
            'origen_copia' => self::ORIGENES[$obra->origenCopia] ?? $obra->origenCopia,
            // El afiche lo da programación, que se lo muestra a la moderación aunque esté pendiente.
            'afiche' => $obra->afiche ? "/obras/{$obra->id}/afiche" : null,
            'cargada' => self::cargada($obra),
            // false: cuentas no respondió; null: el usuario ya no existe.
            'dueno' => match (true) {
                $dueno === false => false,
                $dueno === null => null,
                default => ['nombre' => $dueno->nombre, 'correo' => $dueno->correo],
            },
            'propia' => !$this->servicio->laPuedeResolver($obra, $moderador),
            'estado' => $obra->estado,
            'resoluciones' => $obra->resoluciones(),
        ];
    }

    private static function cargada(ObraDeProgramacion $obra): ?string
    {
        return $obra->creadaEn === null ? null : FechaParaMostrar::diaYHora(new DateTimeImmutable($obra->creadaEn, new DateTimeZone(FechaParaMostrar::ZONA)));
    }

    private static function elegidaDe(Peticion $peticion): ?string
    {
        $id = $peticion->consulta('id');

        return $id === null ? null : (is_string($id) ? $id : '');
    }

    /**
     * El aviso de lo que se acaba de hacer. Con el título de la última resolución de esa obra.
     */
    private function mensaje(Peticion $peticion): ?string
    {
        $avisos = [
            'aprobada' => 'Aprobaste «%s»: ya está en el catálogo.',
            'rechazada' => 'Rechazaste «%s». Su dueño ve el motivo y la puede corregir.',
            'ya_resuelta' => 'La obra ya no estaba pendiente: no se cambió nada.',
        ];
        foreach ($avisos as $clave => $texto) {
            $id = $peticion->consulta($clave);
            if (!is_string($id)) {
                continue;
            }
            foreach ($this->servicio->historial() as $revision) {
                if ($revision['obra_id'] === $id) {
                    return sprintf($texto, $revision['titulo']);
                }
            }

            return $clave === 'ya_resuelta' ? $texto : null;
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

    private static function moderador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de moderador: sin sesión no llega acá.');
    }
}
