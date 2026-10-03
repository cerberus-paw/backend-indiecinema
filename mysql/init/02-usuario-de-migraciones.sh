# El usuario de las migraciones: el único que puede crear, alterar y borrar tablas. La aplicación
# nunca lo usa (cada subsistema se conecta con el suyo, que sólo toca datos) y sólo entra desde
# adentro del contenedor de MySQL ('localhost'), que es donde corre mysql/herramientas/migrar.sh.
#
# Lo que ya se aplicó se anota en un esquema aparte, control_migraciones, que no ve ningún
# subsistema: así la aplicación no puede borrar el registro y hacer que una migración se repita.

# sql_texto() viene de 01-esquemas-y-usuarios.sh: la imagen incluye los dos en la misma shell.
clave=${MYSQL_CLAVE_MIGRACIONES:?falta MYSQL_CLAVE_MIGRACIONES en el entorno de MySQL}

mysql_note "Usuario de migraciones"
docker_process_sql --database=mysql <<-SQL
	CREATE DATABASE IF NOT EXISTS control_migraciones CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
	CREATE TABLE IF NOT EXISTS control_migraciones.aplicadas (
		subsistema  VARCHAR(30)  NOT NULL,
		archivo     VARCHAR(100) NOT NULL,
		-- SHA-256 del archivo: si alguien lo edita después de aplicarlo, migrar.sh lo avisa.
		huella      CHAR(64)     NOT NULL,
		aplicada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (subsistema, archivo)
	);

	CREATE USER IF NOT EXISTS 'migraciones'@'localhost' IDENTIFIED BY $(sql_texto "$clave");
	GRANT SELECT, INSERT ON control_migraciones.aplicadas TO 'migraciones'@'localhost';
	GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER
		ON \`cuentas\`.* TO 'migraciones'@'localhost';
	GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER
		ON \`programacion\`.* TO 'migraciones'@'localhost';
	GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER
		ON \`funciones\`.* TO 'migraciones'@'localhost';
SQL
