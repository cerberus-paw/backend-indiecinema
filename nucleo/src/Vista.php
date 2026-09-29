<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

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
        // Los links se arman con el prefijo: en cuentas, «{{ ruta('/ingresar') }}» es
        // «/cuenta/ingresar»; en programación, que está en la raíz, queda igual.
        $this->twig->addFunction(new TwigFunction('ruta', fn (string $ruta): string => $this->prefijo . $ruta));
    }

    /**
     * @param array<string, mixed> $datos
     */
    public function renderizar(string $plantilla, array $datos = []): string
    {
        return $this->twig->render($plantilla, $datos);
    }
}
