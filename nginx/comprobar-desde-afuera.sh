#!/usr/bin/env bash
# Lo que nginx no deja hacer desde afuera, probado con pedidos de verdad: ninguna ruta /interno/
# llega a un subsistema, con o sin prefijo y aunque venga disfrazada. nginx corta antes de pasarle
# el pedido a nadie, así que alcanza con nginx solo: lo corre la CI sin levantar los subsistemas.
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

echo "El auth_request tampoco se puede pedir directo:"
espera 404 /_sesion

echo "/funcion sin la barra va a /funcion/:"
espera 301 /funcion /funcion/

if ((fallas > 0)); then
	echo "$fallas comprobaciones fallaron."
	exit 1
fi
echo "nginx no deja llegar a /interno/ desde afuera."
