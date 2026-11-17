<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Recaudacion\Modelo\ParametroDeReparto;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Servicios\ServicioDeParametrosDeReparto;
use IndieCinema\Recaudacion\Servicios\UsuariosDeCuentas;
use LogicException;

/**
 * «Reparto» del administrador, en /recaudacion/administracion/reparto (EF-08.1 / IC-559): con
 * cuánto se reparte lo cobrado de una función paga entre la sala, el dueño de la obra y la
 * plataforma, desde cuándo rige y el historial de cambios.
 *
 * - GET: el que rige, los que todavía no empezaron y todos los cambios.
 * - POST: carga otro con su vigencia. Un parámetro no se corrige: el anterior queda en el
 *   historial y las funciones anteriores a la vigencia nueva siguen con el suyo.
 *
 * Las dos piden el rol de administrador, declarado en rutas.php.
 */
final class ParametrosDeReparto extends Controlador
{
    private const PLANTILLA = 'administracion_reparto.html.twig';

    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private readonly ServicioDeParametrosDeReparto $parametros,
        private readonly UsuariosDeCuentas $usuarios,
    ) {
    }

    /** GET /recaudacion/administracion/reparto */
    public function mostrar(Peticion $peticion): Respuesta
    {
        self::administrador($peticion);

        return $this->pantalla(guardado: $peticion->consulta('guardado') === '1');
    }

    /** POST /recaudacion/administracion/reparto */
    public function guardar(Peticion $peticion): Respuesta
    {
        $administrador = self::administrador($peticion);
        $valores = [
            'sala' => self::texto($peticion->campo('sala')),
            'dueno' => self::texto($peticion->campo('dueno')),
            'plataforma' => self::texto($peticion->campo('plataforma')),
            'vigente_desde' => self::texto($peticion->campo('vigente_desde')),
        ];

        $sala = ParametroDeReparto::centesimas($valores['sala']);
        $dueno = ParametroDeReparto::centesimas($valores['dueno']);
        $plataforma = ParametroDeReparto::centesimas($valores['plataforma']);
        $errores = [];
        foreach ([
            ['sala', 'de la sala', $valores['sala'], $sala],
            ['dueno', 'del dueño de la obra', $valores['dueno'], $dueno],
            ['plataforma', 'de la plataforma', $valores['plataforma'], $plataforma],
        ] as [$campo, $parte, $escrito, $leido]) {
            if ($escrito === '') {
                $errores[$campo] = "Escribí el porcentaje {$parte}.";
            } elseif ($leido === null) {
                $errores[$campo] = "El porcentaje {$parte} va de 0 a 100, con hasta dos decimales.";
            }
        }

        $vigenteDesde = self::momento($valores['vigente_desde']);
        if ($valores['vigente_desde'] === '') {
            $errores['vigente_desde'] = 'Elegí desde cuándo rige este reparto.';
        } elseif ($vigenteDesde === null) {
            $errores['vigente_desde'] = 'La fecha y la hora de la vigencia no son válidas.';
        }

        // Los tres porcentajes y la vigencia van aparte de $errores para que quede dicho que, si no
        // se pudieron leer, no se sigue: lo que falló ya tiene su mensaje en $errores.
        if ($errores !== [] || $sala === null || $dueno === null || $plataforma === null || $vigenteDesde === null) {
            // Un valor que no es un momento no vuelve al campo: en uno de fecha y hora el HTML no
            // lo acepta (W3C) y el navegador igual lo descarta. El error del campo dice qué pasó.
            // Los porcentajes los deja afuera el parcial del campo, que es numérico.
            if ($vigenteDesde === null) {
                $valores['vigente_desde'] = '';
            }

            return $this->pantalla($valores, $errores, estado: 422);
        }

        try {
            $this->parametros->cargar($sala, $dueno, $plataforma, $vigenteDesde, $administrador->id);
        } catch (ReglaIncumplida $regla) {
            return $this->pantalla($valores, errorGeneral: $regla->getMessage(), estado: 422);
        }

        return $this->redirigir('/administracion/reparto?guardado=1');
    }

    /**
     * @param array<string, string>|null $valores lo que se mandó, para volver a mostrarlo; con null
     *                                            el formulario arranca con el reparto que rige
     * @param array<string, string>      $errores
     */
    private function pantalla(
        ?array $valores = null,
        array $errores = [],
        ?string $errorGeneral = null,
        bool $guardado = false,
        int $estado = 200,
    ): Respuesta {
        $ahora = new DateTimeImmutable();
        $historial = $this->parametros->historial();
        $vigente = null;
        foreach ($historial as $parametro) {
            if ($parametro->rigeEn($ahora)) {
                $vigente = $parametro;
                break;
            }
        }
        $nombres = $this->usuarios->nombresDe(array_values(array_filter(array_map(
            static fn (ParametroDeReparto $parametro): ?string => $parametro->cargadoPor,
            $historial,
        ))));
        $fila = static fn (ParametroDeReparto $parametro): array => self::comoFila($parametro, $ahora, $vigente, $nombres);

        return $this->vista(self::PLANTILLA, [
            'vigente' => $vigente === null ? null : $fila($vigente),
            'historial' => array_map($fila, $historial),
            'valores' => $valores ?? [
                'sala' => $vigente === null ? '' : self::comoCampo($vigente->sala),
                'dueno' => $vigente === null ? '' : self::comoCampo($vigente->dueno),
                'plataforma' => $vigente === null ? '' : self::comoCampo($vigente->plataforma),
                // Desde ahora mismo, que es el cambio más común; el campo lo deja mover.
                'vigente_desde' => $ahora->format('Y-m-d\TH:i'),
            ],
            'errores' => $errores,
            'error_general' => $errorGeneral,
            'guardado' => $guardado,
        ], $estado);
    }

    /**
     * El estado sale de compararlo con el que rige y no sólo con la fecha: todos los que ya
     * empezaron «rigen» por fecha, pero el que vale es el más nuevo, y los anteriores ya no.
     *
     * @param array<string, string> $nombres los de los administradores, por id
     *
     * @return array<string, mixed>
     */
    private static function comoFila(ParametroDeReparto $parametro, DateTimeImmutable $ahora, ?ParametroDeReparto $vigente, array $nombres): array
    {
        return [
            'sala' => ParametroDeReparto::comoTexto($parametro->sala),
            'dueno' => ParametroDeReparto::comoTexto($parametro->dueno),
            'plataforma' => ParametroDeReparto::comoTexto($parametro->plataforma),
            'vigente_desde' => self::fechaYHora($parametro->vigenteDesde),
            'estado' => match (true) {
                $parametro->id === $vigente?->id => 'rige',
                !$parametro->rigeEn($ahora) => 'futuro',
                default => 'anterior',
            },
            // Null en el de la migración. Si cuentas no responde, la pantalla sale igual: «un
            // administrador» en lugar del nombre.
            'cargado_por' => $parametro->cargadoPor === null ? null : ($nombres[$parametro->cargadoPor] ?? 'un administrador'),
            // El de la migración no: lo pone MySQL con su CURRENT_TIMESTAMP, en UTC, y saldría
            // con unas horas de más al lado de los que carga la aplicación en la hora del sitio.
            'cargado_en' => $parametro->cargadoPor === null ? null : self::fechaYHora($parametro->cargadoEn),
        ];
    }

    /** 7050 → «70,5», como se escribe en el campo, que acepta la coma y el punto. */
    private static function comoCampo(int $centesimas): string
    {
        $decimal = ParametroDeReparto::comoDecimal($centesimas);

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /** «16 de noviembre de 2026, 20:00». */
    private static function fechaYHora(DateTimeImmutable $momento): string
    {
        return "{$momento->format('j')} de " . self::MESES[(int) $momento->format('n')] . " de {$momento->format('Y')}, {$momento->format('H:i')}";
    }

    /**
     * Lo que manda un campo de fecha y hora: «2026-11-16T20:00», o con los segundos, que algunos
     * navegadores agregan. Null si no es un momento.
     *
     * Vuelve a escribirlo para compararlo: createFromFormat() con un mes 13 o un 30 de febrero no
     * devuelve false, corre la fecha, y con algo de más al final tampoco (deja un aviso). Así lo
     * que no es exactamente un momento se rechaza, como en Periodo::entre().
     */
    private static function momento(string $texto): ?DateTimeImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $formato) {
            $momento = DateTimeImmutable::createFromFormat('!' . $formato, $texto);
            if ($momento !== false && $momento->format($formato) === $texto) {
                return $momento;
            }
        }

        return null;
    }

    private static function texto(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }
        $limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor);

        return trim($limpio ?? '');
    }

    private static function administrador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('Ruta de administración sin usuario.');
    }
}
