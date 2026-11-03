<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Servicios\OcupacionDeFunciones;
use IndieCinema\Programacion\Servicios\ServicioDeCartelera;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;

/**
 * GET /: la portada, para cualquiera, con o sin sesión. El carrusel de destacadas, las próximas
 * funciones de la cartelera, algunas salas y un buscador que lleva a la cartelera filtrada.
 *
 * El administrador no usa el sitio público (su encabezado no tiene la cartelera ni las salas):
 * va a su panel. Como el ingreso lleva a la portada, así entra directo ahí.
 */
final class Inicio extends Controlador
{
    /** Una fila de tarjetas en escritorio. */
    private const FUNCIONES = 4;
    private const SALAS = 3;
    /**
     * El carrusel (E4-07) tiene hasta seis: destacar muchas es no destacar ninguna, y la barra de
     * carga no espera de más. Son las primeras de la cartelera que tienen afiche (es un carrusel de
     * imágenes, y una diapositiva sin imagen sería el recuadro vacío del wireframe) y que no
     * cerraron su acuerdo sin quórum (ya no se dan: destacarlas invitaría a algo que no va a pasar).
     */
    private const DESTACADAS = 6;
    /** Entre cuántas de las próximas se buscan, para llegar a seis aunque alguna no tenga afiche. */
    private const CANDIDATAS = 12;

    public function __construct(
        private readonly ServicioDeCartelera $cartelera,
        private readonly ServicioDeSalas $salas,
        private readonly OcupacionDeFunciones $ocupacion,
    ) {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        if ($peticion->usuario()?->alcanza(Rol::Administrador)) {
            return Respuesta::redireccion('/cuenta/administracion/usuarios');
        }

        // Un solo pedido para el carrusel y las tarjetas: las tarjetas son las primeras de la lista.
        $proximas = $this->cartelera->proximas(max(self::FUNCIONES, self::CANDIDATAS));
        $tarjetas = TarjetaDeFuncion::todas(
            $proximas->funciones,
            $this->cartelera->acuerdosDe($proximas->funciones),
            $this->ocupacion->deVarias(TarjetaDeFuncion::conLugares($proximas->funciones)),
        );
        $salas = $this->salas->publicadas();

        return $this->vista('inicio.html.twig', [
            'destacadas' => array_slice(array_values(array_filter(
                $tarjetas,
                static fn (array $tarjeta): bool => $tarjeta['afiche'] !== null && $tarjeta['estado'] !== 'ACUERDO_SIN_MINIMO',
            )), 0, self::DESTACADAS),
            'funciones' => array_slice($tarjetas, 0, self::FUNCIONES),
            'total_funciones' => $proximas->total,
            'salas' => array_map(TarjetaDeSala::datos(...), array_slice($salas, 0, self::SALAS)),
            'total_salas' => count($salas),
            'localidades' => $this->cartelera->localidades(),
        ]);
    }
}
