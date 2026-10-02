# Imagen común a cuentas, programación y funciones: una sola, así una actualización de PHP o de
# Apache se hace en un solo lugar. La E2 fija php:8-apache; se fija además la versión menor
# para que todos (y el VPS) corran exactamente la misma, la mínima que pide composer.json.
FROM php:8.4-apache

# curl ya viene en la imagen (para la API interna y Mercado Pago); falta el driver de MySQL.
RUN docker-php-ext-install pdo_mysql

# La configuración de producción de PHP (sin errores en pantalla, trazas sin argumentos) y
# encima la nuestra. Es la misma en desarrollo: el detalle de los errores lo decide config.ini.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY php.ini "$PHP_INI_DIR/conf.d/indiecinema.ini"

# Apache corre entero como www-data: escucha en el 8080 porque un usuario sin privilegios no
# puede abrir un puerto menor a 1024. Sólo lo alcanza nginx, por la red interna del compose.
RUN sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && a2dissite -q 000-default \
    && a2disconf -q other-vhosts-access-log serve-cgi-bin localized-error-pages
COPY sitio.conf /etc/apache2/sites-available/indiecinema.conf
RUN a2ensite -q indiecinema

# Los archivos subidos (imagen de la sala, afiche) van a un volumen fuera del webroot. Docker
# copia el dueño de esta carpeta al volumen la primera vez, así www-data puede escribir ahí.
RUN mkdir -p /var/indiecinema/archivos && chown www-data:www-data /var/indiecinema/archivos

# Lo cambia el compose para cada subsistema.
ENV APACHE_DOCUMENT_ROOT=/var/www/html

USER www-data
EXPOSE 8080
