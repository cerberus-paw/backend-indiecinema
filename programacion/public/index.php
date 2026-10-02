<?php

declare(strict_types=1);

// Único punto de entrada del subsistema: todo lo demás queda fuera del webroot.
require dirname(__DIR__) . '/vendor/autoload.php';

IndieCinema\Nucleo\Aplicacion::iniciar(dirname(__DIR__));
