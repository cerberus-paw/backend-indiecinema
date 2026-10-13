<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\DatosDeSolicitud;

/**
 * Valida en el servidor la solicitud para ser organizador. La validación del navegador no
 * cuenta: cualquiera puede mandar el POST a mano, o apagarla.
 *
 * Los nombres de los campos son los de la plantilla cuentas/solicitud del paquete front.
 */
final class FormularioDeSolicitud
{
    /**
     * Las reglas de cada campo. La plantilla las recibe para armar los atributos del formulario
     * (required, minlength, maxlength y pattern): así el navegador pide lo mismo que se controla
     * acá, y un límite se cambia en un solo lugar.
     *
     * Los máximos de la sala y la localidad son los de la tabla sala de programación: con la
     * solicitud aprobada, el organizador la carga con los mismos datos.
     */
    public const REGLAS = [
        'sala' => ['requerido' => true, 'maximo' => 100],
        'localidad' => ['requerido' => true, 'maximo' => 100],
        'correo' => ['requerido' => true, 'maximo' => 254],
        'telefono' => ['requerido' => false, 'minimo' => 6, 'maximo' => 20, 'patron' => '\+?[0-9 \-]+'],
        'presentacion' => ['requerido' => true, 'minimo' => 50, 'maximo' => 2000],
    ];

    /** Las casillas del wireframe: sin las dos tildadas no se manda. */
    public const DECLARACIONES = [
        'habilitacion' => 'Tenés que declarar que el espacio está habilitado.',
        'derechos' => 'Tenés que comprometerte a tener los derechos de exhibición.',
    ];

    /** Para los mensajes de error. */
    private const NOMBRES = [
        'sala' => 'el nombre de la sala',
        'localidad' => 'la localidad',
        'correo' => 'el correo de contacto',
        'telefono' => 'el teléfono',
        'presentacion' => 'la presentación',
    ];

    /** Qué decir cuando el formato no es el que se pide. */
    private const FORMATOS = [
        'correo' => 'Escribí un correo válido, como nombre@ejemplo.com.',
        'telefono' => 'El teléfono va con números, espacios o guiones, y puede empezar con +.',
    ];

    /**
     * El correo como lo valida el navegador en un <input type="email">: es la expresión del
     * estándar HTML. filter_var() rechaza algunos que el navegador deja pasar (como «ana@sala»),
     * y con la misma expresión lo que acepta uno lo acepta el otro.
     */
    private const PATRON_CORREO = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/';

    /** @var array<string, string|bool> lo que mandó, para volver a mostrarlo si hay errores */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    private ?DatosDeSolicitud $datos = null;

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
     * Los datos validados, o null si hay errores.
     */
    public function datos(): ?DatosDeSolicitud
    {
        return $this->datos;
    }

    /**
     * @param array<string, mixed> $campos
     */
    private function validar(array $campos): void
    {
        foreach (self::REGLAS as $campo => $reglas) {
            $valor = $campo === 'presentacion' ? self::textoLargo($campos[$campo] ?? null) : self::texto($campos[$campo] ?? null);
            $this->valores[$campo] = $valor;
            $error = self::error($campo, $valor, $reglas);
            if ($error !== null) {
                $this->errores[$campo] = $error;
            }
        }

        foreach (self::DECLARACIONES as $campo => $mensaje) {
            $this->valores[$campo] = ($campos[$campo] ?? null) === '1';
            if (!$this->valores[$campo]) {
                $this->errores[$campo] = $mensaje;
            }
        }

        if ($this->errores === []) {
            $this->datos = new DatosDeSolicitud(
                (string) $this->valores['sala'],
                (string) $this->valores['localidad'],
                (string) $this->valores['correo'],
                $this->valores['telefono'] === '' ? null : (string) $this->valores['telefono'],
                (string) $this->valores['presentacion'],
            );
        }
    }

    /**
     * En el orden en que se fija el navegador: si falta, el largo y después el formato.
     *
     * @param array{requerido: bool, minimo?: int, maximo: int, patron?: string} $reglas
     */
    private static function error(string $campo, string $valor, array $reglas): ?string
    {
        $nombre = self::NOMBRES[$campo];
        if ($valor === '') {
            return $reglas['requerido'] ? ucfirst("falta {$nombre}.") : null;
        }
        $largo = self::largo($valor);
        if (isset($reglas['minimo']) && $largo < $reglas['minimo']) {
            return ucfirst("{$nombre} tiene que tener al menos {$reglas['minimo']} caracteres.");
        }
        if ($largo > $reglas['maximo']) {
            return ucfirst("{$nombre} puede tener hasta {$reglas['maximo']} caracteres.");
        }
        // El patrón, como el atributo pattern, tiene que coincidir con todo el valor.
        $patron = match (true) {
            $campo === 'correo' => self::PATRON_CORREO,
            isset($reglas['patron']) => '/^(?:' . $reglas['patron'] . ')\z/u',
            default => null,
        };
        if ($patron !== null && preg_match($patron, $valor) !== 1) {
            return self::FORMATOS[$campo];
        }

        return null;
    }

    /**
     * El largo como lo mide el navegador para minlength y maxlength: en unidades de UTF-16, así
     * un emoji cuenta dos. Con mb_strlen() el servidor aceptaría textos que el navegador rechaza.
     * Nunca da menos que los caracteres, así que lo que pasa entra en las columnas de la base.
     */
    private static function largo(string $valor): int
    {
        return intdiv(strlen(mb_convert_encoding($valor, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * El texto en una línea, sin espacios de más ni caracteres de control, que no se ven y no
     * tienen nada que hacer en estos campos.
     */
    private static function texto(mixed $valor): string
    {
        return trim((string) preg_replace('/\p{Cc}/u', '', (string) preg_replace('/\s+/u', ' ', self::cadena($valor))));
    }

    /**
     * La presentación conserva sus renglones. El navegador manda cada salto como \r\n pero lo
     * cuenta como un carácter para el maxlength: se cuenta igual acá.
     */
    private static function textoLargo(mixed $valor): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", self::cadena($valor));

        return trim((string) preg_replace('/(?!\n)\p{Cc}/u', '', $texto));
    }

    /**
     * El valor si es texto UTF-8; si no, vacío. Un campo que llega como arreglo (sala[]=…) se
     * toma como vacío.
     */
    private static function cadena(mixed $valor): string
    {
        return is_string($valor) && mb_check_encoding($valor, 'UTF-8') ? $valor : '';
    }
}
