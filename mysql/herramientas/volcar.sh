#!/bin/bash
# Vuelca la base entera por la salida estándar: los tres esquemas de la aplicación y el control de
# migraciones, para que al restaurar migrar.sh sepa qué ya está aplicado. Lo usa
# herramientas/respaldo-diario.sh, que lo comprime y lo sube fuera del servidor:
#
#   docker compose exec -T mysql bash /herramientas/volcar.sh > base.sql
#
# Corre adentro del contenedor de MySQL y entra como root, que sólo existe ahí (MYSQL_ROOT_HOST es
# localhost): así el respaldo no necesita un usuario ni una clave más. Con --single-transaction
# el volcado es una foto consistente de las tablas InnoDB sin bloquear el sitio mientras corre.
# La última línea es «-- Dump completed …»: si falta, el volcado se cortó.

set -euo pipefail

export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?falta MYSQL_ROOT_PASSWORD en el entorno de MySQL}"
exec mysqldump --user=root \
	--single-transaction --quick --routines --triggers --events \
	--set-gtid-purged=OFF --no-tablespaces \
	--databases cuentas programacion funciones control_migraciones
