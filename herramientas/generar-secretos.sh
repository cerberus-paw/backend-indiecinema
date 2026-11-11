#!/bin/bash
# Genera el .env y los config.ini de producción de IndieCinema, con secretos al azar y coherentes
# entre sí. Se corre una sola vez, en un servidor nuevo, parado en backend-indiecinema/:
#
#   bash herramientas/generar-secretos.sh
#
# Para sumar un subsistema a un servidor que ya los tiene, herramientas/sumar-subsistema.sh.
#
# A mano son 18 secretos y 21 huellas repartidos en seis archivos, y un solo valor que no
# coincida deja un subsistema sin arrancar o respondiendo 403. Los secretos nacen en el servidor
# y no salen de ahí. No pisa archivos que ya existan: rotar secretos es otro procedimiento
# (cambiar la clave de un usuario de MySQL que ya existe no se hace editando el .env).

set -euo pipefail

SUBSISTEMAS=(cuentas programacion funciones moderacion recaudacion)

for archivo in .env "${SUBSISTEMAS[@]/%//config/config.ini}"; do
	if [ -e "$archivo" ]; then
		echo "$archivo ya existe: no se pisa. Borralo si querés generar todo de nuevo." >&2
		exit 1
	fi
done
for subsistema in "${SUBSISTEMAS[@]}"; do
	if [ ! -f "$subsistema/config/config.ejemplo.ini" ]; then
		echo "No encuentro $subsistema/config/config.ejemplo.ini: corré esto desde backend-indiecinema/." >&2
		exit 1
	fi
done

al_azar() { openssl rand -hex 32; }
# Lo que guarda [llamadores]: el SHA-256 del token, nunca el token.
huella() { printf '%s' "$1" | sha256sum | cut -d' ' -f1; }

declare -A clave secreto token
for subsistema in "${SUBSISTEMAS[@]}"; do
	clave[$subsistema]=$(al_azar)     # la del usuario de MySQL del subsistema
	secreto[$subsistema]=$(al_azar)   # el que nginx le manda en X-Nginx-Secreto
	token[$subsistema]=$(al_azar)     # con el que llama a /interno/ de los otros
done
token_nginx=$(al_azar)

# Sólo lo lee docker compose, que corre con tu usuario.
(umask 077 && cat >.env <<EOF
PUERTO_HTTP=80
# El correo se simula también en los servidores: el buzón de prueba (ver el README, [correo]).
COMPOSE_PROFILES=buzon

MYSQL_CLAVE_ROOT=$(al_azar)
MYSQL_CLAVE_MIGRACIONES=$(al_azar)
MYSQL_CLAVE_CUENTAS=${clave[cuentas]}
MYSQL_CLAVE_PROGRAMACION=${clave[programacion]}
MYSQL_CLAVE_FUNCIONES=${clave[funciones]}
MYSQL_CLAVE_MODERACION=${clave[moderacion]}
MYSQL_CLAVE_RECAUDACION=${clave[recaudacion]}

NGINX_SECRETO_CUENTAS=${secreto[cuentas]}
NGINX_SECRETO_PROGRAMACION=${secreto[programacion]}
NGINX_SECRETO_FUNCIONES=${secreto[funciones]}
NGINX_SECRETO_MODERACION=${secreto[moderacion]}
NGINX_SECRETO_RECAUDACION=${secreto[recaudacion]}
NGINX_TOKEN_INTERNO=$token_nginx
EOF
)

for subsistema in "${SUBSISTEMAS[@]}"; do
	destino="$subsistema/config/config.ini"
	# Las líneas de [llamadores] se reconocen por la huella de ejemplo (d3fd…): así no se tocan
	# las de [subsistemas], que tienen los mismos nombres pero con una URL.
	(umask 027 && sed \
		-e 's/^entorno = "desarrollo"/entorno = "produccion"/' \
		-e "s/^clave = \"cambiar\"/clave = \"${clave[$subsistema]}\"/" \
		-e "s/^token = \"cambiar\"/token = \"${token[$subsistema]}\"/" \
		-e "s/^secreto = \"cambiar\"/secreto = \"${secreto[$subsistema]}\"/" \
		-e "s/^cuentas = \"d3fd[0-9a-f]*\"/cuentas = \"$(huella "${token[cuentas]}")\"/" \
		-e "s/^programacion = \"d3fd[0-9a-f]*\"/programacion = \"$(huella "${token[programacion]}")\"/" \
		-e "s/^funciones = \"d3fd[0-9a-f]*\"/funciones = \"$(huella "${token[funciones]}")\"/" \
		-e "s/^moderacion = \"d3fd[0-9a-f]*\"/moderacion = \"$(huella "${token[moderacion]}")\"/" \
		-e "s/^recaudacion = \"d3fd[0-9a-f]*\"/recaudacion = \"$(huella "${token[recaudacion]}")\"/" \
		-e "s/^nginx = \"d3fd[0-9a-f]*\"/nginx = \"$(huella "$token_nginx")\"/" \
		"$subsistema/config/config.ejemplo.ini" >"$destino")

	# Apache corre como www-data dentro del contenedor (uid 33, el mismo número que en Ubuntu) y
	# tiene que poder leerlo; el resto de los usuarios del servidor, no.
	if ! chgrp www-data "$destino" 2>/dev/null && ! sudo chgrp www-data "$destino"; then
		echo "No pude darle $destino al grupo www-data: el subsistema no va a poder leerlo." >&2
		exit 1
	fi

	# El núcleo no arranca en producción si queda un valor de ejemplo: se comprueba acá, antes.
	if grep -qE '"cambiar"|"d3fd787a' "$destino"; then
		echo "Quedaron valores de ejemplo en $destino (¿cambió el formato del ejemplo?):" >&2
		grep -nE '"cambiar"|"d3fd787a' "$destino" | sed 's/=.*//' >&2
		exit 1
	fi
done

echo "Listo: .env y config.ini de ${SUBSISTEMAS[*]}, en producción y sin valores de ejemplo."
