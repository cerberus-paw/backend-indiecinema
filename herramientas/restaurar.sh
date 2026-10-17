#!/bin/bash
# Restaura un respaldo de respaldo-diario.sh en el entorno de esta carpeta: la base y, si se pasa,
# el archivo con las fotos y los afiches. Pisa lo que haya en las tablas que trae el respaldo.
#
#   herramientas/restaurar.sh indiecinema-<fecha>.sql.gz [programacion-<fecha>.tar.gz]
#
# Necesita el entorno levantado (docker compose up -d) y MySQL aceptando conexiones: el volcado
# trae las tablas y los datos, pero los usuarios de cada subsistema y sus permisos los crea
# mysql/init cuando MySQL arranca con el volumen vacío, como en cualquier instalación nueva.

set -euo pipefail

if [ $# -lt 1 ] || [ $# -gt 2 ]; then
	echo "Uso: $0 indiecinema-<fecha>.sql.gz [programacion-<fecha>.tar.gz]" >&2
	exit 1
fi
base=$(realpath "$1")
archivos=${2:+$(realpath "$2")}
cd "$(dirname "$0")/.."

gzip -t "$base"
# Las comillas simples son a propósito: la clave de root se expande adentro del contenedor.
# shellcheck disable=SC2016
gzip -dc "$base" | docker compose exec -T mysql bash -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root'
echo "Base restaurada desde $(basename "$base")"

if [ -n "$archivos" ]; then
	gzip -t "$archivos"
	docker compose exec -T programacion tar -C /var/indiecinema/archivos -xzf - <"$archivos"
	echo "Archivos restaurados desde $(basename "$archivos")"
fi
