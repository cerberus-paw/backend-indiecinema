#!/bin/bash
# Restaura un respaldo de respaldar.sh en una base limpia y cuenta lo que quedó (EF-18.5): es la
# «restauración probada» de la propuesta. Va FUERA del servidor, en una máquina que tiene la clave
# privada del respaldo en su gpg, con Docker:
#
#   bash herramientas/restaurar.sh indiecinema-2026-11-19-0400.tar.gpg
#
# Descifra, levanta un MySQL 8.4 descartable (sin puertos publicados ni volumen: se borra al
# terminar), carga base.sql y muestra cuántas filas tiene cada tabla, para compararlas con las del
# servidor el día del respaldo. Revisa también que los dos .tar de archivos se puedan leer y cuenta
# sus archivos. Con --dejar, el MySQL queda andando para mirarlo (docker exec -it … mysql).
#
# Para traer los archivos de vuelta a un servidor, después de probar: base.sql con
# «docker compose exec -T mysql sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot' < base.sql» y
# cada .tar con «docker compose exec -T <subsistema> tar -x -C /var/indiecinema/archivos».

set -euo pipefail

main() {
	local respaldo=${1:-} dejar=${2:-}
	if [ ! -f "$respaldo" ]; then
		echo "Uso: bash herramientas/restaurar.sh <respaldo .tar.gpg> [--dejar]" >&2
		exit 1
	fi

	local trabajo contenedor
	trabajo=$(mktemp -d)
	contenedor="indiecinema-restauracion-$$"
	# Expandido ya: el trap corre después de main, sin sus variables locales.
	# shellcheck disable=SC2064
	trap "rm -rf '$trabajo'; [ '$dejar' = --dejar ] || docker rm -f '$contenedor' >/dev/null 2>&1" EXIT

	gpg --batch --quiet --decrypt "$respaldo" | gunzip | tar -x -C "$trabajo"
	echo "Descifrado:"
	(cd "$trabajo" && LC_ALL=C du -h base.sql archivos-*.tar | sed 's/^/  /')

	local subsistema
	for subsistema in programacion moderacion; do
		echo "archivos-$subsistema: $(tar -tf "$trabajo/archivos-$subsistema.tar" | grep -vc '/$') archivos"
	done

	docker run -d --name "$contenedor" -e MYSQL_ROOT_PASSWORD=restauracion mysql:8.4 >/dev/null
	local _
	for _ in {1..60}; do
		docker exec "$contenedor" mysqladmin --protocol=tcp -h127.0.0.1 -uroot -prestauracion ping --silent >/dev/null 2>&1 && break
		sleep 2
	done
	docker exec -i -e MYSQL_PWD=restauracion "$contenedor" mysql -uroot <"$trabajo/base.sql"

	echo "Filas por tabla:"
	docker exec -e MYSQL_PWD=restauracion "$contenedor" mysql -uroot --batch --skip-column-names -e "
		SELECT CONCAT(TABLE_SCHEMA, '.', TABLE_NAME) FROM information_schema.TABLES
		WHERE TABLE_SCHEMA IN ('cuentas', 'programacion', 'funciones', 'moderacion', 'recaudacion', 'control_migraciones')
		ORDER BY 1" | while read -r tabla; do
		printf '  %-45s %s\n' "$tabla" "$(docker exec -e MYSQL_PWD=restauracion "$contenedor" mysql -uroot --batch --skip-column-names -e "SELECT COUNT(*) FROM $tabla")"
	done
	[ "$dejar" = --dejar ] && echo "El MySQL queda andando: docker exec -it $contenedor mysql -uroot -prestauracion"
	return 0
}

main "$@"
