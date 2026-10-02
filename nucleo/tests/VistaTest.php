<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas;

use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Vista;
use PHPUnit\Framework\TestCase;

final class VistaTest extends TestCase
{
    public function testTodaPlantillaRecibeElUsuarioElTokenYLaRuta(): void
    {
        // Con strict_variables, como en desarrollo: una variable que no llega haría fallar la prueba.
        $vista = new Vista([__DIR__ . '/Dobles/plantillas'], '/cuenta', true);

        $vista->compartirPeticion((new Peticion('GET', '/'))->conTokenCsrf('t1'));
        self::assertSame("visitante|t1|/|sin panel\n", $vista->renderizar('comunes.html.twig'));

        // Después de la primera plantilla Twig sólo deja cambiar globales que ya existían: la
        // segunda petición tiene que ver sus propios datos y no los de la anterior.
        $peticion = (new Peticion('GET', '/salas'))
            ->conUsuario(new Usuario('u-1', Rol::Organizador, 'Salvador'))
            ->conTokenCsrf('t2');
        $vista->compartirPeticion($peticion);
        self::assertSame("Salvador|t2|/salas|panel\n", $vista->renderizar('comunes.html.twig'));
    }
}
