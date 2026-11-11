#!/bin/bash
# Crea el esquema, el usuario y los permisos de un subsistema nuevo en una base que ya tiene datos,
# como lo hacen mysql/init/01 y 02 con el volumen vacío. Es para los servidores, donde mysql/init ya
# no vuelve a correr (EF-01.6): el subsistema nuevo no aparece solo.
#
#   docker compose exec mysql bash /herramientas/crear-esquema.sh moderacion
#
# Antes, la clave del usuario tiene que estar en .env (MYSQL_CLAVE_<SUBSISTEMA>) y el contenedor de
# MySQL tiene que haberse recreado para leerla. Correrlo de nuevo no cambia nada: CREATE … IF NOT
# EXISTS y los GRANT se pueden repetir. Después van sus migraciones, con migrar.sh.
#
# Entra como root, que sólo puede desde adentro del contenedor (MYSQL_ROOT_HOST: localhost).

set -euo pipefail

subsistema=${1:-}
case $subsistema in
	cuentas | programacion | funciones | moderacion) ;;
	*) echo "Uso: crear-esquema.sh <subsistema>, uno de cuentas, programacion, funciones o moderacion." >&2; exit 1 ;;
esac

variable="MYSQL_CLAVE_${subsistema^^}"
clave=${!variable:?falta $variable en el entorno de MySQL: agregala a .env y recreá el contenedor}
# Una cadena de SQL entre comillas simples, con las comillas y las barras de la clave escapadas,
# como sql_texto() de mysql/init/01.
clave=${clave//\\/\\\\}
clave="'${clave//\'/\'\'}'"

export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?falta MYSQL_ROOT_PASSWORD en el entorno de MySQL}"
mysql --user=root --database=mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${subsistema}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '${subsistema}'@'%' IDENTIFIED BY ${clave};
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${subsistema}\`.* TO '${subsistema}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER
	ON \`${subsistema}\`.* TO 'migraciones'@'localhost';
SQL
echo "Esquema y usuario de ${subsistema} listos. Ahora: bash /herramientas/migrar.sh ${subsistema}"
