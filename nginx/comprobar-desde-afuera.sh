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

# rafaga <n> <método> <ruta> <tipo> [Accept]: n + 3 pedidos a la vez. Pasan n, los que admite el
# límite con su ráfaga, y los otros responden 429, con ese tipo, con el mensaje para el visitante y
# con la Content-Security-Policy, como cualquier otra respuesta. A la vez y no uno detrás de otro,
# y el 429 se mira en la misma ráfaga: contra un sitio que tarda en contestar (o en la CI, donde
# nginx espera a los subsistemas que no están), el límite recupera lugares mientras tanto, y un
# pedido que viniera después ya no lo superaría.
rafaga() {
	local dir pasaron cortados uno tipo csp
	dir=$(mktemp -d)
	seq $(($1 + 3)) | xargs -P $(($1 + 3)) -I{} curl -s -X "$2" -H "Accept: ${5:-text/html}" \
		-o "$dir/{}" -D "$dir/{}.cabeceras" -w '%{http_code}\n' "$base$3" >"$dir/estados"
	cortados=$(grep -c '^429$' "$dir/estados")
	pasaron=$(grep -vc '^429$' "$dir/estados")
	uno=$(grep -l '^HTTP/[0-9.]* 429' "$dir"/*.cabeceras | head -1)
	tipo=$(grep -i '^content-type:' "$uno" 2>/dev/null | cut -d' ' -f2- | tr -d '\r')
	csp=$(grep -ci '^content-security-policy:' "$uno" 2>/dev/null)
	if ((pasaron >= $1 && cortados > 0)) && [[ $tipo == "$4"* ]] && ((csp > 0)) \
		&& grep -q 'Hiciste muchos pedidos seguidos. Probá de nuevo en un minuto.' "${uno%.cabeceras}"; then
		printf '  ✔ pasan %s %s %s, y los que siguen dan 429 (%s)\n' "$1" "$2" "$3" "$4"
	else
		printf '  ✘ de %s %s %s pasaron %s y %s dieron 429, «%s» (se esperaba que pasaran %s y los demás dieran 429 %s con el mensaje y la CSP)\n' \
			$(($1 + 3)) "$2" "$3" "$pasaron" "$cortados" "$tipo" "$1" "$4"
		fallas=$((fallas + 1))
	fi
	rm -rf "$dir"
}

# demasiados <método> <ruta> <tipo> [Accept]: con el límite ya gastado por una ráfaga, el pedido
# responde 429 como en rafaga. Hasta 5 intentos: si el sitio tardó, el límite pudo haber
# recuperado algún lugar, y el que lo ocupa no es una falla. Sirve para un límite lento, como el
# de la reserva (uno cada 6 segundos), y para probar el otro formato.
demasiados() {
	local respuesta estado tipo csp cuerpo intento
	for intento in 1 2 3 4 5; do
		respuesta=$(curl -s -X "$1" -H "Accept: ${4:-text/html}" \
			-w '\n%{http_code}\n%header{content-type}\n%header{content-security-policy}' "$base$2")
		csp=${respuesta##*$'\n'}
		respuesta=${respuesta%$'\n'*}
		tipo=${respuesta##*$'\n'}
		respuesta=${respuesta%$'\n'*}
		estado=${respuesta##*$'\n'}
		cuerpo=${respuesta%$'\n'*}
		[[ $estado == 429 ]] && break
	done
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
rafaga 61 GET '/cartelera?q=x' text/html

# Sin sesión: el límite corta antes que el auth_request, así que tampoco se esquiva sin ingresar.
echo "Muchas reservas seguidas desde una IP:"
reservar=/funcion/reservar/00000003-0000-4000-8000-000000000001
rafaga 6 POST $reservar application/json application/json
demasiados POST $reservar text/html

if ((fallas > 0)); then
	echo "$fallas comprobaciones fallaron."
	exit 1
fi
echo "nginx no deja llegar a /interno/ desde afuera y manda las cabeceras de seguridad."
