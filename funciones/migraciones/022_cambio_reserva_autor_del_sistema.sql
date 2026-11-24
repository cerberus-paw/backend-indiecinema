-- Un cambio del sistema no tiene usuario, y uno de un usuario no puede quedar sin él (EF-18.6).
ALTER TABLE cambio_reserva
    ADD CONSTRAINT cambio_reserva_autor_del_sistema CHECK ((autor_rol = 'sistema') = (autor_id IS NULL));
