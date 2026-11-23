-- EF-22.10: devolver una solicitud (013) deja su fila del checklist, como las otras resoluciones.
ALTER TABLE revision_requisitos
    MODIFY COLUMN resolucion ENUM('aprobada', 'rechazada', 'en_revision_manual', 'devuelta') NOT NULL;
