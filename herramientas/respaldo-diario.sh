#!/bin/bash
# Respaldo diario fuera del servidor: la base (mysql/herramientas/volcar.sh) y los archivos que se
# suben a programación (fotos de las salas y afiches), a un bucket de S3. Lo corre todos los días
# el timer de herramientas/systemd/, desde la carpeta del back en el VPS:
#
#   herramientas/respaldo-diario.sh
#
# El destino sale de RESPALDO_S3 y RESPALDO_REGION en .env. En el servidor no hay credenciales de
# AWS: las da el rol IAM de la instancia, que sólo puede subir a ese bucket, no leer ni borrar. Así
# quien entrara al servidor no puede borrar los respaldos. Cuántos días se guardan lo decide la
# regla de ciclo de vida del bucket (docs/respaldos.md).
#
# Primero arma los dos archivos en el disco y comprueba que estén completos; recién ahí los sube.
# Así un volcado que se cortó a la mitad no queda en el bucket como si fuera un respaldo.

set -euo pipefail
cd "$(dirname "$0")/.."

# Sólo esas dos líneas de .env: el resto son secretos y no hace falta leerlos.
valor() { sed -n "s/^$1=//p" .env | tail -n1; }
destino=$(valor RESPALDO_S3)
region=$(valor RESPALDO_REGION)
if [[ ! $destino =~ ^s3://[a-z0-9.-]+(/.*)?$ ]] || [ -z "$region" ]; then
	echo "Faltan RESPALDO_S3 (como s3://bucket/carpeta) o RESPALDO_REGION en .env." >&2
	exit 1
fi
destino=${destino%/}

# El volcado tiene los datos de todos los usuarios: nadie más del servidor lo puede leer.
umask 077
temporal=$(mktemp -d)
trap 'rm -rf "$temporal"' EXIT
fecha=$(date -u +%Y-%m-%dT%H%M%SZ)

docker compose exec -T mysql bash /herramientas/volcar.sh | gzip >"$temporal/base.sql.gz"
ultima=$(gzip -dc "$temporal/base.sql.gz" | tail -n1)
if [[ $ultima != "-- Dump completed"* ]]; then
	echo "El volcado de la base quedó incompleto: no se sube." >&2
	exit 1
fi

docker compose exec -T programacion tar -C /var/indiecinema/archivos -czf - . >"$temporal/archivos.tar.gz"
gzip -t "$temporal/archivos.tar.gz"

# La CLI de AWS va en su imagen, así no se instala nada en el servidor. Con la red del host, porque
# el servicio de metadatos de la instancia (de donde salen las credenciales del rol) sólo responde
# a un salto de distancia y la red de Docker suma otro.
subir() {
	docker run --rm --network host -v "$temporal:/respaldo:ro" amazon/aws-cli:2.37.5 \
		s3 cp --region "$region" --only-show-errors "/respaldo/$1" "$destino/$2"
}
subir base.sql.gz "base/indiecinema-${fecha}.sql.gz"
subir archivos.tar.gz "archivos/programacion-${fecha}.tar.gz"
echo "Respaldo ${fecha} subido a ${destino}"
