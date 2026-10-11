<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use RuntimeException;

/**
 * Los datos de prueba y demostración del subsistema de programación:
 * - 4 salas en localidades distintas, todas habilitadas para programar funciones.
 * - 12 obras clásicas en dominio público con afiches de licencia libre (CC0 / Dominio Público).
 * - 15 funciones futuras con cupo válido, precio y obras asignadas.
 *
 * Sin datos personales reales: salas con nombres culturales y direcciones ficticias,
 * organizadores del dominio reservado .test, y autores históricos del cine.
 *
 * cargar() los guarda con los repositorios, como cualquier alta: los géneros, las salas, las
 * obras con sus géneros y las funciones con sus obras, en ese orden por las claves foráneas.
 */
final class DatosDePrueba
{
    /**
     * Omar Organizador, de los datos de prueba de cuentas, que le da a cada usuario un id fijo:
     * programación no ve ese esquema, así que sólo puede apuntarle si conoce el id de antemano.
     * Es el organizador de las salas y el dueño de las obras.
     */
    public const ORGANIZADOR_DEFECTO_ID = '00000000-0000-4000-8000-000000000002';
    public const DUENO_DEFECTO_ID = self::ORGANIZADOR_DEFECTO_ID;

    /**
     * Géneros cinematográficos para clasificar las obras de demostración.
     *
     * @var list<array{id: string, nombre: string, descripcion: string}>
     */
    public const GENEROS = [
        [
            'id' => '00000004-0000-4000-8000-000000000001',
            'nombre' => 'Drama',
            'descripcion' => 'Obras con énfasis en el conflicto humano, las relaciones y la emoción profunda.',
        ],
        [
            'id' => '00000004-0000-4000-8000-000000000002',
            'nombre' => 'Terror',
            'descripcion' => 'Historias diseñadas para provocar suspenso, miedo o inquietud a través de lo macabro o sobrenatural.',
        ],
        [
            'id' => '00000004-0000-4000-8000-000000000003',
            'nombre' => 'Ciencia ficción',
            'descripcion' => 'Relatos basados en especulaciones científicas, futuros distópicos y viajes espaciales.',
        ],
        [
            'id' => '00000004-0000-4000-8000-000000000004',
            'nombre' => 'Comedia',
            'descripcion' => 'Historias con tono humorístico, situaciones absurdas, enredos y remates cómicos.',
        ],
        [
            'id' => '00000004-0000-4000-8000-000000000005',
            'nombre' => 'Aventura',
            'descripcion' => 'Viajes, desafíos extraordinarios y persecuciones llenas de acción y descubrimientos.',
        ],
        [
            'id' => '00000004-0000-4000-8000-000000000006',
            'nombre' => 'Fantasía',
            'descripcion' => 'Mundos imaginarios, magia, ilusión óptica y elementos sobrenaturales o mitológicos.',
        ],
        [
            'id' => '00000004-0000-4000-8000-000000000007',
            'nombre' => 'Suspenso',
            'descripcion' => 'Tramas de misterio y tensión psicológica que mantienen al espectador en incertidumbre constante.',
        ],
        [
            'id' => '00000004-0000-4000-8000-000000000008',
            'nombre' => 'Documental',
            'descripcion' => 'Registro cinematográfico de la realidad, ensayo visual y exploración social o urbana.',
        ],
    ];

    /**
     * 4 salas en localidades distintas, todas habilitadas y con imágenes de licencia libre.
     *
     * @var list<array{
     *     id: string,
     *     organizador_id: string,
     *     nombre: string,
     *     descripcion: string,
     *     direccion: string,
     *     localidad: string,
     *     capacidad: int,
     *     peliculas_por_funcion: int,
     *     duracion_funcion: int,
     *     tiempo_entre_funciones: int,
     *     imagen: string,
     *     habilitada: bool
     * }>
     */
    public const SALAS = [
        [
            'id' => '00000001-0000-4000-8000-000000000001',
            'organizador_id' => self::ORGANIZADOR_DEFECTO_ID,
            'nombre' => 'Cine Club El Alambique',
            'descripcion' => 'Espacio cultural independiente dedicado a la proyección y debate de clásicos del cine mundial y producciones autogestivas.',
            'direccion' => 'San Martín 420',
            'localidad' => 'Luján',
            'capacidad' => 80,
            'peliculas_por_funcion' => 1,
            'duracion_funcion' => 180,
            'tiempo_entre_funciones' => 30,
            'imagen' => 'c1a1a001000000000000000000000001.jpg',
            'habilitada' => true,
        ],
        [
            'id' => '00000001-0000-4000-8000-000000000002',
            'organizador_id' => self::ORGANIZADOR_DEFECTO_ID,
            'nombre' => 'Espacio Cultural La Trocha',
            'descripcion' => 'Antigua estación ferroviaria recuperada como sala de cine comunitario, proyecciones al aire libre y ciclos de vanguardia.',
            'direccion' => 'Calle 40 y 21',
            'localidad' => 'Mercedes',
            'capacidad' => 120,
            'peliculas_por_funcion' => 2,
            'duracion_funcion' => 180,
            'tiempo_entre_funciones' => 45,
            'imagen' => 'c1a1a002000000000000000000000002.jpg',
            'habilitada' => true,
        ],
        [
            'id' => '00000001-0000-4000-8000-000000000003',
            'organizador_id' => self::ORGANIZADOR_DEFECTO_ID,
            'nombre' => 'Cine Teatro Eureka',
            'descripcion' => 'Sala cooperativa recuperada por vecinos y realizadores independientes, equipada con proyector de alta definición y sonido envolvente.',
            'direccion' => '25 de Mayo 312',
            'localidad' => 'General Rodríguez',
            'capacidad' => 60,
            'peliculas_por_funcion' => 1,
            'duracion_funcion' => 110,
            'tiempo_entre_funciones' => 30,
            'imagen' => 'c1a1a003000000000000000000000003.jpg',
            'habilitada' => true,
        ],
        [
            'id' => '00000001-0000-4000-8000-000000000004',
            'organizador_id' => self::ORGANIZADOR_DEFECTO_ID,
            'nombre' => 'Cine Club Areco',
            'descripcion' => 'Microcine independiente en el casco histórico de Areco, con ciclos temáticos de cine mudo, vanguardias y cortometrajes regionales.',
            'direccion' => 'Alsina 184',
            'localidad' => 'San Antonio de Areco',
            'capacidad' => 45,
            'peliculas_por_funcion' => 2,
            'duracion_funcion' => 90,
            'tiempo_entre_funciones' => 20,
            'imagen' => 'c1a1a004000000000000000000000004.jpg',
            'habilitada' => true,
        ],
    ];

    /**
     * 12 obras clásicas con afiche versionado en dominio público y sin datos personales reales.
     *
     * @var list<array{
     *     id: string,
     *     dueno_id: string,
     *     titulo: string,
     *     tipo: string,
     *     sinopsis: string,
     *     anio: int,
     *     director: string,
     *     duracion: int,
     *     origen_copia: string,
     *     afiche: string,
     *     generos: list<string>
     * }>
     */
    public const OBRAS = [
        [
            'id' => '00000002-0000-4000-8000-000000000001',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'Metrópolis',
            'tipo' => 'pelicula',
            'sinopsis' => 'En una distopía futurista dividida entre la opulencia de los gobernantes y el subsuelo obrero, Freder se enamora de la pacifista María mientras un científico crea un robot a su imagen.',
            'anio' => 1927,
            'director' => 'Fritz Lang',
            'duracion' => 153,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0001000000000000000000000001.jpg',
            'generos' => ['Ciencia ficción', 'Drama'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000002',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'Nosferatu',
            'tipo' => 'pelicula',
            'sinopsis' => 'Hutter viaja a los Cárpatos para vender una finca al misterioso conde Orlok, sin sospechar que se trata de un vampiro milenario que llevará la peste y la muerte a su hogar.',
            'anio' => 1922,
            'director' => 'F. W. Murnau',
            'duracion' => 94,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0002000000000000000000000002.jpg',
            'generos' => ['Terror', 'Suspenso'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000003',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'Viaje a la Luna',
            'tipo' => 'cortometraje',
            'sinopsis' => 'Un grupo de astrónomos viaja al satélite terrestre en una cápsula propulsada por un cañón gigante, explora el suelo lunar y escapa de los selenítemas en un clásico fundacional.',
            'anio' => 1902,
            'director' => 'Georges Méliès',
            'duracion' => 13,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0003000000000000000000000003.jpg',
            'generos' => ['Ciencia ficción', 'Fantasía'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000004',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'El gabinete del Dr. Caligari',
            'tipo' => 'pelicula',
            'sinopsis' => 'En una feria ambulante, el doctor Caligari exhibe al sonámbulo Cesare, quien vaticina crímenes mientras una ola de asesinatos aterroriza a la ciudad en escenarios expresionistas.',
            'anio' => 1920,
            'director' => 'Robert Wiene',
            'duracion' => 77,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0004000000000000000000000004.jpg',
            'generos' => ['Terror', 'Suspenso'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000005',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'El maquinista de la General',
            'tipo' => 'pelicula',
            'sinopsis' => 'Durante la Guerra Civil estadounidense, un maquinista rechazado por el ejército emprende una audaz persecución solitaria para recuperar su locomotora secuestrada y rescatar a su novia.',
            'anio' => 1926,
            'director' => 'Buster Keaton',
            'duracion' => 79,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0005000000000000000000000005.jpg',
            'generos' => ['Comedia', 'Aventura'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000006',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'El acorazado Potemkin',
            'tipo' => 'pelicula',
            'sinopsis' => 'La tripulación de un buque de guerra ruso se rebela contra las condiciones inhumanas de los oficiales, desatando una conmovedora ola de solidaridad popular en el puerto de Odesa.',
            'anio' => 1925,
            'director' => 'Serguéi Eisenstein',
            'duracion' => 75,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0006000000000000000000000006.jpg',
            'generos' => ['Drama', 'Aventura'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000007',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'La noche de los muertos vivientes',
            'tipo' => 'pelicula',
            'sinopsis' => 'Un grupo de desconocidos se refugia en una granja abandonada para sobrevivir al asedio de una horda de muertos resucitados en la obra maestra fundacional del cine zombi.',
            'anio' => 1968,
            'director' => 'George A. Romero',
            'duracion' => 96,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0007000000000000000000000007.jpg',
            'generos' => ['Terror', 'Suspenso'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000008',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'Sherlock Jr.',
            'tipo' => 'pelicula',
            'sinopsis' => 'Un proyeccionista que sueña con ser detective se duerme durante una función y entra mágicamente a la pantalla para resolver un robo con asombrosas acrobacias visuales.',
            'anio' => 1924,
            'director' => 'Buster Keaton',
            'duracion' => 45,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0008000000000000000000000008.jpg',
            'generos' => ['Comedia'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000009',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'El hombre de la cámara',
            'tipo' => 'pelicula',
            'sinopsis' => 'Retrato vanguardista y vertiginoso de una jornada urbana mediante revolucionarias técnicas de montaje, cámara rápida, sobreimpresiones y pantallas divididas.',
            'anio' => 1929,
            'director' => 'Dziga Vértov',
            'duracion' => 68,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0009000000000000000000000009.jpg',
            'generos' => ['Documental', 'Drama'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000010',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'El fantasma de la ópera',
            'tipo' => 'pelicula',
            'sinopsis' => 'Un misterioso músico desfigurado habita en las catacumbas de la Ópera de París y aterroriza a los artistas para impulsar al estrellato a una joven cantante.',
            'anio' => 1925,
            'director' => 'Rupert Julian',
            'duracion' => 93,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0010000000000000000000000010.jpg',
            'generos' => ['Terror', 'Drama'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000011',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'Viaje a través de lo imposible',
            'tipo' => 'cortometraje',
            'sinopsis' => 'Exploradores de la Sociedad Geográfica viajan al interior del Sol en un tren volador y un submarino mecánico en una colorida odisea de ciencia ficción temprana.',
            'anio' => 1904,
            'director' => 'Georges Méliès',
            'duracion' => 20,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0011000000000000000000000011.jpg',
            'generos' => ['Ciencia ficción', 'Aventura'],
        ],
        [
            'id' => '00000002-0000-4000-8000-000000000012',
            'dueno_id' => self::DUENO_DEFECTO_ID,
            'titulo' => 'El pibe',
            'tipo' => 'pelicula',
            'sinopsis' => 'Un vagabundo de buen corazón rescata a un bebé abandonado en un callejón y lo cría como a su propio hijo, forjando un entrañable vínculo puesto a prueba por el destino.',
            'anio' => 1921,
            'director' => 'Charles Chaplin',
            'duracion' => 68,
            'origen_copia' => 'dominio_publico',
            'afiche' => 'af1c0012000000000000000000000012.jpg',
            'generos' => ['Comedia', 'Drama'],
        ],
    ];

    /**
     * 15 funciones futuras con cupo <= capacidad de la sala, precio base >= 0 y fecha futura.
     *
     * La fecha no es fija: es dias después del día en que se cargan, a la hora indicada. Con una
     * fecha fija, la demo se quedaría sin funciones futuras en cuanto pasara.
     *
     * @var list<array{
     *     id: string,
     *     sala_id: string,
     *     estado: string,
     *     dias: int,
     *     hora: string,
     *     precio_base: float,
     *     cupo: int,
     *     obras: list<array{obra_id: string, estado: string}>
     * }>
     */
    public const FUNCIONES = [
        // Sala 1: Cine Club El Alambique (Luján, capacidad: 80)
        [
            'id' => '00000003-0000-4000-8000-000000000001',
            'sala_id' => '00000001-0000-4000-8000-000000000001',
            'estado' => 'programada',
            'dias' => 7,
            'hora' => '20:00',
            'precio_base' => 2500.00,
            'cupo' => 80,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000001', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000002',
            'sala_id' => '00000001-0000-4000-8000-000000000001',
            'estado' => 'programada',
            'dias' => 14,
            'hora' => '21:00',
            'precio_base' => 2200.00,
            'cupo' => 75,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000002', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000003',
            'sala_id' => '00000001-0000-4000-8000-000000000001',
            'estado' => 'programada',
            'dias' => 21,
            'hora' => '20:30',
            'precio_base' => 2500.00,
            'cupo' => 80,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000004', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000004',
            'sala_id' => '00000001-0000-4000-8000-000000000001',
            'estado' => 'programada',
            'dias' => 28,
            'hora' => '19:30',
            'precio_base' => 2000.00,
            'cupo' => 70,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000005', 'estado' => 'elegida'],
            ],
        ],

        // Sala 2: Espacio Cultural La Trocha (Mercedes, capacidad: 120)
        [
            'id' => '00000003-0000-4000-8000-000000000005',
            'sala_id' => '00000001-0000-4000-8000-000000000002',
            'estado' => 'programada',
            'dias' => 8,
            'hora' => '19:00',
            'precio_base' => 0.00,
            'cupo' => 120,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000003', 'estado' => 'elegida'],
                ['obra_id' => '00000002-0000-4000-8000-000000000001', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000006',
            'sala_id' => '00000001-0000-4000-8000-000000000002',
            'estado' => 'programada',
            'dias' => 15,
            'hora' => '20:00',
            'precio_base' => 1800.00,
            'cupo' => 100,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000006', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000007',
            'sala_id' => '00000001-0000-4000-8000-000000000002',
            'estado' => 'programada',
            'dias' => 22,
            'hora' => '21:30',
            'precio_base' => 2400.00,
            'cupo' => 120,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000007', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000008',
            'sala_id' => '00000001-0000-4000-8000-000000000002',
            'estado' => 'programada',
            'dias' => 29,
            'hora' => '18:30',
            'precio_base' => 0.00,
            'cupo' => 110,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000011', 'estado' => 'elegida'],
                ['obra_id' => '00000002-0000-4000-8000-000000000008', 'estado' => 'elegida'],
            ],
        ],

        // Sala 3: Cine Teatro Eureka (General Rodríguez, capacidad: 60)
        [
            'id' => '00000003-0000-4000-8000-000000000009',
            'sala_id' => '00000001-0000-4000-8000-000000000003',
            'estado' => 'programada',
            'dias' => 9,
            'hora' => '18:00',
            'precio_base' => 2800.00,
            'cupo' => 60,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000009', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000010',
            'sala_id' => '00000001-0000-4000-8000-000000000003',
            'estado' => 'programada',
            'dias' => 16,
            'hora' => '20:00',
            'precio_base' => 3000.00,
            'cupo' => 55,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000010', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000011',
            'sala_id' => '00000001-0000-4000-8000-000000000003',
            'estado' => 'programada',
            'dias' => 23,
            'hora' => '19:00',
            'precio_base' => 2600.00,
            'cupo' => 60,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000012', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000012',
            'sala_id' => '00000001-0000-4000-8000-000000000003',
            'estado' => 'programada',
            'dias' => 30,
            'hora' => '21:00',
            'precio_base' => 3200.00,
            'cupo' => 50,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000002', 'estado' => 'elegida'],
            ],
        ],

        // Sala 4: Cine Club Areco (San Antonio de Areco, capacidad: 45)
        [
            'id' => '00000003-0000-4000-8000-000000000013',
            'sala_id' => '00000001-0000-4000-8000-000000000004',
            'estado' => 'programada',
            'dias' => 10,
            'hora' => '19:00',
            'precio_base' => 3500.00,
            'cupo' => 45,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000003', 'estado' => 'elegida'],
                ['obra_id' => '00000002-0000-4000-8000-000000000004', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000014',
            'sala_id' => '00000001-0000-4000-8000-000000000004',
            'estado' => 'programada',
            'dias' => 17,
            'hora' => '20:30',
            'precio_base' => 3500.00,
            'cupo' => 40,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000008', 'estado' => 'elegida'],
            ],
        ],
        [
            'id' => '00000003-0000-4000-8000-000000000015',
            'sala_id' => '00000001-0000-4000-8000-000000000004',
            'estado' => 'programada',
            'dias' => 24,
            'hora' => '18:30',
            'precio_base' => 3000.00,
            'cupo' => 45,
            'obras' => [
                ['obra_id' => '00000002-0000-4000-8000-000000000005', 'estado' => 'elegida'],
            ],
        ],
    ];

    private function __construct(
        private readonly RepositorioDeGeneros $generos,
        private readonly RepositorioDeSalas $salas,
        private readonly RepositorioDeObras $obras,
        private readonly RepositorioDeFunciones $funciones,
    ) {
    }

    /**
     * Sólo en desarrollo, como los usuarios de prueba de cuentas.
     *
     * @throws RuntimeException fuera de desarrollo
     */
    public static function para(
        Configuracion $configuracion,
        RepositorioDeGeneros $generos,
        RepositorioDeSalas $salas,
        RepositorioDeObras $obras,
        RepositorioDeFunciones $funciones,
    ): self {
        if (!$configuracion->enDesarrollo()) {
            throw new RuntimeException('Los datos de prueba se cargan sólo con entorno = "desarrollo".');
        }

        return new self($generos, $salas, $obras, $funciones);
    }

    /**
     * Agrega lo que falte, buscando cada cosa por su id. Correrlo de nuevo no duplica nada ni toca
     * lo que ya está: si alguien cambió algo a mano para probar, queda como lo dejó, y una función
     * que ya estaba conserva la fecha con la que se cargó.
     *
     * No va todo en una transacción, porque cada repositorio abre la suya: si se corta a mitad de
     * camino, correrlo de nuevo completa lo que faltó.
     *
     * @param DateTimeImmutable $hoy desde cuándo se cuentan las fechas de las funciones nuevas
     *
     * @return array<string, array{agregadas: int, yaEstaban: int}> por cada tipo de dato
     */
    public function cargar(DateTimeImmutable $hoy = new DateTimeImmutable('today')): array
    {
        return [
            'generos' => self::agregarLasQueFalten(self::generos(), $this->generos->buscar(...), $this->generos->agregar(...)),
            'salas' => self::agregarLasQueFalten(self::salas(), $this->salas->buscar(...), $this->salas->agregar(...)),
            'obras' => self::agregarLasQueFalten(self::obras(), $this->obras->buscar(...), $this->obras->agregar(...)),
            'funciones' => self::agregarLasQueFalten(self::funciones($hoy), $this->funciones->buscar(...), $this->funciones->agregar(...)),
        ];
    }

    /**
     * @template T of object
     *
     * @param list<T>              $todas
     * @param callable(string): ?T $buscar
     * @param callable(T): void    $agregar
     *
     * @return array{agregadas: int, yaEstaban: int}
     */
    private static function agregarLasQueFalten(array $todas, callable $buscar, callable $agregar): array
    {
        $agregadas = 0;
        foreach ($todas as $una) {
            if ($buscar($una->id) === null) {
                $agregar($una);
                $agregadas++;
            }
        }

        return ['agregadas' => $agregadas, 'yaEstaban' => count($todas) - $agregadas];
    }

    /**
     * Carpeta raíz de los archivos de prueba en el subsistema.
     */
    public static function carpetaDatos(): string
    {
        return dirname(__DIR__, 2) . '/datos-prueba';
    }

    public static function carpetaAfiches(): string
    {
        return self::carpetaDatos() . '/afiches';
    }

    public static function carpetaSalas(): string
    {
        return self::carpetaDatos() . '/salas';
    }

    public static function rutaAfiche(string $nombre): string
    {
        return self::carpetaAfiches() . '/' . $nombre;
    }

    public static function rutaSala(string $nombre): string
    {
        return self::carpetaSalas() . '/' . $nombre;
    }

    /**
     * Devuelve los 8 géneros como objetos de dominio.
     *
     * @return list<Genero>
     */
    public static function generos(): array
    {
        return array_map(static fn (array $g): Genero => new Genero($g['id'], $g['nombre'], $g['descripcion']), self::GENEROS);
    }

    /**
     * Devuelve las 4 salas como objetos de dominio.
     *
     * @return list<Sala>
     */
    public static function salas(?string $organizadorId = null): array
    {
        $orgId = $organizadorId ?? self::ORGANIZADOR_DEFECTO_ID;

        return array_map(
            static fn (array $s): Sala => new Sala(
                $s['id'],
                $orgId,
                new DatosDeSala(
                    $s['nombre'],
                    $s['descripcion'],
                    $s['direccion'],
                    $s['localidad'],
                    $s['capacidad'],
                    $s['peliculas_por_funcion'],
                    $s['duracion_funcion'],
                    $s['tiempo_entre_funciones'],
                ),
                $s['imagen'],
                $s['habilitada'],
            ),
            self::SALAS,
        );
    }

    /**
     * Devuelve las 12 obras como objetos de dominio.
     *
     * @return list<Obra>
     */
    public static function obras(?string $duenoId = null): array
    {
        $dId = $duenoId ?? self::DUENO_DEFECTO_ID;
        $generosMap = array_column(self::GENEROS, 'id', 'nombre');

        return array_map(
            static function (array $o) use ($dId, $generosMap): Obra {
                $generosIds = array_map(
                    static fn (string $nombre): string => $generosMap[$nombre] ?? throw new RuntimeException("Género desconocido: {$nombre}"),
                    $o['generos'],
                );

                return new Obra(
                    $o['id'],
                    $dId,
                    $o['titulo'],
                    $o['sinopsis'],
                    $o['anio'],
                    $o['director'],
                    $o['duracion'],
                    TipoObra::from($o['tipo']),
                    OrigenCopia::from($o['origen_copia']),
                    $generosIds,
                    $o['afiche'],
                );
            },
            self::OBRAS,
        );
    }

    /**
     * Devuelve las 15 funciones futuras como objetos de dominio, con la fecha contada desde $hoy.
     *
     * @return list<Funcion>
     */
    public static function funciones(DateTimeImmutable $hoy = new DateTimeImmutable('today')): array
    {
        return array_map(
            static function (array $f) use ($hoy): Funcion {
                $obrasIds = array_column($f['obras'], 'obra_id');
                [$hora, $minutos] = array_map(intval(...), explode(':', $f['hora']));

                return new Funcion(
                    $f['id'],
                    $f['sala_id'],
                    $obrasIds,
                    $f['precio_base'],
                    $f['cupo'],
                    EstadoFuncion::from($f['estado']),
                    $hoy->modify("+{$f['dias']} days")->setTime($hora, $minutos),
                );
            },
            self::FUNCIONES,
        );
    }
}
