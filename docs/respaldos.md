# Respaldo diario

Todos los días a las 4:30 UTC (1:30 en Argentina), `herramientas/respaldo-diario.sh` sube a un
bucket de S3 dos archivos con la fecha en el nombre:

- `base/indiecinema-<fecha>.sql.gz`: los esquemas `cuentas`, `programacion`, `funciones` y
  `control_migraciones`, volcados por `mysql/herramientas/volcar.sh`.
- `archivos/programacion-<fecha>.tar.gz`: las fotos de las salas y los afiches (el volumen
  `archivos-programacion`), que la base nombra pero no guarda.

Por qué así:

- **Fuera del servidor:** si se pierde la instancia o su disco, el respaldo sigue estando.
- **Sin claves en el servidor:** las credenciales las da el rol IAM de la instancia, que sólo puede
  subir a la carpeta del respaldo. Quien entrara al servidor no puede leer ni borrar los de días
  anteriores.
- **Privado y cifrado:** el bucket bloquea todo acceso público y cifra en reposo (SSE-S3). Se
  guardan 30 días.
- **Completo o nada:** el volcado se arma en una carpeta temporal que sólo lee el usuario del
  despliegue, se comprueba que terminó (`-- Dump completed`) y recién ahí se sube. Al terminar se
  borra.

## Armado en AWS

Una vez, desde la consola, en la misma región que la instancia:

1. **S3 → Create bucket:** un nombre único (por ejemplo `indiecinema-respaldos-cerberus`), con
   «Block all public access» y el cifrado SSE-S3, que vienen así.
2. **El bucket → Management → Create lifecycle rule:** con el prefijo `diario/`, «Expire current
   versions of objects» a los 30 días y «Delete incomplete multipart uploads» a 1 día.
3. **IAM → Roles → Create role:** «AWS service», caso de uso «EC2», sin políticas. Ya creado,
   «Add permissions → Create inline policy», en JSON, con el nombre del bucket:

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [{
       "Sid": "SoloSubirRespaldos",
       "Effect": "Allow",
       "Action": ["s3:PutObject", "s3:AbortMultipartUpload"],
       "Resource": "arn:aws:s3:::indiecinema-respaldos-cerberus/diario/*"
     }]
   }
   ```

4. **EC2 → la instancia → Actions → Security → Modify IAM role:** el rol nuevo.

## En el servidor

En `.env`, el bucket con la carpeta y su región:

```
RESPALDO_S3=s3://indiecinema-respaldos-cerberus/diario
RESPALDO_REGION=us-east-1
```

El primero se corre a mano; después lo dispara el timer de systemd. Las unidades suponen el clon en
`/home/ubuntu/indiecinema/backend-indiecinema` y el usuario `ubuntu` en el grupo `docker`.

```
herramientas/respaldo-diario.sh
sudo cp herramientas/systemd/indiecinema-respaldo.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now indiecinema-respaldo.timer
systemctl list-timers indiecinema-respaldo.timer   # la próxima corrida
journalctl -u indiecinema-respaldo                 # qué pasó en cada una
```

## Restaurar

En cualquier máquina con el entorno levantado (README, «Levantar el entorno»):

1. Bajar los dos archivos de la fecha desde la consola de S3: el servidor no puede leerlos.
2. Restaurarlos y aplicar las migraciones posteriores al respaldo, si las hay:

   ```
   herramientas/restaurar.sh indiecinema-<fecha>.sql.gz programacion-<fecha>.tar.gz
   docker compose exec mysql bash /herramientas/migrar.sh
   ```

Pisa las tablas que trae el respaldo. Los usuarios de MySQL y sus permisos no van en el respaldo:
los crea `mysql/init` con las claves del `.env` de esa máquina, así que restaurar no copia los
secretos del servidor.

## Pruebas de restauración

- 17/10/2026: el respaldo del entorno de desarrollo, restaurado en un entorno nuevo (otro proyecto
  de compose, con la base recién creada): las 11 tablas con las mismas filas, los 20 archivos con
  el mismo SHA-256, y `comprobar-aislamiento.sh` y `migrar.sh` sin cambios.
