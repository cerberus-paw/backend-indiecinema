<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\DocumentoSubido;
use IndieCinema\Moderacion\Modelo\EstadoDocumento;
use IndieCinema\Moderacion\Modelo\Suspension;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDocumentos;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La documentación de una sala para su organizador (EF-07.5): por qué está suspendida, sus
 * documentos y las renovaciones que sube. De quién es la sala lo dice programación.
 */
final class ServicioDeDocumentacion
{
    public function __construct(
        private readonly RepositorioDeDocumentos $documentos,
        private readonly ArchivosDeDocumentos $archivos,
        private readonly ServicioDeSuspensiones $suspensiones,
        private readonly SalasDeProgramacion $salas,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * La sala, si es del organizador. Si no existe o es de otro, 404, como si no existiera.
     *
     * @return array{nombre: string, organizador_id: string, habilitada: bool}
     *
     * @throws ExcepcionHttp 404; 503 si programación no responde
     */
    public function salaDe(string $salaId, Usuario $organizador): array
    {
        $sala = Uuid::esValido($salaId) ? $this->salas->datosDe($salaId) : null;
        if ($sala === null || !hash_equals($sala['organizador_id'], $organizador->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $sala;
    }

    /**
     * @return list<Suspension>
     */
    public function suspensionesDe(string $salaId): array
    {
        return $this->suspensiones->activasDe($salaId);
    }

    /**
     * @return list<Documento> de la sala, primero el seguro y el que vence más lejos
     */
    public function documentosDe(string $salaId): array
    {
        return $this->documentos->deLaSala($salaId);
    }

    /**
     * Guarda el PDF y lo deja pendiente, sin solicitud, para que lo revise la moderación.
     */
    public function subir(string $salaId, DocumentoSubido $subido): Documento
    {
        if ($subido->rutaTemporal === null) {
            throw new LogicException('Una renovación lleva su PDF: el formulario lo pide.');
        }
        $archivo = $this->archivos->guardar($subido->rutaTemporal);
        $documento = new Documento(
            Uuid::nuevo(),
            $salaId,
            null,
            $subido->tipo,
            $archivo,
            $subido->venceEn,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            EstadoDocumento::Pendiente,
        );
        try {
            $this->documentos->agregarRenovacion($documento);
        } catch (Throwable $error) {
            $this->archivos->borrar($archivo);
            throw $error;
        }
        $this->log->info('El organizador de la sala {sala} subió {tipo} nuevo (documento {documento}).', [
            'sala' => $salaId,
            'tipo' => $subido->tipo->conArticulo(),
            'documento' => $documento->id,
        ]);

        return $documento;
    }
}
