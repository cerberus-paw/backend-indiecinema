<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Programacion\Modelo\DatosDeSala;

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

    /** @var array<string, string> lo que mandó, para volver a mostrarlo si hay errores */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    private ?DatosDeSala $datos = null;

    /**
     * @param array<string, mixed> $campos el cuerpo del POST
     */
    public static function desdeCampos(array $campos): self
    {
        $formulario = new self();
        $formulario->validar($campos);

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
