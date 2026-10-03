#!/bin/bash
# Comprueba que cada subsistema sólo alcanza su propio esquema: es la prueba de que la separación
# en subsistemas también vale en la base (E2, mínimo privilegio). Entra con el usuario y la clave
# de cada uno, como lo hace su aplicación, e intenta lo que no debería poder hacer:
#
#   docker compose exec mysql bash /herramientas/comprobar-aislamiento.sh
#
# Cada intento tiene que fallar con «command denied» o «Access denied». Sale con 1 si alguno pasa.

set -uo pipefail

# Una tabla de cada esquema para intentar leer. No hace falta que exista: MySQL controla el
# permiso antes de buscar la tabla, así que el error es el mismo.
declare -A tabla=([cuentas]=usuario [programacion]=sala [funciones]=reserva)
fallas=0

# Corre $3 como el usuario $1 y espera que MySQL lo rechace por permisos.
rechaza() {
	local usuario=$1 clave=$2 sql=$3 salida
	salida=$(MYSQL_PWD="$clave" mysql -h127.0.0.1 -u"$usuario" --batch -e "$sql" 2>&1)
	if [[ $salida == *"command denied"* || $salida == *"Access denied"* ]]; then
		printf '  ✔ %-47s %s\n' "$sql" "${salida#*: }"
	else
		printf '  ✘ %-47s %s\n' "$sql" "${salida:-pasó sin error}"
		fallas=$((fallas + 1))
	fi
}

for subsistema in cuentas programacion funciones; do
	variable="MYSQL_CLAVE_${subsistema^^}"
	clave=${!variable:?falta $variable en el entorno de MySQL}
	echo "Usuario ${subsistema}:"

	for otro in cuentas programacion funciones; do
		[ "$otro" = "$subsistema" ] && continue
		rechaza "$subsistema" "$clave" "SELECT * FROM ${otro}.${tabla[$otro]}"
	done
	rechaza "$subsistema" "$clave" "CREATE TABLE ${subsistema}.prueba (id INT)"
	rechaza "$subsistema" "$clave" "DROP TABLE ${subsistema}.${tabla[$subsistema]}"
	rechaza "$subsistema" "$clave" "SELECT * FROM control_migraciones.aplicadas"
done

if [ "$fallas" -gt 0 ]; then
	[ "$fallas" -eq 1 ] && echo "1 intento pasó: hay permisos de más." >&2 || echo "${fallas} intentos pasaron: hay permisos de más." >&2
	exit 1
fi
echo "Cada subsistema sólo alcanza su propio esquema."
