-- Pedidos de títulos ausentes en el catálogo de IndieCinema
-- (EF-11 / IC-575, EF-11.1 / IC-576, EF-11.2 / IC-577, EF-11.3 / IC-578).
CREATE TABLE pedido_titulo (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    director VARCHAR(100) NOT NULL,
    creado_por CHAR(36) CHARACTER SET ascii NOT NULL,
    estado ENUM('abierto', 'activo', 'tomado') NOT NULL DEFAULT 'abierto',
    activado_en DATETIME NULL,
    obra_id CHAR(36) CHARACTER SET ascii NULL,
    tomado_por CHAR(36) CHARACTER SET ascii NULL,
    tomado_en DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX pedido_por_titulo_y_anio (titulo, anio),
    INDEX pedido_por_estado (estado, activado_en, creado_en),
    INDEX pedido_por_obra (obra_id),
    CONSTRAINT pedido_de_obra FOREIGN KEY (obra_id) REFERENCES obra (id),
    CONSTRAINT pedido_titulo_no_vacio CHECK (TRIM(titulo) <> ''),
    CONSTRAINT pedido_director_no_vacio CHECK (TRIM(director) <> ''),
    CONSTRAINT pedido_anio_valido CHECK (anio >= 1888),
    CONSTRAINT pedido_tomado_completo CHECK (estado <> 'tomado' OR (obra_id IS NOT NULL AND tomado_por IS NOT NULL))
) ENGINE = InnoDB;
