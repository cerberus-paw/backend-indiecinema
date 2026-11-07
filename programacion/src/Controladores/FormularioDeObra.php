<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use finfo;
use IndieCinema\Programacion\Modelo\DatosDeObra;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\TipoObra;

/**
 * Valida en el servidor lo que manda el formulario de alta y edición de obra.
 * La validación del navegador no cuenta: cualquiera puede mandar el POST a mano.
 */
final class FormularioDeObra
{
    /** Campo => [etiqueta para los mensajes, largo máximo], igual que las columnas de la tabla. */
    private const TEXTOS = [
        'titulo' => ['el título', 150],
        'director' => ['la dirección', 100],
        'sinopsis' => ['la sinopsis', 2000],
    ];

    /** Mensajes del afiche */
    private const FALTA_EL_AFICHE = 'Falta el afiche de la obra.';
    private const AFICHE_DE_OTRO_TIPO = 'El afiche tiene que ser JPG, PNG o WebP.';

    /** Lo que admite php.ini (upload_max_filesize) y dice la ayuda del formulario. */
    private const MAXIMO_IMAGEN = 5 * 1024 * 1024;
    /** Tipo según el contenido => extensión con la que se guarda. */
    private const TIPOS_DE_IMAGEN = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    /** Más que esto no aporta en un afiche y sólo pesa al mostrarlo. */
    private const LADO_MAXIMO = 8000;

    /** @var array<string, mixed> lo que mandó, para volver a mostrarlo si hay errores */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    /** La imagen nueva, si mandó una válida. */
    public private(set) ?ImagenSubida $imagen = null;

    private ?DatosDeObra $datos = null;

    /**
     * @param array<string, mixed>      $campos             el cuerpo del POST
     * @param array<string, mixed>|null $afiche             el archivo del campo afiche, como lo arma PHP
     * @param list<Genero>              $generosDisponibles géneros registrados en la base
     * @param bool                      $aficheObligatorio  en el alta; al editar, sin afiche queda el que estaba
     */
    public static function desdeCampos(
        array $campos,
        ?array $afiche = null,
        array $generosDisponibles = [],
        bool $aficheObligatorio = false,
    ): self {
        $formulario = new self();
        $formulario->validar($campos, $generosDisponibles);
        $formulario->validarImagen($afiche, $aficheObligatorio);
        if ($formulario->errores !== []) {
            $formulario->datos = null;
            $formulario->imagen = null;
        }

        return $formulario;
    }

    /**
     * El formulario de edición, con los datos que tiene la obra.
     */
    public static function desdeDatos(DatosDeObra $datos): self
    {
        $formulario = new self();
        $formulario->valores = [
            'titulo' => $datos->titulo,
            'director' => $datos->director,
            'sinopsis' => $datos->sinopsis,
            'anio' => (string) $datos->anio,
            'duracion' => (string) $datos->duracionMinutos,
            'tipo' => $datos->tipo->value,
            'origen_copia' => $datos->origenCopia->value,
            'generos' => $datos->generosIds,
        ];
        $formulario->datos = $datos;

        return $formulario;
    }

    /**
     * Las reglas para el navegador, las mismas con las que valida este formulario:
     * campo => las claves que espera el parcial de campo del paquete front.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function reglas(bool $aficheObligatorio): array
    {
        $anioMensaje = 'El año tiene que estar entre ' . Obra::PRIMER_ANIO . ' y el actual.';
        $duracionMensaje = 'La duración tiene que estar entre 1 y 600 minutos.';

        $reglas = [];
        foreach (self::TEXTOS as $campo => [$etiqueta, $maximo]) {
            $reglas[$campo] = [
                'requerido' => true,
                'maximo' => $maximo,
                'mensajes' => [
                    'requerido' => self::faltaTexto($etiqueta),
                    'maximo' => self::textoDemasiadoLargo($etiqueta, $maximo),
                ],
            ];
        }

        $reglas['anio'] = [
            'requerido' => true,
            'valor_minimo' => Obra::PRIMER_ANIO,
            'valor_maximo' => (int) date('Y'),
            'mensajes' => [
                'requerido' => $anioMensaje,
                'valor_minimo' => $anioMensaje,
                'valor_maximo' => $anioMensaje,
                'tipo' => $anioMensaje,
            ],
        ];

        $reglas['duracion'] = [
            'requerido' => true,
            'valor_minimo' => 1,
            'valor_maximo' => 600,
            'mensajes' => [
                'requerido' => $duracionMensaje,
                'valor_minimo' => $duracionMensaje,
                'valor_maximo' => $duracionMensaje,
                'tipo' => $duracionMensaje,
            ],
        ];

        $reglas['tipo'] = [
            'requerido' => true,
            'mensajes' => [
                'requerido' => 'Elegí el tipo de obra.',
            ],
        ];

        $reglas['origen_copia'] = [
            'requerido' => true,
            'mensajes' => [
                'requerido' => 'Elegí el origen de la copia.',
            ],
        ];

        $reglas['generos'] = [
            'requerido' => true,
            'mensajes' => [
                'requerido' => 'Elegí al menos un género.',
            ],
        ];

        $reglas['afiche'] = [
            'requerido' => $aficheObligatorio,
            'aceptar' => implode(',', array_keys(self::TIPOS_DE_IMAGEN)),
            'mensajes' => [
                'requerido' => self::FALTA_EL_AFICHE,
                'aceptar' => self::AFICHE_DE_OTRO_TIPO,
            ],
        ];

        return $reglas;
    }

    private static function faltaTexto(string $etiqueta): string
    {
        return 'Falta ' . $etiqueta . '.';
    }

    private static function textoDemasiadoLargo(string $etiqueta, int $maximo): string
    {
        return ucfirst($etiqueta) . " puede tener hasta {$maximo} caracteres.";
    }

    public function datos(): ?DatosDeObra
    {
        return $this->datos;
    }

    /**
     * @param array<string, mixed> $campos
     * @param list<Genero>         $generosDisponibles
     */
    private function validar(array $campos, array $generosDisponibles): void
    {
        $textos = [];
        foreach (self::TEXTOS as $campo => [$etiqueta, $maximo]) {
            $valor = self::texto($campos[$campo] ?? null, $campo === 'sinopsis');
            $this->valores[$campo] = $valor;
            if ($valor === '') {
                $this->errores[$campo] = self::faltaTexto($etiqueta);
            } elseif (mb_strlen($valor) > $maximo) {
                $this->errores[$campo] = self::textoDemasiadoLargo($etiqueta, $maximo);
            }
            $textos[$campo] = $valor;
        }

        $anioValor = self::texto($campos['anio'] ?? null, false);
        $this->valores['anio'] = $anioValor;
        $anio = filter_var($anioValor, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => Obra::PRIMER_ANIO, 'max_range' => (int) date('Y')],
        ]);
        if ($anio === false) {
            $this->errores['anio'] = 'El año tiene que estar entre ' . Obra::PRIMER_ANIO . ' y el actual.';
        }

        $duracionValor = self::texto($campos['duracion'] ?? ($campos['duracion_minutos'] ?? null), false);
        $this->valores['duracion'] = $duracionValor;
        $duracion = filter_var($duracionValor, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 600],
        ]);
        if ($duracion === false) {
            $this->errores['duracion'] = 'La duración tiene que estar entre 1 y 600 minutos.';
        }

        $tipoValor = self::texto($campos['tipo'] ?? null, false);
        $this->valores['tipo'] = $tipoValor;
        $tipo = TipoObra::tryFrom($tipoValor);
        if ($tipo === null) {
            $this->errores['tipo'] = 'Elegí el tipo de obra.';
        }

        $origenValor = self::texto($campos['origen_copia'] ?? null, false);
        $this->valores['origen_copia'] = $origenValor;
        $origenCopia = OrigenCopia::tryFrom($origenValor);
        if ($origenCopia === null) {
            $this->errores['origen_copia'] = 'Elegí el origen de la copia.';
        }

        $generosRecibidos = $campos['generos'] ?? [];
        if (!is_array($generosRecibidos)) {
            $generosRecibidos = [];
        }
        $generosIds = array_values(array_filter(array_map(
            static fn (mixed $g): string => is_scalar($g) ? trim((string) $g) : '',
            $generosRecibidos,
        ), static fn (string $id): bool => $id !== ''));
        $this->valores['generos'] = $generosIds;

        $idsValidos = array_map(static fn (Genero $g): string => $g->id, $generosDisponibles);
        $nombresValidos = array_map(static fn (Genero $g): string => $g->nombre, $generosDisponibles);

        if ($generosIds === []) {
            $this->errores['generos'] = 'Elegí al menos un género.';
        } else {
            // Convierte nombres a IDs si vinieron como nombres, o valida que sean IDs válidos.
            $generosResueltos = [];
            foreach ($generosIds as $gid) {
                if (in_array($gid, $idsValidos, true)) {
                    $generosResueltos[] = $gid;
                } else {
                    $indice = array_search($gid, $nombresValidos, true);
                    if ($indice !== false) {
                        $generosResueltos[] = $idsValidos[$indice];
                    } else {
                        $this->errores['generos'] = 'Elegí géneros válidos del catálogo.';
                        break;
                    }
                }
            }
            if (!isset($this->errores['generos'])) {
                $generosIds = array_values(array_unique($generosResueltos));
            }
        }

        if ($this->errores === [] && $anio !== false && $duracion !== false && $tipo !== null && $origenCopia !== null && $generosIds !== []) {
            $this->datos = new DatosDeObra(
                $textos['titulo'],
                $textos['sinopsis'],
                (int) $anio,
                $textos['director'],
                (int) $duracion,
                $tipo,
                $origenCopia,
                $generosIds,
            );
        }
    }

    /**
     * Valida el afiche por su contenido real con finfo y getimagesize.
     *
     * @param array<string, mixed>|null $archivo
     */
    private function validarImagen(?array $archivo, bool $obligatoria): void
    {
        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            if ($obligatoria) {
                $this->errores['afiche'] = self::FALTA_EL_AFICHE;
            }

            return;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $this->errores['afiche'] = 'El afiche puede pesar hasta 5 MB.';

            return;
        }
        $ruta = $archivo['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($ruta) || !is_file($ruta)) {
            $this->errores['afiche'] = 'No pudimos recibir el afiche. Probá de nuevo.';

            return;
        }
        if (filesize($ruta) > self::MAXIMO_IMAGEN) {
            $this->errores['afiche'] = 'El afiche puede pesar hasta 5 MB.';

            return;
        }

        $extension = self::TIPOS_DE_IMAGEN[(new finfo(FILEINFO_MIME_TYPE))->file($ruta)] ?? null;
        $medidas = $extension === null ? false : @getimagesize($ruta);
        if ($extension === null || $medidas === false) {
            $this->errores['afiche'] = self::AFICHE_DE_OTRO_TIPO;

            return;
        }
        if ($medidas[0] > self::LADO_MAXIMO || $medidas[1] > self::LADO_MAXIMO) {
            $this->errores['afiche'] = 'El afiche puede medir hasta ' . self::LADO_MAXIMO . ' píxeles de lado.';

            return;
        }

        $this->imagen = new ImagenSubida($ruta, $extension);
    }

    private static function texto(mixed $valor, bool $variasLineas): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }
        $valor = $variasLineas ? str_replace("\r\n", "\n", $valor) : (string) preg_replace('/\s+/u', ' ', $valor);

        return trim((string) preg_replace($variasLineas ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', '', $valor));
    }
}
