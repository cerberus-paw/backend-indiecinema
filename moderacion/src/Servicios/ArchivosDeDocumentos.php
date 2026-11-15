<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use LogicException;
use RuntimeException;

/**
 * Los PDF del seguro y de la habilitación de las salas (EF-07.4), en el volumen de archivos de
 * moderación, junto a las fotos de las solicitudes y en su propia carpeta: fuera del webroot, así
 * que Apache nunca los sirve por su cuenta. Los entrega un controlador sólo a quien mandó la
 * solicitud y al moderador. No se borran: son el historial, y una corrección sin un PDF nuevo
 * apunta al mismo archivo.
 *
 * Cada uno se guarda con un nombre al azar, nunca con el que mandó el usuario, que podría pisar
 * otro archivo o salirse de la carpeta.
 */
final class ArchivosDeDocumentos
{
    private const NOMBRE = '/^[0-9a-f]{32}\.pdf$/D';

    private readonly string $carpeta;

    public function __construct(Configuracion $configuracion)
    {
        // El volumen que monta el compose (archivos-moderacion), como ArchivosDeSolicitudes.
        $this->carpeta = rtrim((string) $configuracion->obtener('archivos.carpeta', '/var/indiecinema/archivos'), '/') . '/documentos';
    }

    /**
     * @param string $rutaTemporal un PDF ya validado por su contenido
     *
     * @return string el nombre con el que quedó guardado
     */
    public function guardar(string $rutaTemporal): string
    {
        if (!is_dir($this->carpeta) && !mkdir($this->carpeta, 0750, true) && !is_dir($this->carpeta)) {
            throw new RuntimeException("No se pudo crear {$this->carpeta}.");
        }

        $nombre = bin2hex(random_bytes(16)) . '.pdf';
        // rename y no move_uploaded_file: la petición ya dejó pasar sólo archivos subidos en este
        // pedido (Peticion::desdeGlobales).
        if (!rename($rutaTemporal, $this->ruta($nombre))) {
            throw new RuntimeException("No se pudo guardar el documento en {$this->carpeta}.");
        }
        chmod($this->ruta($nombre), 0640);

        return $nombre;
    }

    public function borrar(string $nombre): void
    {
        $ruta = $this->ruta($nombre);
        if (is_file($ruta)) {
            unlink($ruta);
        }
    }

    public function contenido(string $nombre): ?string
    {
        $contenido = @file_get_contents($this->ruta($nombre));

        return $contenido === false ? null : $contenido;
    }

    private function ruta(string $nombre): string
    {
        // El nombre sale de la base, pero si no tiene la forma de los que se generan acá, no se usa.
        if (preg_match(self::NOMBRE, $nombre) !== 1) {
            throw new LogicException("«{$nombre}» no es el nombre de un documento.");
        }

        return "{$this->carpeta}/{$nombre}";
    }
}
