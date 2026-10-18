#!/bin/bash
# Publica en el VPS lo que está en main: trae los dos repositorios, instala las dependencias,
# recrea los contenedores que cambiaron, aplica las migraciones y comprueba que el sitio responda.
# No toca los datos ni los secretos (.env y los config.ini).
#
# Lo corre el workflow CD del back y del front cuando la CI pasa en main. Entra por SSH con una
# clave que en ~/.ssh/authorized_keys sólo puede correr este script, sin terminal ni túneles: si
# el secreto de GitHub se filtrara, lo único que permite es volver a desplegar main.
#
#   restrict,command="/home/ubuntu/indiecinema/backend-indiecinema/herramientas/desplegar.sh" ssh-ed25519 AAAA… cd-github
#
# También se puede correr a mano, desde cualquier carpeta.

set -euo pipefail

# Todo va dentro de main: bash lee la función entera antes de correrla, así el git pull que cambia
# este mismo archivo no le cambia los pasos a la corrida en curso.
main() {
	cd "$(dirname "$(realpath "$0")")/.."
	local front=../frontend-indiecinema

	# Un despliegue por vez: el back y el front pueden mergear casi juntos.
	exec 9>"$HOME/.indiecinema-despliegue.lock"
	flock --timeout 900 9

	local antes
	antes=$(git rev-parse HEAD)
	git -C "$front" pull --ff-only --quiet
	git pull --ff-only --quiet
	echo "back:  $(git log -1 --format='%h %s')"
	echo "front: $(git -C "$front" log -1 --format='%h %s')"

	# Composer en su imagen, como en el README: el servidor no tiene PHP. Se monta la carpeta de los
	# dos repositorios, porque el núcleo y el front se instalan desde rutas relativas.
	local subsistema
	for subsistema in cuentas programacion funciones; do
		docker run --rm -u "$(id -u):$(id -g)" -v "$(realpath ..):/app" -w "/app/$(basename "$PWD")/$subsistema" \
			composer:2 install --no-interaction --no-progress </dev/null
	done

	docker compose up -d --build --quiet-pull </dev/null
	# nginx arma su configuración con las plantillas cuando arranca: si cambiaron, hay que reiniciarlo.
	if ! git diff --quiet "$antes" HEAD -- nginx/templates; then
		docker compose restart nginx </dev/null
	fi

	# Si MySQL se acaba de recrear, tarda en aceptar conexiones. Se espera por TCP: mientras corre
	# mysql/init, el servidor temporal sólo atiende el socket, y migrar antes chocaría con él.
	local espera
	for espera in {1..60}; do
		[ "$espera" -gt 1 ] && sleep 2
		if docker compose exec -T mysql mysqladmin --protocol=tcp --host=127.0.0.1 ping --silent </dev/null >/dev/null 2>&1; then
			break
		fi
	done
	docker compose exec -T mysql bash /herramientas/migrar.sh </dev/null

	comprobar
}

# Pide la cartelera a este mismo servidor, sin pasar por el DNS pero con el nombre del certificado,
# así también se comprueba el HTTPS. Le da medio minuto a lo que se acaba de recrear.
comprobar() {
	local dominio url
	dominio=$(sed -n 's/^DOMINIO=//p' .env | tail -n1)
	if [ -n "$dominio" ]; then
		url=(--resolve "${dominio}:443:127.0.0.1" "https://${dominio}/cartelera")
	else
		url=("http://localhost:$(sed -n 's/^PUERTO_HTTP=//p' .env | tail -n1)/cartelera")
	fi
	local intento
	for intento in {1..10}; do
		if curl -fsS -o /dev/null --max-time 10 "${url[@]}"; then
			echo "El sitio responde (intento ${intento})."
			return 0
		fi
		sleep 3
	done
	echo "El sitio no responde después de desplegar." >&2
	docker compose ps >&2
	return 1
}

main "$@"
exit
