-- Adhesiones de espectadores a pedidos de títulos ausentes (EF-11.1 / IC-576).
-- La clave primaria compuesta impide duplicar adhesiones por usuario y pedido aunque lleguen
-- dos solicitudes simultáneas.
CREATE TABLE adhesion_pedido (
    pedido_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pedido_id, usuario_id),
    UNIQUE INDEX una_adhesion_por_usuario_y_pedido (usuario_id, pedido_id),
    CONSTRAINT adhesion_de_pedido FOREIGN KEY (pedido_id) REFERENCES pedido_titulo (id) ON DELETE CASCADE
) ENGINE = InnoDB;
