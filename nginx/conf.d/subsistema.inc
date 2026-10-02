# Lo común a los tres subsistemas. Va en un archivo aparte porque, si una location define una
# sola cabecera propia (como el secreto de cada subsistema, IC-31), deja de heredar todas las
# del server.

# Con el nombre en una variable, se resuelve en cada pedido (ver «resolver»). Apache escucha en
# el 8080 porque corre sin privilegios (php/Dockerfile).
proxy_pass http://$subsistema:8080;
proxy_http_version 1.1;
proxy_set_header Host $host;
proxy_set_header X-Forwarded-Proto $scheme;

# La identidad la pone sólo nginx, después de validar la sesión con cuentas (IC-31): si el
# navegador manda estas cabeceras, se descartan.
proxy_set_header X-Usuario-Id "";
proxy_set_header X-Rol "";
proxy_set_header X-Usuario-Nombre "";
proxy_set_header X-Nginx-Secreto "";
