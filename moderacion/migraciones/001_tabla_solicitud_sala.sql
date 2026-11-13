-- La solicitud de sala (E2, modelo de objetos de moderación: SolicitudSala): el trámite con el
-- que un usuario pide abrir una sala (EF-07.2). Vivía en programación; se mudó a moderación, que
-- no tiene acceso al esquema de las salas.
--
-- Guarda los datos propuestos de la sala, con los largos de la tabla sala de programación: la
-- sala recién existe allá cuando se aprueba la solicitud, y moderación la crea por la API interna
-- con el id que ya guardó acá (sala_id, sin clave foránea porque es de otro esquema). Corregir una
-- rechazada es una solicitud nueva con el mismo sala_id; una sala nueva lleva uno nuevo.
--
-- imagen es el nombre al azar del archivo en el volumen de moderación. Es nula en las que se
-- mudaron de programación: su foto quedó en la sala de allá.
--
-- solicitante_nombre es el nombre al mandarla, que llega de nginx con la sesión: la lista y el
-- buscador de la bandeja lo usan sin pedirle a cuentas uno por uno.
--
-- Una sala tiene como mucho una pendiente: «pendiente_de» vale su id mientras la solicitud está
-- pendiente y NULL después, y el índice único no cuenta los NULL. Lo controla la base, así dos
-- envíos a la vez no dejan dos.
--
-- Las fechas las pone la aplicación, en UTC.
CREATE TABLE solicitud_sala (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    solicitante_nombre VARCHAR(100) NULL,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(2000) NOT NULL,
    direccion VARCHAR(150) NOT NULL,
    localidad VARCHAR(100) NOT NULL,
    capacidad SMALLINT UNSIGNED NOT NULL,
    peliculas_por_funcion TINYINT UNSIGNED NOT NULL,
    -- En minutos.
    duracion_funcion SMALLINT UNSIGNED NOT NULL,
    tiempo_entre_funciones SMALLINT UNSIGNED NOT NULL,
    imagen VARCHAR(64) CHARACTER SET ascii NULL,
    estado ENUM('pendiente', 'aprobada', 'rechazada') NOT NULL DEFAULT 'pendiente',
    enviada_en DATETIME NOT NULL,
    -- La resolución: quién, cuándo y el motivo, que se le muestra al solicitante.
    resuelta_por CHAR(36) CHARACTER SET ascii NULL,
    resuelta_en DATETIME NULL,
    motivo VARCHAR(1000) NULL,
    pendiente_de CHAR(36) CHARACTER SET ascii AS (IF(estado = 'pendiente', sala_id, NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE INDEX una_pendiente_por_sala (pendiente_de),
    -- La bandeja: las pendientes, de la más vieja a la más nueva, y el historial.
    INDEX solicitud_por_estado (estado, enviada_en),
    -- La última de un usuario, para mostrarle en qué quedó.
    INDEX solicitud_por_usuario (usuario_id, enviada_en),
    INDEX solicitud_por_sala (sala_id, enviada_en),
    -- Resuelta es con fecha y con motivo; pendiente, sin fecha.
    CONSTRAINT solicitud_sala_resuelta CHECK ((estado = 'pendiente') = (resuelta_en IS NULL)),
    CONSTRAINT solicitud_sala_con_motivo CHECK (resuelta_en IS NULL OR motivo IS NOT NULL),
    -- La aplicación ya lo valida; esto cuida la tabla si alguien escribe por otro lado.
    CONSTRAINT solicitud_sala_capacidad_positiva CHECK (capacidad > 0),
    CONSTRAINT solicitud_sala_peliculas_positivas CHECK (peliculas_por_funcion > 0),
    CONSTRAINT solicitud_sala_duracion_positiva CHECK (duracion_funcion > 0)
) ENGINE = InnoDB;
