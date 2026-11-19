#!/bin/bash
# El respaldo diario de un servidor (EF-18.5, lo que prometió la propuesta): la base de cada
# subsistema y los archivos que subieron los usuarios, cifrados y guardados fuera del VPS. Lo corre
# el cron (herramientas/crontab) a las 4; también se puede correr a mano, desde cualquier carpeta:
#
#   bash herramientas/respaldar.sh
#
# Qué guarda, en un solo .tar cifrado con gpg:
#   - base.sql: mysqldump de los cinco esquemas y control_migraciones, en una transacción, así sale
#     un estado consistente aunque el sitio siga recibiendo pedidos;
#   - archivos-programacion.tar y archivos-moderacion.tar: los volúmenes con las fotos, los afiches
#     y la documentación de las salas, leídos desde el contenedor de su subsistema.
#
# Se cifra con la CLAVE PÚBLICA de ~/.config/indiecinema/respaldo.asc: en el servidor no hay con qué
# descifrarlo. La privada la tiene el grupo, fuera del servidor, y es la que usa restaurar.sh. Un
# respaldo robado del servidor o del destino no sirve sin ella (la propuesta: «filtración de un
# respaldo»).
#
# Dónde queda: los últimos DIAS en ~/respaldos/diarios del servidor, y una copia fuera si existe
# ~/.config/indiecinema/respaldo-destino, con una línea:
#   s3://bucket/carpeta    con la AWS CLI y las credenciales del servidor (aws configure)
#   usuario@host:carpeta   con scp y la clave SSH del usuario del despliegue
# Sin destino, el respaldo queda sólo en el servidor y el script lo avisa en el log: no protege de
# perder el servidor.

set -euo pipefail

readonly DIAS=14
readonly CONFIG="$HOME/.config/indiecinema"
readonly CLAVE="$CONFIG/respaldo.asc"
readonly DESTINO="$CONFIG/respaldo-destino"
readonly CARPETA="$HOME/respaldos/diarios"

main() {
	cd "$(dirname "$(realpath "$0")")/.."
	if [ ! -f "$CLAVE" ]; then
		echo "Falta la clave pública en $CLAVE (README, «Respaldos»): no se respalda sin cifrar." >&2
		exit 1
	fi

	local trabajo nombre
	trabajo=$(mktemp -d)
	# Lo que quede sin cifrar, se borra aunque algo falle. La ruta va expandida ya: el trap corre
	# después de main, cuando sus variables locales ya no existen.
	# shellcheck disable=SC2064
	trap "rm -rf '$trabajo'" EXIT
	nombre="indiecinema-$(date -u +%Y-%m-%d-%H%M).tar.gpg"

	# Como root, desde adentro del contenedor de MySQL (root no entra de otro lado). Con --routines y
	# --triggers por si alguna migración los suma; --set-gtid-purged=OFF para poder cargarlo en una
	# base limpia.
	docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot \
		--single-transaction --routines --triggers --set-gtid-purged=OFF \
		--databases cuentas programacion funciones moderacion recaudacion control_migraciones' \
		</dev/null >"$trabajo/base.sql"
	# Los volúmenes, con el usuario del subsistema, que es quien los escribe.
	local subsistema
	for subsistema in programacion moderacion; do
		docker compose exec -T "$subsistema" tar -c -C /var/indiecinema/archivos . \
			</dev/null >"$trabajo/archivos-$subsistema.tar"
	done

	mkdir -p "$CARPETA"
	chmod 700 "$HOME/respaldos" "$CARPETA"
	tar -c -C "$trabajo" base.sql archivos-programacion.tar archivos-moderacion.tar | gzip \
		| gpg --batch --yes --quiet --trust-model always --recipient-file "$CLAVE" --encrypt \
			--output "$CARPETA/$nombre"
	chmod 600 "$CARPETA/$nombre"
	echo "$(date -u '+%F %T') respaldo $nombre ($(du -h "$CARPETA/$nombre" | cut -f1))"

	copiar_afuera "$CARPETA/$nombre"
	# Los viejos, sólo los de esta carpeta y sólo los que tienen el nombre de un respaldo.
	find "$CARPETA" -maxdepth 1 -name 'indiecinema-*.tar.gpg' -mtime "+$DIAS" -delete
}

copiar_afuera() {
	if [ ! -s "$DESTINO" ]; then
		echo "Sin $DESTINO: el respaldo quedó sólo en este servidor." >&2
		return 0
	fi
	local destino
	destino=$(head -n1 "$DESTINO")
	case $destino in
		s3://*) aws s3 cp --only-show-errors "$1" "${destino%/}/" ;;
		*@*:*) scp -q -o BatchMode=yes "$1" "$destino" ;;
		*)
			echo "No entiendo el destino «$destino»: s3://bucket/carpeta o usuario@host:carpeta." >&2
			return 1
			;;
	esac
	echo "Copiado a $destino."
}

main "$@"
