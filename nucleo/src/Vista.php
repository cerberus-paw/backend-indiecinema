<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

use IndieCinema\Nucleo\Http\Peticion;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renderiza las plantillas Twig. Las plantillas viven en el paquete front; las del núcleo (la
 * página de error) quedan de respaldo.
 */
final class Vista
{
    private readonly Environment $twig;

    /**
     * @param list<string> $directorios dónde buscar las plantillas, en orden; los que no existen se saltean
     * @param string       $prefijo     el del subsistema, que nginx le saca a la ruta al entrar
     */
    public function __construct(array $directorios, private readonly string $prefijo, bool $depuracion)
    {
        $existentes = array_values(array_filter($directorios, 'is_dir'));

        $this->twig = new Environment(new FilesystemLoader($existentes), [
            // Twig escapa todo lo que imprime: es el control contra XSS de la capa de aplicación.
            'autoescape' => 'html',
            // En desarrollo, una variable mal escrita falla en lugar de salir vacía.
            'strict_variables' => $depuracion,
        ]);
        $this->twig->addGlobal('prefijo', $this->prefijo);
        // Twig no deja agregar variables globales después de la primera plantilla, sólo cambiarlas:
        // por eso se declaran acá y compartirPeticion() les pone el valor de cada petición.
        $this->twig->addGlobal('usuario', null);
        $this->twig->addGlobal('csrf', null);
        $this->twig->addGlobal('ruta_actual', '/');
        // Los links se arman con el prefijo: en cuentas, «{{ ruta('/ingresar') }}» es
        // «/cuenta/ingresar»; en programación, que está en la raíz, queda igual.
        $this->twig->addFunction(new TwigFunction('ruta', fn (string $ruta): string => $this->prefijo . $ruta));
    }

    /**
     * Lo que recibe toda plantilla además de sus datos: el usuario (null sin sesión), el token
     * CSRF para los formularios y la ruta actual. Son globales y no datos de cada vista para que
     * también los tengan las páginas de error, que se ven con el mismo encabezado.
     */
    public function compartirPeticion(Peticion $peticion): void
    {
        $this->twig->addGlobal('usuario', $peticion->usuario());
        $this->twig->addGlobal('csrf', $peticion->tokenCsrf());
        $this->twig->addGlobal('ruta_actual', $peticion->ruta());
    }

    /**
     * @param array<string, mixed> $datos
     */
    public function renderizar(string $plantilla, array $datos = []): string
    {
        return $this->twig->render($plantilla, $datos);
    }
}
