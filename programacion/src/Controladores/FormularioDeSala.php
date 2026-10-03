<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use finfo;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\ImagenSubida;

/**
 * Valida en el servidor lo que manda el formulario de alta y edición de sala. La validación del
 * navegador no cuenta: cualquiera puede mandar el POST a mano.
 *
 * Los nombres de los campos son los de la plantilla programacion/alta_sala del paquete front.
 */
final class FormularioDeSala
{
    /** Campo => [etiqueta para los mensajes, largo máximo], igual que las columnas de la tabla. */
    private const TEXTOS = [
        'nombre' => ['el nombre', 100],
        'descripcion' => ['la descripción', 2000],
        'direccion' => ['la dirección', 150],
        'localidad' => ['la localidad', 100],
    ];

    /** Campo => [mínimo, máximo, mensaje si no está en el rango]. */
    private const NUMEROS = [
        'capacidad' => [1, 2000, 'La capacidad tiene que ser un número de butacas entre 1 y 2000.'],
        'peliculas_por_funcion' => [1, 10, 'Tienen que ser entre 1 y 10 películas por función.'],
        'duracion_funcion' => [1, 600, 'La duración tiene que estar entre 1 y 600 minutos.'],
        'tiempo_entre_funciones' => [0, 240, 'El intervalo tiene que estar entre 0 y 240 minutos.'],
    ];

    /** Lo que admite php.ini (upload_max_filesize) y dice la ayuda del formulario. */
    private const MAXIMO_IMAGEN = 5 * 1024 * 1024;
    /** Tipo según el contenido => extensión con la que se guarda. */
    private const TIPOS_DE_IMAGEN = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    /** Más que esto no aporta en una foto de la sala y sólo pesa al mostrarla. */
    private const LADO_MAXIMO = 8000;

    /** @var array<string, string> lo que mandó, para volver a mostrarlo si hay errores */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    /** La imagen nueva, si mandó una válida. */
    public private(set) ?ImagenSubida $imagen = null;

    private ?DatosDeSala $datos = null;

    /**
     * @param array<string, mixed>      $campos            el cuerpo del POST
     * @param array<string, mixed>|null $imagen            el archivo del campo imagen, como lo arma PHP
     * @param bool                      $imagenObligatoria en el alta; al editar, sin imagen queda la que estaba
     */
    public static function desdeCampos(array $campos, ?array $imagen = null, bool $imagenObligatoria = false): self
    {
        $formulario = new self();
        $formulario->validar($campos);
        $formulario->validarImagen($imagen, $imagenObligatoria);
        if ($formulario->errores !== []) {
            $formulario->datos = null;
            $formulario->imagen = null;
        }

        return $formulario;
    }

    /**
     * El formulario de edición, con los datos que tiene la sala.
     */
    public static function desdeDatos(DatosDeSala $datos): self
    {
        $formulario = new self();
        $formulario->valores = [
            'nombre' => $datos->nombre,
            'descripcion' => $datos->descripcion,
            'direccion' => $datos->direccion,
            'localidad' => $datos->localidad,
            'capacidad' => (string) $datos->capacidad,
            'peliculas_por_funcion' => (string) $datos->peliculasPorFuncion,
            'duracion_funcion' => (string) $datos->duracionFuncion,
            'tiempo_entre_funciones' => (string) $datos->tiempoEntreFunciones,
        ];
        $formulario->datos = $datos;

        return $formulario;
    }

    /**
     * Los datos validados, o null si hay errores.
     */
    public function datos(): ?DatosDeSala
    {
        return $this->datos;
    }

    /**
     * @param array<string, mixed> $campos
     */
    private function validar(array $campos): void
    {
        $textos = [];
        foreach (self::TEXTOS as $campo => [$etiqueta, $maximo]) {
            $valor = self::texto($campos[$campo] ?? null, $campo === 'descripcion');
            $this->valores[$campo] = $valor;
            if ($valor === '') {
                $this->errores[$campo] = 'Falta ' . $etiqueta . '.';
            } elseif (mb_strlen($valor) > $maximo) {
                $this->errores[$campo] = ucfirst($etiqueta) . " puede tener hasta {$maximo} caracteres.";
            }
            $textos[$campo] = $valor;
        }

        $numeros = [];
        foreach (self::NUMEROS as $campo => [$minimo, $maximo, $mensaje]) {
            $valor = self::texto($campos[$campo] ?? null, false);
            $this->valores[$campo] = $valor;
            $numero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimo, 'max_range' => $maximo]]);
            if ($numero === false) {
                $this->errores[$campo] = $mensaje;
            }
            $numeros[$campo] = (int) $numero;
        }

        if ($this->errores === []) {
            $this->datos = new DatosDeSala(
                $textos['nombre'],
                $textos['descripcion'],
                $textos['direccion'],
                $textos['localidad'],
                $numeros['capacidad'],
                $numeros['peliculas_por_funcion'],
                $numeros['duracion_funcion'],
                $numeros['tiempo_entre_funciones'],
            );
        }
    }

    /**
     * La imagen se juzga por su contenido (finfo lee los primeros bytes), nunca por la extensión
     * ni por el tipo que dice el navegador: los dos los elige quien la manda. Además tiene que
     * poder leerse como imagen, con un tamaño razonable.
     *
     * @param array<string, mixed>|null $archivo
     */
    private function validarImagen(?array $archivo, bool $obligatoria): void
    {
        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            if ($obligatoria) {
                $this->errores['imagen'] = 'Falta la foto de la sala.';
            }

            return;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $this->errores['imagen'] = 'La foto puede pesar hasta 5 MB.';

            return;
        }
        $ruta = $archivo['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($ruta) || !is_file($ruta)) {
            $this->errores['imagen'] = 'No pudimos recibir la foto. Probá de nuevo.';

            return;
        }
        if (filesize($ruta) > self::MAXIMO_IMAGEN) {
            $this->errores['imagen'] = 'La foto puede pesar hasta 5 MB.';

            return;
        }

        $extension = self::TIPOS_DE_IMAGEN[(new finfo(FILEINFO_MIME_TYPE))->file($ruta)] ?? null;
        $medidas = $extension === null ? false : @getimagesize($ruta);
        if ($extension === null || $medidas === false) {
            $this->errores['imagen'] = 'La foto tiene que ser JPG, PNG o WebP.';

            return;
        }
        if ($medidas[0] > self::LADO_MAXIMO || $medidas[1] > self::LADO_MAXIMO) {
            $this->errores['imagen'] = 'La foto puede medir hasta ' . self::LADO_MAXIMO . ' píxeles de lado.';

            return;
        }

        $this->imagen = new ImagenSubida($ruta, $extension);
    }

    /**
     * El texto sin espacios de más y sin caracteres de control, que no se ven y no tienen nada que
     * hacer en estos campos. Un campo que llega como arreglo (nombre[]=…) se toma como vacío.
     */
    private static function texto(mixed $valor, bool $variasLineas): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }
        // En una línea, los saltos y tabulaciones pasan a ser un espacio antes de sacar el resto.
        $valor = $variasLineas ? str_replace("\r\n", "\n", $valor) : (string) preg_replace('/\s+/u', ' ', $valor);

        return trim((string) preg_replace($variasLineas ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', '', $valor));
    }
}
