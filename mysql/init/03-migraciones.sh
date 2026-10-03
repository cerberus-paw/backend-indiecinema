# Con la base recién creada, aplica las migraciones que ya hay: así un clon nuevo queda con todas
# las tablas con un solo «docker compose up». Las que se sumen después se aplican a mano con
# mysql/herramientas/migrar.sh (ver el README).

mysql_note "Migraciones de los subsistemas"
bash /herramientas/migrar.sh
