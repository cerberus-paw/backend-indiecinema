#!/bin/bash
# Corre el análisis estático y las pruebas de un subsistema (o de todos) sin PHP ni Composer en la
# máquina: con la misma imagen de los subsistemas y contra un MySQL descartable, como el CI.
#
#   herramientas/pruebas.sh funciones                  # un subsistema: nucleo, cuentas, programacion o funciones
#   herramientas/pruebas.sh funciones --filter Reserva # lo que sigue va a PHPUnit
#   herramientas/pruebas.sh                            # los cuatro
#
# El MySQL (mysql-pruebas) queda andando para la próxima corrida, también en 127.0.0.1:3307 para
# quien prefiere su PHP. Se apaga con: docker stop mysql-pruebas
#
# Los repositorios del back y del front van uno al lado del otro, como para levantar el entorno:
# se monta la carpeta que los contiene, así las rutas relativas de Composer valen adentro.

set -euo pipefail

readonly RED=indiecinema-pruebas
readonly MYSQL=mysql-pruebas
readonly IMAGEN=indiecinema-pruebas
readonly PAQUETES=(nucleo cuentas programacion funciones)

main() {
	cd "$(dirname "$(realpath "$0")")/.."
	local raiz
	raiz=$(dirname "$PWD")

	local paquetes=("${PAQUETES[@]}")
	if [[ $# -gt 0 && $1 != -* ]]; then
		if [[ ! " ${PAQUETES[*]} " =~ " $1 " ]]; then
			echo "No conozco «$1»: es uno de ${PAQUETES[*]}." >&2
			exit 2
		fi
		paquetes=("$1")
		shift
	fi

	preparar_mysql
	# La imagen de los subsistemas (php/Dockerfile), con su PHP y pdo_mysql; se reconstruye sola si
	# cambia el Dockerfile.
	# Callada si sale bien; si falla, otra vez con toda la salida para ver por qué.
	docker build --quiet --tag "$IMAGEN" php >/dev/null 2>&1 || docker build --tag "$IMAGEN" php

	local fallaron=()
	for paquete in "${paquetes[@]}"; do
		echo "== $paquete"
		instalar "$raiz" "$paquete"
		# PHPStan antes, como el CI: tarda menos y con su caché, casi nada.
		if ! en_la_imagen "$raiz" "$paquete" vendor/bin/phpstan analyse --memory-limit=1G --no-progress \
			|| ! en_la_imagen "$raiz" "$paquete" vendor/bin/phpunit "$@"; then
			fallaron+=("$paquete")
		fi
	done

	if [[ ${#fallaron[@]} -gt 0 ]]; then
		echo "Fallaron: ${fallaron[*]}" >&2
		exit 1
	fi
}

# El MySQL descartable en su red, la primera vez; si ya andaba (por ejemplo, levantado a mano como
# dice el README), sólo se suma a la red.
preparar_mysql() {
	docker network inspect "$RED" >/dev/null 2>&1 || docker network create "$RED" >/dev/null
	if [[ -z $(docker ps --quiet --filter "name=^${MYSQL}$") ]]; then
		docker run --detach --rm --name "$MYSQL" --network "$RED" \
			--env MYSQL_ROOT_PASSWORD=pruebas --env MYSQL_DATABASE=pruebas \
			--publish 127.0.0.1:3307:3306 mysql:8.4 >/dev/null
	elif ! docker inspect --format '{{json .NetworkSettings.Networks}}' "$MYSQL" | grep -q "\"$RED\""; then
		docker network connect "$RED" "$MYSQL"
	fi

	# La primera vez tarda unos segundos en aceptar conexiones.
	for _ in $(seq 60); do
		if docker exec "$MYSQL" mysqladmin ping --host=127.0.0.1 --password=pruebas --silent >/dev/null 2>&1; then
			return
		fi
		sleep 1
	done
	echo "El MySQL de pruebas no arrancó: docker logs $MYSQL" >&2
	exit 1
}

# Un comando de PHP en la imagen de los subsistemas, en la carpeta del paquete y con el MySQL.
en_la_imagen() {
	local raiz=$1 paquete=$2
	shift 2
	docker run --rm --network "$RED" --user "$(id -u):$(id -g)" \
		--env PRUEBAS_MYSQL_HOST="$MYSQL" --env PRUEBAS_MYSQL_PUERTO=3306 --env PRUEBAS_MYSQL_CLAVE=pruebas \
		--volume "$raiz:/src" --workdir "/src/backend-indiecinema/$paquete" \
		--entrypoint php "$IMAGEN" "$@"
}

# Las dependencias de desarrollo (PHPUnit y PHPStan) con el Composer de Docker, sólo si faltan.
instalar() {
	local raiz=$1 paquete=$2
	if [[ ! -x $paquete/vendor/bin/phpunit || ! -x $paquete/vendor/bin/phpstan ]]; then
		docker run --rm --user "$(id -u):$(id -g)" --volume "$raiz:/app" \
			--workdir "/app/backend-indiecinema/$paquete" composer:2 install --quiet --no-interaction
	fi
}

main "$@"
