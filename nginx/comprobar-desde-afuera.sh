#!/usr/bin/env bash
# Lo que nginx no deja hacer desde afuera, probado con pedidos de verdad: ninguna ruta /interno/
# llega a un subsistema, con o sin prefijo y aunque venga disfrazada. nginx corta antes de pasarle
# el pedido a nadie, así que alcanza con nginx solo: lo corre la CI sin levantar los subsistemas.
# También, que las respuestas traigan las cabeceras de seguridad, como la Content-Security-Policy,
# y que muchas búsquedas o reservas seguidas den 429. Esas pruebas gastan el límite de la IP que
# corre el script: si se repite antes de un minuto, las ráfagas encuentran el límite ya gastado.
#
# Uso: nginx/comprobar-desde-afuera.sh [http://localhost:8080]
set -u

base=${1:-http://localhost:8080}
fallas=0

# espera <estado> <ruta> [Location esperado]
espera() {
	local estado ubicacion
	read -r estado ubicacion < <(curl -s -o /dev/null --path-as-is -w '%{http_code} %header{location}' "$base$2")
	if [[ $estado == "$1" && ( -z ${3:-} || $ubicacion == "$3" ) ]]; then
		printf '  ✔ %s %s\n' "$estado" "$2"
	else
		printf '  ✘ %s %s (se esperaba %s %s)\n' "$estado" "$2" "$1" "${3:-}"
		fallas=$((fallas + 1))
	fi
}

# cabecera <nombre> <valor>: la respuesta de la portada la trae con ese valor exacto. Sin los
# subsistemas (en la CI) la portada es un error, pero nginx agrega las cabeceras igual («always»).
cabecera() {
	local valor
	valor=$(curl -s -o /dev/null -w "%header{$1}" "$base/")
	if [[ $valor == "$2" ]]; then
		printf '  ✔ %s\n' "$1"
	else
		printf '  ✘ %s: «%s» (se esperaba «%s»)\n' "$1" "$valor" "$2"
		fallas=$((fallas + 1))
	fi
}

# rafaga <n> <método> <ruta>: n pedidos seguidos, y ninguno corta el límite de pedidos por IP.
# Sin los subsistemas (en la CI) responden un error de nginx, pero no 429.
rafaga() {
	local i estado cortados=0
	for ((i = 0; i < $1; i++)); do
		estado=$(curl -s -o /dev/null -X "$2" -w '%{http_code}' "$base$3")
		[[ $estado == 429 ]] && cortados=$((cortados + 1))
	done
	if ((cortados == 0)); then
		printf '  ✔ pasan %s %s %s\n' "$1" "$2" "$3"
	else
		printf '  ✘ de %s %s %s, %s dieron 429 (se esperaba que pasaran todos)\n' "$1" "$2" "$3" "$cortados"
		fallas=$((fallas + 1))
	fi
}

# demasiados <método> <ruta> <tipo> [Accept]: el pedido corta el límite. Responde 429, con ese
# tipo, con el mensaje para el visitante y con la Content-Security-Policy, como cualquier otra
# respuesta.
demasiados() {
	local respuesta estado tipo csp cuerpo
	respuesta=$(curl -s -X "$1" -H "Accept: ${4:-text/html}" \
		-w '\n%{http_code}\n%header{content-type}\n%header{content-security-policy}' "$base$2")
	csp=${respuesta##*$'\n'}
	respuesta=${respuesta%$'\n'*}
	tipo=${respuesta##*$'\n'}
	respuesta=${respuesta%$'\n'*}
	estado=${respuesta##*$'\n'}
	cuerpo=${respuesta%$'\n'*}
	if [[ $estado == 429 && $tipo == "$3"* && -n $csp
		&& $cuerpo == *'Hiciste muchos pedidos seguidos. Probá de nuevo en un minuto.'* ]]; then
		printf '  ✔ 429 %s %s (%s)\n' "$1" "$2" "$3"
	else
		printf '  ✘ %s %s %s: «%s» (se esperaba 429 %s con el mensaje y la CSP)\n' "$estado" "$1" "$2" "$tipo" "$3"
		fallas=$((fallas + 1))
	fi
}

echo "Las cabeceras de seguridad:"
cabecera Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
cabecera Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()"
cabecera Cross-Origin-Opener-Policy same-origin
cabecera Cross-Origin-Resource-Policy same-origin
cabecera Cross-Origin-Embedder-Policy require-corp

echo "Las rutas /interno/ no existen desde afuera:"
espera 404 /interno/estado
espera 404 /interno/sesion
espera 404 /cuenta/interno/sesion
espera 404 /cuenta/interno/estado
espera 404 /funcion/interno/estado
espera 404 /funcion/interno/funciones/00000003-0000-4000-8000-000000000001
espera 404 /interno/funciones/00000003-0000-4000-8000-000000000001
# Disfrazadas: nginx decodifica y normaliza la ruta antes de elegir la location.
espera 404 /funcion/%69nterno/estado
espera 404 /funcion//interno/estado
espera 404 /funcion/./interno/estado
espera 404 /funcion/x/../interno/estado
espera 404 /cuenta/%2e/interno/sesion

echo "El auth_request y la respuesta del 429 tampoco se pueden pedir directo:"
espera 404 /_sesion
espera 404 /_demasiados/json
espera 404 /_demasiados/html

echo "/funcion sin la barra va a /funcion/:"
espera 301 /funcion /funcion/

echo "Muchas búsquedas seguidas desde una IP:"
rafaga 31 GET '/cartelera?q=x'
demasiados GET '/cartelera?q=x' text/html

# Sin sesión: el límite corta antes que el auth_request, así que tampoco se esquiva sin ingresar.
echo "Muchas reservas seguidas desde una IP:"
reservar=/funcion/reservar/00000003-0000-4000-8000-000000000001
rafaga 6 POST $reservar
demasiados POST $reservar application/json application/json
demasiados POST $reservar text/html

if ((fallas > 0)); then
	echo "$fallas comprobaciones fallaron."
	exit 1
fi
echo "nginx no deja llegar a /interno/ desde afuera y manda las cabeceras de seguridad."
