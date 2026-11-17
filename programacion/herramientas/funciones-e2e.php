<?php

/*
 * Crea las dos funciones donde reservan las pruebas de punta a punta (`E2E_FUNCION`): una para las
 * del back y otra para las del front, así las dos corridas no se cuentan los lugares.
 *
 *     docker compose exec programacion php herramientas/funciones-e2e.php
 *
 * Son gratuitas y a un año. Gratuitas porque lo que prueban esas pruebas es la reserva confirmada,
 * con su código y su comprobante: una función paga, con Mercado Pago activo, deja la reserva
 * pendiente de pago, y las pruebas ni la confirman ni la cancelan. A un año porque las funciones de
 * los datos de prueba se cuentan desde el día en que se cargaron y van pasando: la del front pasó
 * el 13/11 y con ella se cayeron todas sus reservas.
 *
 * Van en La Trocha, de los datos de prueba (herramientas/datos-de-prueba.php, que tiene que haber
 * corrido antes). Correrlo de nuevo no cambia las que ya están. En producción, como los datos de
 * prueba, sólo con --confirmar-produccion: el servidor de desarrollo tiene entorno = "produccion".
 */

declare(strict_types=1);

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;

// Está fuera del webroot, pero por las dudas: no se ejecuta desde un pedido HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

const CONFIRMAR_PRODUCCION = '--confirmar-produccion';

// La «e» del prefijo es de e2e: los ids de los datos de prueba usan del 0 al 9.
const FUNCIONES_E2E = [
    'back' => '0000000e-0000-4000-8000-000000000001',
    'front' => '0000000e-0000-4000-8000-000000000002',
];
const TROCHA = '00000001-0000-4000-8000-000000000002';
const POTEMKIN = '00000002-0000-4000-8000-000000000006';
// Menos que la capacidad de la sala (120), y lejos del tope por persona: las pruebas reservan de a
// pocos lugares y los cancelan al terminar.
const CUPO = 100;

$argumentos = array_slice($argv, 1);
foreach (array_diff($argumentos, [CONFIRMAR_PRODUCCION]) as $desconocido) {
    fwrite(STDERR, "No conozco el argumento {$desconocido}: el único es " . CONFIRMAR_PRODUCCION . '.' . PHP_EOL);
    exit(1);
}

try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    if (!$configuracion->enDesarrollo() && !in_array(CONFIRMAR_PRODUCCION, $argumentos, true)) {
        throw new RuntimeException(
            'Esto es producción: las funciones de las pruebas se crean acá sólo con la confirmación explícita, '
            . 'agregando ' . CONFIRMAR_PRODUCCION . ' al comando.',
        );
    }
    $base = BaseDeDatos::conectar($configuracion);
    $salas = new RepositorioDeSalas($base);
    $funciones = new RepositorioDeFunciones($base);
    if ($salas->buscar(TROCHA) === null) {
        throw new RuntimeException('Falta la sala La Trocha: primero herramientas/datos-de-prueba.php.');
    }

    $fecha = (new DateTimeImmutable('today'))->modify('+1 year')->setTime(20, 0);
    foreach (FUNCIONES_E2E as $para => $id) {
        $existente = $funciones->buscar($id);
        if ($existente !== null) {
            printf("  %-5s %s ya estaba (%s, %s)\n", $para, $id, $existente->estado->value, $existente->fechaHora?->format('Y-m-d H:i') ?? 'sin fecha');
            continue;
        }
        $funciones->agregar(new Funcion($id, TROCHA, [POTEMKIN], 0.0, CUPO, EstadoFuncion::Programada, $fecha));
        printf("  %-5s %s agregada (%s)\n", $para, $id, $fecha->format('Y-m-d H:i'));
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
