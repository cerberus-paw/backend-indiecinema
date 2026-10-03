#!/bin/bash
# Aplica las migraciones que faltan, en orden, y anota cada una en control_migraciones.
#
# Corre adentro del contenedor de MySQL, con el usuario de migraciones, que es el único con
# permiso para crear y alterar tablas y no existe en ningún contenedor de la aplicación:
#
#   docker compose exec mysql bash /herramientas/migrar.sh                # los tres subsistemas
#   docker compose exec mysql bash /herramientas/migrar.sh programacion   # uno solo
#
# Cada subsistema guarda las suyas en <subsistema>/migraciones/, con nombres como
# 001_tabla_sala.sql: tres dígitos, guion bajo y minúsculas. Se aplican en orden de nombre, cada
# una con su esquema como base por defecto. Correrlo de nuevo no hace nada si no hay nuevas.
#
# Una migración aplicada no se edita: si cambia, el script frena y avisa, y el cambio va en una
# migración nueva. Si una falla a mitad de camino, no se anota; como MySQL no deshace un CREATE ni
# un ALTER, hay que revisar qué quedó antes de volver a correrla.

set -euo pipefail

export MYSQL_PWD="${MYSQL_CLAVE_MIGRACIONES:?falta MYSQL_CLAVE_MIGRACIONES en el entorno de MySQL}"
sql() {
	mysql --user=migraciones --batch --skip-column-names "$@"
}

if [ $# -eq 0 ]; then
	set -- cuentas programacion funciones
fi

for subsistema in "$@"; do
	case $subsistema in
		cuentas | programacion | funciones) ;;
		*) echo "No existe el subsistema «${subsistema}»." >&2; exit 1 ;;
	esac

	aplicadas=0
	for archivo in "/migraciones/${subsistema}"/*.sql; do
		[ -e "$archivo" ] || continue # la carpeta todavía no tiene migraciones
		nombre=$(basename "$archivo")
		# El nombre va dentro del SQL de control: sólo se aceptan los que no pueden romperlo.
		if [[ ! $nombre =~ ^[0-9]{3}_[a-z0-9_]+\.sql$ ]]; then
			echo "${subsistema}/${nombre}: el nombre tiene que ser como 001_tabla_sala.sql." >&2
			exit 1
		fi

		huella=$(sha256sum "$archivo" | cut -d' ' -f1)
		anterior=$(sql -e "SELECT huella FROM control_migraciones.aplicadas WHERE subsistema = '${subsistema}' AND archivo = '${nombre}'")
		if [ -n "$anterior" ]; then
			if [ "$anterior" != "$huella" ]; then
				echo "${subsistema}/${nombre} cambió después de aplicarse: el cambio va en una migración nueva." >&2
				exit 1
			fi
			continue
		fi

		echo "Aplicando ${subsistema}/${nombre}"
		if ! sql --database="$subsistema" <"$archivo"; then
			echo "${subsistema}/${nombre} falló y no se anotó. Revisá qué quedó aplicado antes de correrla de nuevo." >&2
			exit 1
		fi
		sql -e "INSERT INTO control_migraciones.aplicadas (subsistema, archivo, huella) VALUES ('${subsistema}', '${nombre}', '${huella}')"
		aplicadas=$((aplicadas + 1))
	done
	case $aplicadas in
		0) echo "${subsistema}: al día" ;;
		1) echo "${subsistema}: 1 migración nueva" ;;
		*) echo "${subsistema}: ${aplicadas} migraciones nuevas" ;;
	esac
done
