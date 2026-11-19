# Cómo llega el reporte a cada recomendación

Así funcionan las recomendaciones de `/recaudacion/reportes` (EF-09 y EF-22.3). Están escritas para poder contestar «¿cómo llegaste a eso?». El código está en `recaudacion/src/Servicios/Sugerencias.php`, y cada umbral está en `[reportes]` de `recaudacion/config/config.ini` (si falta, vale el de `config.ejemplo.ini`).

## Las variables

Recaudación no guarda funciones ni reservas: las pide por la API interna y las cruza.

| Variable | De dónde sale |
|---|---|
| Sala, día de la semana y hora de cada función | programación (`/interno/funciones/finalizadas`) |
| Precio: con precio o a la gorra | programación |
| Votación de títulos: qué obra ganó y cuántos votos sacó cada una | programación |
| Quiénes votaron | programación |
| Lugares, lugares vendidos y cuántos fueron | funciones (`/interno/cobros`) |
| Quiénes reservaron | funciones |
| Calificaciones: el promedio y cuántas hubo | funciones |
| Lo cobrado y cómo se repartió (EF-22.1) | el reparto de recaudación |

Las cuentas se hacen con enteros: un porcentaje se compara multiplicando en cruz, así 36 de 40 es el 90 % justo.

## Las de una variable

Cada una mira una sola cosa de una sala, o de todas. Son la base de las combinadas.

| Recomendación | Regla | Umbrales |
|---|---|---|
| Día para una promoción | Un día de la semana con varias funciones vendió hasta el umbral y menos que el resto de la semana en la misma sala. | `dia_flojo_ocupacion_maxima` (50), `dia_flojo_funciones_minimas` (2) |
| Título para repetir | La obra llenó, fue la gente que reservó y gustó, sumado en todas sus funciones. | `repetir_ocupacion_minima` (90), `repetir_asistencia_minima` (80), `repetir_promedio_minimo` (4) |
| Lo que el público pidió y no vio | La obra perdió votaciones con buena parte de los votos y no se proyectó en el período. | `pedido_votos_minimos` (30) |
| A la gorra o con precio | La sala dio de los dos tipos de función. Si a la gorra llena muchos puntos más, el público es sensible al precio (conviene un descuento). Si con precio llena igual o más, se puede cobrar. | `gorra_diferencia_minima` (20), `gorra_funciones_minimas` (2) |
| Votaron y no reservaron | Personas de la sala que votaron títulos y no reservaron ninguna función. | `votaron_sin_reservar_minimos` (5) |
| Sumá una función | Una función de la obra vendió casi todos los lugares. | `agotada_ocupacion_minima` (95) |

## Las combinadas (EF-22.3)

El profe pidió el 18/11 «mezclar los ingredientes». Cada combinada parte de un **día flojo** de una sala y le suma otra cosa de la misma sala, porque el que tiene que venir ese día es su público y no el de otra. Cada una muestra el porqué: todos los datos que la dispararon.

### Dar lo pedido a la gorra el día flojo

- **Cuándo:** las tres condiciones juntas.
  1. Un día es flojo en la sala.
  2. El público de esa sala votó una obra que no ganó y no la vio («lo que el público pidió»).
  3. En esa sala, a la gorra llena por lo menos `gorra_diferencia_minima` puntos más que con precio.
- **Qué sugiere:** dar ese título a la gorra ese día.
- **Por qué:** cruza el día, la ocupación, la votación y el precio. La gorra se sugiere porque en esa sala ya funcionó, que es como pidió el profe que se justifique.

### Cambiar el horario del día flojo

- **Cuándo:** las tres condiciones juntas.
  1. Un día es flojo en la sala.
  2. En la sala, una franja horaria llena por lo menos `franja_diferencia_minima` puntos más que la otra, con al menos `franja_funciones_minimas` funciones en cada una. Las franjas se separan en `franja_corte_hora`: antes es la tarde y desde esa hora, la noche.
  3. La mayoría de las funciones del día flojo están en la franja que llena menos.
- **Qué sugiere:** pasar las funciones de ese día a la otra franja.
- **Por qué:** cruza el día, la hora y la ocupación. Si el día está flojo porque se da a una hora a la que el público no va, una promoción no lo arregla.

### Lo que se agota, también el día flojo

- **Cuándo:** las tres condiciones juntas.
  1. Un día es flojo en la sala.
  2. Una obra agotó alguna función en esa misma sala.
  3. El promedio de las calificaciones de esa obra llega a `repetir_promedio_minimo`.
- **Qué sugiere:** sumar una función de esa obra el día flojo. Si hay varias, sale la que más se agotó.
- **Por qué:** cruza la demanda que no entró, lo que le pareció al público y el día que sobra lugar.

## Lo que queda afuera

- El género y la categoría de la obra, y el perfil del espectador: programación no los manda en la API interna de finalizadas. Son el próximo ingrediente.
- La capacidad de la sala ya está en la ocupación, que es en porcentaje de los lugares.
