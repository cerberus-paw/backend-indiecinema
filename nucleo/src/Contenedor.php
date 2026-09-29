<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Arma los controladores y lo que necesitan.
 *
 * Lo compartido (la configuración, la vista, la base) se registra una vez; el resto se construye
 * mirando el constructor, así un controlador o un servicio nuevo no hay que darlo de alta en
 * ningún lado. Cada objeto se crea una sola vez por petición.
 */
final class Contenedor
{
    /** @var array<class-string, object> */
    private array $instancias = [];

    /** @var array<class-string, callable(self): object> */
    private array $fabricas = [];

    /**
     * @param class-string $clase
     */
    public function registrar(string $clase, object $instancia): void
    {
        $this->instancias[$clase] = $instancia;
    }

    /**
     * Para lo que no conviene crear si la petición no lo usa, como la conexión a la base.
     *
     * @param class-string           $clase
     * @param callable(self): object $fabrica
     */
    public function fabrica(string $clase, callable $fabrica): void
    {
        $this->fabricas[$clase] = $fabrica;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $clase
     *
     * @return T
     */
    public function obtener(string $clase): object
    {
        if (!isset($this->instancias[$clase])) {
            $this->instancias[$clase] = isset($this->fabricas[$clase])
                ? ($this->fabricas[$clase])($this)
                : $this->construir($clase);
        }

        /** @var T */
        return $this->instancias[$clase];
    }

    /**
     * @param class-string $clase
     */
    private function construir(string $clase): object
    {
        if (!class_exists($clase)) {
            throw new LogicException("No existe la clase {$clase}.");
        }
        $reflexion = new ReflectionClass($clase);
        if (!$reflexion->isInstantiable()) {
            throw new LogicException("No se puede construir {$clase}: hay que registrarla en el contenedor.");
        }

        $constructor = $reflexion->getConstructor();
        if ($constructor === null) {
            return $reflexion->newInstance();
        }

        $argumentos = [];
        foreach ($constructor->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            if ($tipo instanceof ReflectionNamedType && !$tipo->isBuiltin()) {
                $argumentos[] = $this->obtener($tipo->getName());
            } elseif ($parametro->isDefaultValueAvailable()) {
                $argumentos[] = $parametro->getDefaultValue();
            } else {
                throw new LogicException(
                    "No se puede construir {$clase}: \${$parametro->getName()} no es una clase y no tiene valor por defecto.",
                );
            }
        }

        return $reflexion->newInstanceArgs($argumentos);
    }
}
