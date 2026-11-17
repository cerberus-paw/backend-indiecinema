<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use IndieCinema\Moderacion\Modelo\DatosDeSala;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\DocumentoSubido;
use IndieCinema\Moderacion\Modelo\TipoDocumento;

/**
 * El formulario de la solicitud de sala: los datos de la sala, con las mismas reglas que el alta
 * (FormularioDeSala), el seguro y la habilitación en PDF con su vencimiento
 * (FormularioDeDocumentos, EF-07.4), y las dos declaraciones de «Términos y condiciones» del
 * wireframe, sin las que no se manda.
 */
final class FormularioDeSolicitud
{
    /** Casilla => qué falta si no está tildada. */
    private const DECLARACIONES = [
        'habilitacion' => 'Tenés que declarar que el espacio está habilitado.',
        'derechos' => 'Tenés que comprometerte a tener los derechos de exhibición.',
    ];

    /** @var array<string, string|bool> lo que mandó, para volver a mostrarlo; las casillas, como verdadero o falso */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    /** @var list<DocumentoSubido> los PDF que mandó bien */
    public private(set) array $documentos = [];

    private function __construct(public readonly FormularioDeSala $sala, ?FormularioDeDocumentos $documentos = null)
    {
        $this->valores = $sala->valores + ($documentos === null ? [] : $documentos->valores);
        $this->errores = $sala->errores + ($documentos === null ? [] : $documentos->errores);
        $this->documentos = $documentos === null ? [] : $documentos->documentos;
    }

    /**
     * @param array<string, mixed>                     $campos            el cuerpo del POST
     * @param array<string, array<string, mixed>|null> $archivos          campo => el archivo, como lo arma PHP
     * @param bool                                     $imagenObligatoria para una sala nueva; al corregir una
     *                                                                    rechazada, sin foto queda la que tenía
     * @param list<TipoDocumento>                      $yaMandados        los documentos de la rechazada: sin un
     *                                                                    PDF nuevo, queda el que mandó
     */
    public static function desdeCampos(array $campos, array $archivos, bool $imagenObligatoria, array $yaMandados = []): self
    {
        $formulario = new self(
            FormularioDeSala::desdeCampos($campos, $archivos['imagen'] ?? null, $imagenObligatoria),
            FormularioDeDocumentos::desdeCampos($campos, $archivos, $yaMandados),
        );
        foreach (self::DECLARACIONES as $casilla => $mensaje) {
            $tildada = ($campos[$casilla] ?? null) === '1';
            $formulario->valores[$casilla] = $tildada;
            if (!$tildada) {
                $formulario->errores[$casilla] = $mensaje;
            }
        }

        return $formulario;
    }

    /**
     * Con los datos de la sala que le rechazaron y los vencimientos de sus documentos, para
     * corregirla. Las declaraciones se vuelven a tildar.
     *
     * @param list<Documento> $documentos
     */
    public static function desdeDatos(DatosDeSala $datos, array $documentos = []): self
    {
        return new self(FormularioDeSala::desdeDatos($datos), FormularioDeDocumentos::desdeDocumentos($documentos));
    }

    /**
     * Los datos de la sala, o null si hay algún error, también en las declaraciones.
     */
    public function datos(): ?DatosDeSala
    {
        return $this->errores === [] ? $this->sala->datos() : null;
    }

    /**
     * Las del alta de la sala, las de los documentos y las declaraciones, obligatorias. Cada declaración lleva su
     * mensaje, el mismo que contesta el servidor si no se tilda (E4-09.1): sin tildar es lo único
     * que el navegador puede ver de ellas.
     *
     * @param list<TipoDocumento> $yaMandados
     *
     * @return array<string, array<string, mixed>>
     */
    public static function reglas(bool $imagenObligatoria, array $yaMandados = []): array
    {
        $declaraciones = [];
        foreach (self::DECLARACIONES as $casilla => $mensaje) {
            $declaraciones[$casilla] = ['requerido' => true, 'mensajes' => ['requerido' => $mensaje]];
        }

        return FormularioDeSala::reglas($imagenObligatoria) + FormularioDeDocumentos::reglas($yaMandados) + $declaraciones;
    }
}
