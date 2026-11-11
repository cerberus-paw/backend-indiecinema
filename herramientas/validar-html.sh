#!/bin/bash
# Pasa por el validador del W3C (vnu) las páginas que dibujan las pruebas, como el CI (E4-10.7):
# portada, cartelera, ficha de una función, reserva, ingresar y registro, y desde F-01.8 también las
# que se ven con sesión: el panel del organizador, Mis reservas y la administración. Las pruebas que ya piden
# esas páginas las guardan en una carpeta (PAGINAS_PARA_VALIDAR) y la imagen del validador las
# revisa; falla si alguna tiene un error de HTML. Los avisos no la hacen fallar.
#
#   herramientas/validar-html.sh
#
# Como herramientas/pruebas.sh, sin PHP ni Java en la máquina: todo con Docker, con el front al
# lado del back.

set -euo pipefail

# El mismo que el CI (.github/workflows/ci.yml), fijado por digest.
readonly VALIDADOR=ghcr.io/validator/validator@sha256:0af71abb9e40083cc0e8b3fcbe51a55a45889c662a3f507fbf1f9f013a109842
# Las pruebas que dibujan cada página, por subsistema.
readonly -A PRUEBAS=(
	[programacion]='InicioTest|CarteleraTest|FichaDeLaFuncionTest|ObrasTest|SalasDelOrganizadorTest|ObrasDelOrganizadorTest|FuncionesDelOrganizadorTest|SolicitudDeSalaTest|AdministracionDeSolicitudesTest'
	[cuentas]='IngresoTest|RegistroTest|MiCuentaTest|AdministracionDeUsuariosTest'
	[funciones]='ReservasTest|PanelDeReservasTest|CalificacionesTest'
)

main() {
	cd "$(dirname "$(realpath "$0")")/.."
	local carpeta=$PWD/.paginas-para-validar
	rm -rf "$carpeta"
	mkdir -p "$carpeta"

	# Adentro del contenedor el back está en /src/backend-indiecinema (herramientas/pruebas.sh).
	for paquete in programacion cuentas funciones; do
		PAGINAS_PARA_VALIDAR=/src/backend-indiecinema/.paginas-para-validar \
			herramientas/pruebas.sh "$paquete" --filter "${PRUEBAS[$paquete]}"
	done

	validar "$carpeta"
}

# vnu sobre cada página de la carpeta, con el archivo y la línea de cada error.
validar() {
	local carpeta=$1
	echo "== validador del W3C: $(ls "$carpeta" | tr '\n' ' ')"
	docker run --rm --volume "$carpeta:/paginas:ro" "$VALIDADOR" \
		java -jar /vnu.jar --errors-only --format gnu /paginas
	echo "Sin errores."
}

main "$@"
