<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CategoriaDenuncia;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDenuncias;
use IndieCinema\Nucleo\Configuracion;
use RuntimeException;

/**
 * Las denuncias de prueba (EF-22.11), para mostrar las reglas en la demo: sobre el Alambique, la
 * primera sala del organizador de prueba, dos denuncias medias tomadas de los dos espectadores
 * dentro del período (a una del aviso, con aviso_cantidad = 3) y una alta abierta sobre una de sus
 * funciones, que queda «Para suspender».
 *
 * Usa los ids fijos de los datos de prueba de cuentas y de programación, así que va después de
 * ellos. Las fechas se cuentan hacia atrás desde hoy, así quedan dentro del período. Correrlo de
 * nuevo agrega sólo lo que falte, buscando cada denuncia por su id.
 */
final class DatosDePrueba
{
    public const SALA = '00000001-0000-4000-8000-000000000001';
    public const FUNCION = '00000003-0000-4000-8000-000000000001';
    private const ADMINISTRADOR = '00000000-0000-4000-8000-000000000001';
    private const EVA = '00000000-0000-4000-8000-000000000003';
    private const ELIAS = '00000000-0000-4000-8000-000000000004';

    /**
     * hace: cuántos días antes de hoy se hizo; tomada: si la tomó el administrador un día después.
     *
     * @var list<array{id: string, objeto: 'sala'|'funcion', usuario: string, categoria: CategoriaDenuncia, detalle: string, hace: int, tomada: ?string}>
     */
    private const DENUNCIAS = [
        ['id' => '00000007-0000-4000-8000-000000000001', 'objeto' => 'sala', 'usuario' => self::EVA, 'categoria' => CategoriaDenuncia::Falsa, 'detalle' => 'La dirección de la ficha no es la de la sala: di vueltas media hora.', 'hace' => 6, 'tomada' => 'La ficha tenía la dirección vieja.'],
        ['id' => '00000007-0000-4000-8000-000000000002', 'objeto' => 'sala', 'usuario' => self::ELIAS, 'categoria' => CategoriaDenuncia::CambioSinAviso, 'detalle' => 'Pasaron la función de las 20 a las 22 y no avisaron.', 'hace' => 4, 'tomada' => 'El cambio de horario no se avisó.'],
        ['id' => '00000007-0000-4000-8000-000000000003', 'objeto' => 'funcion', 'usuario' => self::EVA, 'categoria' => CategoriaDenuncia::NoSeHizo, 'detalle' => 'Fuimos a la hora y estaba cerrado.', 'hace' => 1, 'tomada' => null],
    ];

    private function __construct(
        private readonly RepositorioDeDenuncias $denuncias,
        private readonly SalasDeProgramacion $salas,
        private readonly FuncionesDeProgramacion $funciones,
    ) {
    }

    /**
     * @throws RuntimeException fuera de desarrollo, sin la confirmación
     */
    public static function para(Configuracion $configuracion, RepositorioDeDenuncias $denuncias, SalasDeProgramacion $salas, FuncionesDeProgramacion $funciones, bool $confirmarProduccion = false): self
    {
        if (!$configuracion->enDesarrollo() && !$confirmarProduccion) {
            throw new RuntimeException('Fuera de desarrollo, las denuncias de prueba se cargan sólo con --confirmar-produccion.');
        }

        return new self($denuncias, $salas, $funciones);
    }

    /**
     * @return array<string, bool> id => si se agregó ahora
     *
     * @throws RuntimeException si programación no tiene la sala o la función de prueba: no carga nada
     */
    public function cargar(): array
    {
        $sala = $this->salas->datosDe(self::SALA)
            ?? throw new RuntimeException('Programación no tiene la sala de prueba (' . self::SALA . '): primero sus datos de prueba. No se cargó nada.');
        $funcion = $this->funciones->una(self::FUNCION)
            ?? throw new RuntimeException('Programación no tiene la función de prueba (' . self::FUNCION . '): primero sus datos de prueba. No se cargó nada.');
        $titulos = ['sala' => $sala['nombre'], 'funcion' => implode(' + ', array_column($funcion['obras'], 'titulo'))];
        $hoy = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $cargadas = [];
        foreach (self::DENUNCIAS as $denuncia) {
            $creada = $hoy->modify("-{$denuncia['hace']} days");
            $cargadas[$denuncia['id']] = $this->denuncias->agregarDePrueba(
                $denuncia['id'],
                $denuncia['objeto'] === 'sala' ? self::SALA : self::FUNCION,
                $denuncia['objeto'],
                self::SALA,
                mb_substr($titulos[$denuncia['objeto']], 0, 200),
                $denuncia['usuario'],
                $denuncia['categoria'],
                $denuncia['detalle'],
                $creada,
                $denuncia['tomada'] === null ? null : [$denuncia['tomada'], self::ADMINISTRADOR, $creada->modify('+1 day')],
            );
        }

        return $cargadas;
    }
}
