-- «Tomar» (EF-22.11): el moderador dice que las denuncias de una sala o una función son válidas sin
-- sancionar todavía. Quedan resueltas con la acción tomada, que no toca otro subsistema y cuenta
-- para las reglas. Los CHECK siguen valiendo: es una resuelta con acción.
ALTER TABLE denuncia
    MODIFY COLUMN accion ENUM('suspender', 'rechazar', 'ocultar', 'tomada') NULL;
