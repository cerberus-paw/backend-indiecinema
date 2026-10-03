# Esquemas y usuarios de la aplicación, con mínimo privilegio (E2, capa de base de datos).
#
# Lo corre la imagen de MySQL una sola vez, cuando arranca con el volumen vacío. Es un .sh y no
# un .sql porque las claves salen de las variables de entorno del compose (que las toma de .env):
# así ninguna clave queda en el repositorio. La imagen lo incluye con «.» (no es ejecutable), y
# por eso puede usar su función docker_process_sql, que ya se conecta como root.
#
# Cada subsistema tiene su esquema y un usuario que sólo lee y escribe datos de ese esquema: sin
# CREATE, ALTER ni DROP (las tablas las crean las migraciones, con otro usuario) y sin acceso a
# los esquemas de los demás. El usuario puede entrar desde cualquier host ('%') porque MySQL no
# publica ningún puerto: sólo lo alcanzan los contenedores de la red interna.

# Una cadena de SQL entre comillas simples, con las comillas y las barras de la clave escapadas.
sql_texto() {
	local valor=${1//\\/\\\\}
	printf "'%s'" "${valor//\'/\'\'}"
}

for subsistema in cuentas programacion funciones; do
	variable="MYSQL_CLAVE_${subsistema^^}"
	clave=${!variable:?falta $variable en el entorno de MySQL}

	mysql_note "Esquema y usuario de ${subsistema}"
	docker_process_sql --database=mysql <<-SQL
		CREATE DATABASE IF NOT EXISTS \`${subsistema}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
		CREATE USER IF NOT EXISTS '${subsistema}'@'%' IDENTIFIED BY $(sql_texto "$clave");
		GRANT SELECT, INSERT, UPDATE, DELETE ON \`${subsistema}\`.* TO '${subsistema}'@'%';
	SQL
done
