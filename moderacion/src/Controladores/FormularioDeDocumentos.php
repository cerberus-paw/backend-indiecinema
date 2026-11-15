<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use DateTimeImmutable;
use finfo;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\DocumentoSubido;
use IndieCinema\Moderacion\Modelo\TipoDocumento;

/**
 * El seguro y la habilitación de la solicitud de sala (EF-07.4): un PDF y su fecha de
 * vencimiento cada uno. El PDF se juzga por su contenido, nunca por la extensión ni por el tipo
 * que dice el navegador, que los elige quien lo manda. El vencimiento tiene que ser posterior a hoy
 * (en la hora del sitio) y no pasar de diez años, que frena un año mal tipeado.
 *
 * Al corregir una solicitud rechazada, el PDF que ya mandó no es obligatorio: si no sube otro,
 * queda ése, con el vencimiento que diga el formulario (que viene cargado con el anterior).
 */
final class FormularioDeDocumentos
{
    /** Tipo => [el campo del archivo, el de la fecha]. «habilitacion» es la casilla de la declaración. */
    public const CAMPOS = [
        'seguro' => ['seguro', 'vence_seguro'],
        'habilitacion' => ['habilitacion_pdf', 'vence_habilitacion'],
    ];

    /** Lo que admite php.ini (upload_max_filesize) por archivo y dice la ayuda del formulario. */
    private const MAXIMO = 5 * 1024 * 1024;
    private const ANIOS_MAXIMOS = 10;

    /** @var array<string, string> las fechas que mandó, para volver a mostrarlas */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    /** @var list<DocumentoSubido> los que mandó bien */
    public private(set) array $documentos = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>                     $campos     el cuerpo del POST
     * @param array<string, array<string, mixed>|null> $archivos   campo => el archivo, como lo arma PHP
     * @param list<TipoDocumento>                      $yaMandados los que mandó con la solicitud que
     *                                                             corrige: no son obligatorios
     */
    public static function desdeCampos(array $campos, array $archivos, array $yaMandados = []): self
    {
        $formulario = new self();
        foreach (TipoDocumento::cases() as $tipo) {
            [$campoArchivo, $campoFecha] = self::CAMPOS[$tipo->value];
            $fecha = $campos[$campoFecha] ?? null;
            $formulario->valores[$campoFecha] = is_string($fecha) ? trim($fecha) : '';
            $yaMandado = in_array($tipo, $yaMandados, true);
            $archivo = $archivos[$campoArchivo] ?? null;
            $nuevo = ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $ruta = $formulario->validarArchivo($tipo, $archivo, obligatorio: !$yaMandado);
            $vence = $formulario->validarFecha($tipo, $formulario->valores[$campoFecha]);
            if ($vence !== null && ($ruta !== null || ($yaMandado && !$nuevo))) {
                $formulario->documentos[] = new DocumentoSubido($tipo, $ruta, $vence);
            }
        }

        return $formulario;
    }

    /**
     * Con los vencimientos de los que mandó la solicitud rechazada, para corregirla.
     *
     * @param list<Documento> $anteriores
     */
    public static function desdeDocumentos(array $anteriores): self
    {
        $formulario = new self();
        foreach ($anteriores as $documento) {
            $formulario->valores[self::CAMPOS[$documento->tipo->value][1]] = $documento->venceEn->format('Y-m-d');
        }

        return $formulario;
    }

    /**
     * Como las de FormularioDeSala, con los mismos mensajes que contesta el servidor.
     *
     * @param list<TipoDocumento> $yaMandados los que no son obligatorios, al corregir
     *
     * @return array<string, array<string, mixed>>
     */
    public static function reglas(array $yaMandados = []): array
    {
        $reglas = [];
        foreach (TipoDocumento::cases() as $tipo) {
            [$campoArchivo, $campoFecha] = self::CAMPOS[$tipo->value];
            $reglas[$campoArchivo] = [
                'requerido' => !in_array($tipo, $yaMandados, true),
                'aceptar' => 'application/pdf,.pdf',
                'mensajes' => ['requerido' => self::faltaElPdf($tipo), 'aceptar' => self::noEsPdf($tipo)],
            ];
            $reglas[$campoFecha] = [
                'requerido' => true,
                'valor_minimo' => self::hoy()->modify('+1 day')->format('Y-m-d'),
                'valor_maximo' => self::limite()->format('Y-m-d'),
                'mensajes' => [
                    'requerido' => self::faltaLaFecha($tipo),
                    'tipo' => self::faltaLaFecha($tipo),
                    'valor_minimo' => self::yaVencio($tipo),
                    'valor_maximo' => self::demasiadoLejos($tipo),
                ],
            ];
        }

        return $reglas;
    }

    /**
     * @param array<string, mixed>|null $archivo
     *
     * @return ?string el temporal, si es un PDF que va
     */
    private function validarArchivo(TipoDocumento $tipo, ?array $archivo, bool $obligatorio): ?string
    {
        $campo = self::CAMPOS[$tipo->value][0];
        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            if ($obligatorio) {
                $this->errores[$campo] = self::faltaElPdf($tipo);
            }

            return null;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $this->errores[$campo] = self::muyPesado($tipo);

            return null;
        }
        $ruta = $archivo['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($ruta) || !is_file($ruta)) {
            $this->errores[$campo] = 'No pudimos recibir el PDF ' . $tipo->deQue() . '. Probá de nuevo.';

            return null;
        }
        $mensaje = match (true) {
            filesize($ruta) > self::MAXIMO => self::muyPesado($tipo),
            !self::esPdf($ruta) => self::noEsPdf($tipo),
            default => null,
        };
        if ($mensaje !== null) {
            $this->errores[$campo] = $mensaje;

            return null;
        }

        return $ruta;
    }

    /**
     * finfo mira los primeros bytes y además tiene que empezar como empieza un PDF: un archivo que
     * finfo toma por PDF por algo que tiene más adentro no pasa.
     */
    private static function esPdf(string $ruta): bool
    {
        $inicio = (string) file_get_contents($ruta, length: 5);

        return (new finfo(FILEINFO_MIME_TYPE))->file($ruta) === 'application/pdf' && $inicio === '%PDF-';
    }

    private function validarFecha(TipoDocumento $tipo, string $texto): ?DateTimeImmutable
    {
        $campo = self::CAMPOS[$tipo->value][1];
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        // Sin la comparación, un 30 de febrero pasaría como 2 de marzo.
        if ($fecha === false || $fecha->format('Y-m-d') !== $texto) {
            $this->errores[$campo] = self::faltaLaFecha($tipo);

            return null;
        }
        $mensaje = match (true) {
            $fecha <= self::hoy() => self::yaVencio($tipo),
            $fecha > self::limite() => self::demasiadoLejos($tipo),
            default => null,
        };
        if ($mensaje !== null) {
            $this->errores[$campo] = $mensaje;

            return null;
        }

        return $fecha;
    }

    private static function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    private static function limite(): DateTimeImmutable
    {
        return self::hoy()->modify('+' . self::ANIOS_MAXIMOS . ' years');
    }

    private static function faltaElPdf(TipoDocumento $tipo): string
    {
        return 'Falta el PDF ' . $tipo->deQue() . '.';
    }

    private static function noEsPdf(TipoDocumento $tipo): string
    {
        return ucfirst($tipo->conArticulo()) . ' tiene que ser un PDF.';
    }

    private static function muyPesado(TipoDocumento $tipo): string
    {
        return 'El PDF ' . $tipo->deQue() . ' puede pesar hasta 5 MB.';
    }

    private static function faltaLaFecha(TipoDocumento $tipo): string
    {
        return 'Falta la fecha de vencimiento ' . $tipo->deQue() . '.';
    }

    private static function yaVencio(TipoDocumento $tipo): string
    {
        return ucfirst($tipo->conArticulo()) . ' tiene que vencer después de hoy.';
    }

    private static function demasiadoLejos(TipoDocumento $tipo): string
    {
        return 'El vencimiento ' . $tipo->deQue() . ' no puede pasar de ' . self::ANIOS_MAXIMOS . ' años: revisá el año.';
    }
}
