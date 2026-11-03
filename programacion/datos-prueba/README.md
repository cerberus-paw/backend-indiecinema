# Datos de prueba para programación

Juego de datos para demostración y pruebas del subsistema `programacion` de IndieCinema.

Cumple con los criterios de **E3-02.1**:
- **4 salas** en localidades distintas (`Luján`, `Mercedes`, `General Rodríguez` y `San Antonio de Areco`), todas habilitadas.
- **12 obras** con su afiche real, correspondientes a clásicos históricos del cine en **dominio público**.
- **15 funciones futuras** con cupo dentro de la capacidad de su sala, precio base y obras programadas.
- **Sin datos personales reales**: nombres de salas y direcciones ficticias, organizadores de prueba (`@indiecinema.test`), y figuras históricas del cine.
- **Imágenes de licencia libre**: afiches en **dominio público** e ilustraciones de salas bajo **Creative Commons Zero (CC0)**.

---

## 1. Salas (4 salas en distintas localidades)

Todas las salas están marcadas como `habilitada = true` para la cartelera y programación de funciones.

| ID | Nombre | Localidad | Dirección | Capacidad | Películas / Función | Duración (min) | Archivo Imagen |
|---|---|---|---|---|---|---|---|
| `00000001-0000-4000-8000-000000000001` | Cine Club El Alambique | **Luján** | San Martín 420 | 80 | 1 | 180 | `c1a1a001000000000000000000000001.jpg` |
| `00000001-0000-4000-8000-000000000002` | Espacio Cultural La Trocha | **Mercedes** | Calle 40 y 21 | 120 | 2 | 180 | `c1a1a002000000000000000000000002.jpg` |
| `00000001-0000-4000-8000-000000000003` | Cine Teatro Eureka | **General Rodríguez** | 25 de Mayo 312 | 60 | 1 | 110 | `c1a1a003000000000000000000000003.jpg` |
| `00000001-0000-4000-8000-000000000004` | Cine Club Areco | **San Antonio de Areco** | Alsina 184 | 45 | 2 | 90 | `c1a1a004000000000000000000000004.jpg` |

---

## 2. Obras (12 obras con afiche en dominio público)

Todas las obras cuentan con afiche versionado en `afiches/`, con formato `[0-9a-f]{32}.jpg`.

| ID | Título | Tipo | Año | Director | Duración | Géneros | Origen copia | Archivo Afiche |
|---|---|---|---|---|---|---|---|---|
| `00000002-0000-4000-8000-000000000001` | Metrópolis | Película | 1927 | Fritz Lang | 153 min | Ciencia ficción, Drama | Dominio público | `af1c0001000000000000000000000001.jpg` |
| `00000002-0000-4000-8000-000000000002` | Nosferatu | Película | 1922 | F. W. Murnau | 94 min | Terror, Suspenso | Dominio público | `af1c0002000000000000000000000002.jpg` |
| `00000002-0000-4000-8000-000000000003` | Viaje a la Luna | Cortometraje | 1902 | Georges Méliès | 13 min | Ciencia ficción, Fantasía | Dominio público | `af1c0003000000000000000000000003.jpg` |
| `00000002-0000-4000-8000-000000000004` | El gabinete del Dr. Caligari | Película | 1920 | Robert Wiene | 77 min | Terror, Suspenso | Dominio público | `af1c0004000000000000000000000004.jpg` |
| `00000002-0000-4000-8000-000000000005` | El maquinista de la General | Película | 1926 | Buster Keaton | 79 min | Comedia, Aventura | Dominio público | `af1c0005000000000000000000000005.jpg` |
| `00000002-0000-4000-8000-000000000006` | El acorazado Potemkin | Película | 1925 | Serguéi Eisenstein | 75 min | Drama, Aventura | Dominio público | `af1c0006000000000000000000000006.jpg` |
| `00000002-0000-4000-8000-000000000007` | La noche de los muertos vivientes | Película | 1968 | George A. Romero | 96 min | Terror, Suspenso | Dominio público | `af1c0007000000000000000000000007.jpg` |
| `00000002-0000-4000-8000-000000000008` | Sherlock Jr. | Película | 1924 | Buster Keaton | 45 min | Comedia | Dominio público | `af1c0008000000000000000000000008.jpg` |
| `00000002-0000-4000-8000-000000000009` | El hombre de la cámara | Película | 1929 | Dziga Vértov | 68 min | Documental, Drama | Dominio público | `af1c0009000000000000000000000009.jpg` |
| `00000002-0000-4000-8000-000000000010` | El fantasma de la ópera | Película | 1925 | Rupert Julian | 93 min | Terror, Drama | Dominio público | `af1c0010000000000000000000000010.jpg` |
| `00000002-0000-4000-8000-000000000011` | Viaje a través de lo imposible | Cortometraje | 1904 | Georges Méliès | 20 min | Ciencia ficción, Aventura | Dominio público | `af1c0011000000000000000000000011.jpg` |
| `00000002-0000-4000-8000-000000000012` | El pibe | Película | 1921 | Charles Chaplin | 68 min | Comedia, Drama | Dominio público | `af1c0012000000000000000000000012.jpg` |

---

## 3. Funciones (15 funciones futuras)

Distribuidas entre las 4 salas, con cupo menor o igual a la capacidad de la sala y obras que entran en la duración de su función. La fecha no es fija: se cuenta desde el día en que se cargan los datos, así siempre son futuras.

| ID | Sala | Cuándo | Cupo | Precio | Obras programadas |
|---|---|---|---|---|---|
| `00000003-0000-4000-8000-000000000001` | Cine Club El Alambique (Luján) | en 7 días, 20:00 | 80 / 80 | $ 2.500 | Metrópolis |
| `00000003-0000-4000-8000-000000000002` | Cine Club El Alambique (Luján) | en 14 días, 21:00 | 75 / 80 | $ 2.200 | Nosferatu |
| `00000003-0000-4000-8000-000000000003` | Cine Club El Alambique (Luján) | en 21 días, 20:30 | 80 / 80 | $ 2.500 | El gabinete del Dr. Caligari |
| `00000003-0000-4000-8000-000000000004` | Cine Club El Alambique (Luján) | en 28 días, 19:30 | 70 / 80 | $ 2.000 | El maquinista de la General |
| `00000003-0000-4000-8000-000000000005` | Espacio Cultural La Trocha (Mercedes) | en 8 días, 19:00 | 120 / 120 | $ 0 (a la gorra) | Viaje a la Luna + Metrópolis |
| `00000003-0000-4000-8000-000000000006` | Espacio Cultural La Trocha (Mercedes) | en 15 días, 20:00 | 100 / 120 | $ 1.800 | El acorazado Potemkin |
| `00000003-0000-4000-8000-000000000007` | Espacio Cultural La Trocha (Mercedes) | en 22 días, 21:30 | 120 / 120 | $ 2.400 | La noche de los muertos vivientes |
| `00000003-0000-4000-8000-000000000008` | Espacio Cultural La Trocha (Mercedes) | en 29 días, 18:30 | 110 / 120 | $ 0 (a la gorra) | Viaje a través de lo imposible + Sherlock Jr. |
| `00000003-0000-4000-8000-000000000009` | Cine Teatro Eureka (Gral. Rodríguez) | en 9 días, 18:00 | 60 / 60 | $ 2.800 | El hombre de la cámara |
| `00000003-0000-4000-8000-000000000010` | Cine Teatro Eureka (Gral. Rodríguez) | en 16 días, 20:00 | 55 / 60 | $ 3.000 | El fantasma de la ópera |
| `00000003-0000-4000-8000-000000000011` | Cine Teatro Eureka (Gral. Rodríguez) | en 23 días, 19:00 | 60 / 60 | $ 2.600 | El pibe |
| `00000003-0000-4000-8000-000000000012` | Cine Teatro Eureka (Gral. Rodríguez) | en 30 días, 21:00 | 50 / 60 | $ 3.200 | Nosferatu |
| `00000003-0000-4000-8000-000000000013` | Cine Club Areco (San Antonio de Areco) | en 10 días, 19:00 | 45 / 45 | $ 3.500 | Viaje a la Luna + El gabinete del Dr. Caligari |
| `00000003-0000-4000-8000-000000000014` | Cine Club Areco (San Antonio de Areco) | en 17 días, 20:30 | 40 / 45 | $ 3.500 | Sherlock Jr. |
| `00000003-0000-4000-8000-000000000015` | Cine Club Areco (San Antonio de Areco) | en 24 días, 18:30 | 45 / 45 | $ 3.000 | El maquinista de la General |

---

## 4. Licencias y Reproducibilidad

- Los afiches son los originales de cada película, o un fotograma cuando Commons no tiene el afiche, todos en **dominio público** y bajados de Wikimedia Commons (lista abajo). Los fotogramas van recortados, apaisados y sin bandas negras.
- Las fotos de salas son creaciones visuales originales para IndieCinema bajo licencia **CC0 1.0 Universal (Public Domain Dedication)**.
- El script reproductor de las fotos de salas está versionado en `generar_imagenes.py` (también dibuja afiches, que ya no se usan: no pisar los de `afiches/`). Necesita Python 3 con Pillow y las fuentes Open Sans Condensed ExtraBold, Noto Serif y Adwaita Sans (todas con licencia libre) en las rutas que figuran al principio del script; si falta alguna, frena en vez de usar otra.

### Origen de los afiches

| Afiche | Obra | Qué es | En Wikimedia Commons |
|---|---|---|---|
| `af1c0001000000000000000000000001.jpg` | Metrópolis | afiche de Boris Bilinsky | [File:Boris Bilinski (1900-1948) Plakat für den Film Metropolis (1).jpg](https://commons.wikimedia.org/wiki/File:Boris_Bilinski_%281900-1948%29_Plakat_für_den_Film_Metropolis_%281%29.jpg) |
| `af1c0002000000000000000000000002.jpg` | Nosferatu | fotograma: Orlok en la puerta | [File:Nosferatu (1922) - Count Orlok Standing In A Wooden Doorway.png](https://commons.wikimedia.org/wiki/File:Nosferatu_%281922%29_-_Count_Orlok_Standing_In_A_Wooden_Doorway.png) |
| `af1c0003000000000000000000000003.jpg` | Viaje a la Luna | fotograma coloreado de la Luna | [File:Melies color Voyage dans la lune.jpg](https://commons.wikimedia.org/wiki/File:Melies_color_Voyage_dans_la_lune.jpg) |
| `af1c0004000000000000000000000004.jpg` | El gabinete del Dr. Caligari | afiche de Rudolf Ledl / Fritz Bernhard | [File:Das Cabinet des Dr. Caligari.JPG](https://commons.wikimedia.org/wiki/File:Das_Cabinet_des_Dr._Caligari.JPG) |
| `af1c0005000000000000000000000005.jpg` | El maquinista de la General | afiche de United Artists | [File:The General (1926) - Movie Poster 2.png](https://commons.wikimedia.org/wiki/File:The_General_%281926%29_-_Movie_Poster_2.png) |
| `af1c0006000000000000000000000006.jpg` | El acorazado Potemkin | afiche soviético | [File:Vintage Potemkin.jpg](https://commons.wikimedia.org/wiki/File:Vintage_Potemkin.jpg) |
| `af1c0007000000000000000000000007.jpg` | La noche de los muertos vivientes | afiche | [File:Night Of The Living Dead (1968) - Poster.jpg](https://commons.wikimedia.org/wiki/File:Night_Of_The_Living_Dead_%281968%29_-_Poster.jpg) |
| `af1c0008000000000000000000000008.jpg` | Sherlock Jr. | afiche de Metro | [File:Sherlock jr poster.jpg](https://commons.wikimedia.org/wiki/File:Sherlock_jr_poster.jpg) |
| `af1c0009000000000000000000000009.jpg` | El hombre de la cámara | fotograma: la cámara y el camarógrafo | [File:Man with a Movie Camera by Dziga Vertov.jpg](https://commons.wikimedia.org/wiki/File:Man_with_a_Movie_Camera_by_Dziga_Vertov.jpg) |
| `af1c0010000000000000000000000010.jpg` | El fantasma de la ópera | afiche de Universal | [File:The Phantom of the Opera (1925).jpg](https://commons.wikimedia.org/wiki/File:The_Phantom_of_the_Opera_%281925%29.jpg) |
| `af1c0011000000000000000000000011.jpg` | Viaje a través de lo imposible | fotograma: el título de Star Film | [File:Méliès, Le voyage a travers l'impossible1904 colorizée 01.jpg](https://commons.wikimedia.org/wiki/File:Méliès,_Le_voyage_a_travers_l'impossible1904_colorizée_01.jpg) |
| `af1c0012000000000000000000000012.jpg` | El pibe | afiche de First National | [File:The Kid (1921) poster.jpg](https://commons.wikimedia.org/wiki/File:The_Kid_%281921%29_poster.jpg) |

- No requiere dependencias externas en tiempo de ejecución de la aplicación web: los archivos JPEG ya se encuentran generados y versionados en el repositorio.
