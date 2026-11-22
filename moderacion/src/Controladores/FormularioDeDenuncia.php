<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CategoriaDenuncia;
use IndieCinema\Moderacion\Modelo\Denunciado;
use IndieCinema\Moderacion\Modelo\TipoDenuncia;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDenuncias;
use IndieCinema\Moderacion\Servicios\LoDenunciado;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * GET y POST /moderacion/denuncias/nueva?tipo=…&id=… (EF-07.8): cualquiera con sesión denuncia lo
 * publicado, salvo lo suyo, con un motivo y un detalle. El motivo da la gravedad (EF-22.11), que no
 * se ve ni se elige. La denuncia llega a la pestaña
 * «Denuncias» de la bandeja.
 */
final class FormularioDeDenuncia extends Controlador
{
    /** Como la columna denuncia.detalle. */
    private const MAXIMO_DETALLE = 1000;

    public function __construct(
        private readonly LoDenunciado $denunciados,
        private readonly RepositorioDeDenuncias $denuncias,
    ) {
    }

    /**
     * @throws ExcepcionHttp 404 si no existe, no está publicado o es suyo
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $denunciado = $this->denunciable($peticion);

        return $this->formulario($denunciado, enviada: $peticion->consulta('enviada') === '1', repetida: $this->yaLaHizo($denunciado, $peticion));
    }

    /**
     * Guarda la abierta y vuelve a la página, que la confirma con el link a la ficha (303: recargar
     * no la manda de nuevo). Una repetida no suma otra.
     *
     * @throws ExcepcionHttp 404 si no existe, no está publicado o es suyo
     */
    public function enviar(Peticion $peticion): Respuesta
    {
        $denunciado = $this->denunciable($peticion);
        $categoria = CategoriaDenuncia::tryFrom((string) (is_string($peticion->campo('categoria')) ? $peticion->campo('categoria') : ''));
        $detalle = self::detalle($peticion->campo('detalle'));
        $errores = [];
        if ($categoria === null || !in_array($categoria, $denunciado->tipo->categorias(), true)) {
            $errores['categoria'] = 'Elegí de qué se trata.';
        }
        if ($categoria === CategoriaDenuncia::Otro && $detalle === '') {
            $errores['detalle'] = 'Contanos qué pasa: en «Otro» el detalle es obligatorio.';
        } elseif (mb_strlen($detalle) > self::MAXIMO_DETALLE) {
            $errores['detalle'] = 'El detalle puede tener hasta ' . self::MAXIMO_DETALLE . ' caracteres.';
        }
        if ($errores !== [] || $categoria === null) {
            $campo = $peticion->campo('categoria');

            return $this->formulario($denunciado, ['categoria' => is_string($campo) ? $campo : '', 'detalle' => $detalle], $errores, 422);
        }

        $this->denuncias->agregar(
            $denunciado->tipo,
            $denunciado->id,
            $denunciado->salaId(),
            mb_substr($denunciado->titulo, 0, 200),
            self::usuario($peticion)->id,
            $categoria,
            $detalle === '' ? null : $detalle,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return $this->redirigir('/denuncias/nueva?' . http_build_query(['tipo' => $denunciado->tipo->value, 'id' => $denunciado->id, 'enviada' => '1']));
    }

    private function yaLaHizo(Denunciado $denunciado, Peticion $peticion): bool
    {
        return $this->denuncias->tieneAbierta($denunciado->tipo, $denunciado->id, self::usuario($peticion)->id);
    }

    /**
     * Conserva los renglones y saca los demás caracteres de control.
     */
    private static function detalle(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }

        return trim((string) preg_replace('/(?!\n)\p{Cc}/u', '', str_replace(["\r\n", "\r"], "\n", $valor)));
    }

    /**
     * Lo que se denuncia, si se puede denunciar: publicado y de otro.
     *
     * @throws ExcepcionHttp 404 si no
     */
    private function denunciable(Peticion $peticion): Denunciado
    {
        $tipo = TipoDenuncia::tryFrom((string) $peticion->consulta('tipo', ''));
        $id = $peticion->consulta('id');
        $denunciado = $tipo === null || !is_string($id) ? null : $this->denunciados->buscar($tipo, $id);
        if ($denunciado === null || !$denunciado->publicado || $denunciado->esDe(self::usuario($peticion)->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $denunciado;
    }

    /**
     * @param array<string, string> $valores lo que mandó, si hubo errores
     * @param array<string, string> $errores campo => qué está mal
     */
    private function formulario(Denunciado $denunciado, array $valores = [], array $errores = [], int $estado = 200, bool $enviada = false, bool $repetida = false): Respuesta
    {
        return $this->vista('denunciar.html.twig', [
            'denunciado' => ['tipo' => $denunciado->tipo->value, 'tipo_etiqueta' => $denunciado->tipo->etiqueta(), 'id' => $denunciado->id, 'titulo' => $denunciado->titulo, 'url' => $denunciado->url],
            'categorias' => array_map(static fn (CategoriaDenuncia $categoria): array => ['valor' => $categoria->value, 'etiqueta' => $categoria->etiqueta()], $denunciado->tipo->categorias()),
            'valores' => $valores,
            'errores' => $errores,
            'maximo_detalle' => self::MAXIMO_DETALLE,
            'enviada' => $enviada,
            'repetida' => $repetida,
        ], $estado);
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');
    }
}
