-- Rellena el título normalizado de los pedidos que ya estaban registrados antes de la columna
-- (EF-22.6).
UPDATE pedido_titulo
SET titulo_normalizado = COALESCE(
    NULLIF(TRIM(REGEXP_REPLACE(LOWER(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            titulo,
            'Á', 'a'), 'É', 'e'), 'Í', 'i'), 'Ó', 'o'), 'Ú', 'u'), 'Ü', 'u'), 'Ñ', 'n'),
            'á', 'a'), 'é', 'e'), 'í', 'i'), 'ó', 'o'), 'ú', 'u'), 'ü', 'u'), 'ñ', 'n')
    ), '[^[:alnum:]]+', ' ')), ''),
    TRIM(LOWER(titulo))
)
WHERE titulo_normalizado = '';
