#!/bin/bash
# Suma un subsistema nuevo a un servidor que ya tiene su .env y los config.ini de los demás
# (EF-01.6), con secretos al azar y coherentes con los que ya están. Parado en backend-indiecinema/,
# antes de mergear la rama que trae el subsistema:
#
#   bash herramientas/sumar-subsistema.sh moderacion
#
# Hace lo que generar-secretos.sh, pero sólo para el nuevo y sin tocar los secretos de los demás:
#   - agrega MYSQL_CLAVE_<SUB> y NGINX_SECRETO_<SUB> al .env;
#   - arma <sub>/config/config.ini desde su ejemplo, en producción, con las huellas de los tokens
#     de los demás (que lee de sus config.ini, y el de nginx del .env);
#   - agrega el nuevo a [subsistemas] y su huella a [llamadores] en los config.ini de los demás.
#
# Lo que falta después, en este orden (también lo dice al terminar):
#   1. mergear la rama (el CD recrea MySQL con la clave nueva y levanta el contenedor);
#   2. docker compose exec mysql bash /herramientas/crear-esquema.sh <sub>.
# El orden no rompe nada: mientras el subsistema no tiene migraciones no toca la base, y migrar.sh
# no la necesita.
#
# No pisa nada: si el config.ini del nuevo ya existe, o el .env ya tiene sus variables, frena.

set -euo pipefail

nuevo=${1:-}
if [[ ! $nuevo =~ ^[a-z]+$ || ! -f "$nuevo/config/config.ejemplo.ini" ]]; then
	echo "Uso: bash herramientas/sumar-subsistema.sh <subsistema>, parado en backend-indiecinema/ y con la rama que lo trae." >&2
	exit 1
fi
if [ -e "$nuevo/config/config.ini" ]; then
	echo "$nuevo/config/config.ini ya existe: no se pisa." >&2
	exit 1
fi
mayusculas=${nuevo^^}
if grep -qE "^(MYSQL_CLAVE|NGINX_SECRETO)_${mayusculas}=" .env; then
	echo ".env ya tiene MYSQL_CLAVE_${mayusculas} o NGINX_SECRETO_${mayusculas}: no se pisa." >&2
	exit 1
fi

# Los que ya están: los que tienen su config.ini.
otros=()
for archivo in */config/config.ini; do
	otros+=("${archivo%%/*}")
done
if [ ${#otros[@]} -eq 0 ]; then
	echo "No hay ningún config.ini: en un servidor nuevo va herramientas/generar-secretos.sh." >&2
	exit 1
fi

al_azar() { openssl rand -hex 32; }
huella() { printf '%s' "$1" | sha256sum | cut -d' ' -f1; }
# El valor de «clave = "…"» de un .ini, en la sección dada.
valor_ini() {
	awk -v seccion="[$2]" -v clave="$3" '
		/^\[/ { adentro = ($0 == seccion) }
		adentro && $1 == clave { sub(/^[^=]*= *"/, ""); sub(/".*$/, ""); print; exit }
	' "$1"
}

clave=$(al_azar)
secreto=$(al_azar)
token=$(al_azar)

# 1. El .env.
printf '\nMYSQL_CLAVE_%s=%s\nNGINX_SECRETO_%s=%s\n' "$mayusculas" "$clave" "$mayusculas" "$secreto" >>.env

# 2. El config.ini del nuevo, con la huella del token de cada uno de los otros.
destino="$nuevo/config/config.ini"
reemplazos=(
	-e 's/^entorno = "desarrollo"/entorno = "produccion"/'
	-e "s/^clave = \"cambiar\"/clave = \"${clave}\"/"
	-e "s/^token = \"cambiar\"/token = \"${token}\"/"
	-e "s/^secreto = \"cambiar\"/secreto = \"${secreto}\"/"
)
for otro in "${otros[@]}"; do
	token_otro=$(valor_ini "$otro/config/config.ini" interno token)
	if [ -z "$token_otro" ]; then
		echo "No encuentro [interno] token en $otro/config/config.ini." >&2
		exit 1
	fi
	reemplazos+=(-e "s/^${otro} = \"d3fd[0-9a-f]*\"/${otro} = \"$(huella "$token_otro")\"/")
done
token_nginx=$(sed -n 's/^NGINX_TOKEN_INTERNO=//p' .env | tail -n1)
reemplazos+=(-e "s/^nginx = \"d3fd[0-9a-f]*\"/nginx = \"$(huella "$token_nginx")\"/")
(umask 027 && sed "${reemplazos[@]}" "$nuevo/config/config.ejemplo.ini" >"$destino")
if ! chgrp www-data "$destino" 2>/dev/null && ! sudo chgrp www-data "$destino"; then
	echo "No pude darle $destino al grupo www-data: el subsistema no va a poder leerlo." >&2
	exit 1
fi
if grep -qE '"cambiar"|"d3fd787a' "$destino"; then
	echo "Quedaron valores de ejemplo en $destino: ¿falta algún subsistema en este servidor?" >&2
	grep -nE '"cambiar"|"d3fd787a' "$destino" | sed 's/=.*//' >&2
	exit 1
fi

# 3. Los config.ini de los otros: dónde está el nuevo y la huella de su token, al principio de cada
# sección. Si ya estaban (alguien lo sumó a mano), no se repiten.
for otro in "${otros[@]}"; do
	archivo="$otro/config/config.ini"
	cp -p "$archivo" "$archivo.antes-de-${nuevo}"
	awk -v nuevo="$nuevo" -v url="\"http://${nuevo}:8080\"" -v huella="\"$(huella "$token")\"" '
		/^\[/ { seccion = $0 }
		{ print }
		seccion == "[subsistemas]" && /^\[subsistemas\]/ && !tiene_url { print nuevo " = " url }
		seccion == "[llamadores]" && /^\[llamadores\]/ && !tiene_huella { print nuevo " = " huella }
	' tiene_url="$(valor_ini "$archivo" subsistemas "$nuevo")" tiene_huella="$(valor_ini "$archivo" llamadores "$nuevo")" \
		"$archivo.antes-de-${nuevo}" >"$archivo"
done

echo "Listo: .env, $destino y los config.ini de ${otros[*]} (con una copia .antes-de-${nuevo})."
echo "Ahora: mergear la rama y, cuando el CD termine, docker compose exec mysql bash /herramientas/crear-esquema.sh ${nuevo}"
