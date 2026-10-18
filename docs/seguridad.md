# Seguridad por capa

Qué protege cada capa de IndieCinema y dónde está configurado. Red y sistema operativo describen
el VPS de https://indiecinema.duckdns.org tal como quedó el 18/10/2026: una instancia EC2 de AWS
(t3.small) con IP elástica, donde corre el `docker-compose.yml` de este repositorio.

## Red

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Cortafuegos fuera de la máquina: sólo entran el 22 (SSH), el 80 y el 443, por IPv4. El SSH queda abierto a cualquier origen porque el despliegue continuo entra desde las máquinas de GitHub Actions, que no tienen IP fija; lo cuidan la clave, fail2ban y que la clave del despliegue sólo puede correr el script (capa de sistema operativo) | reglas de entrada del security group de la instancia en AWS (ufw no se usa) | consola de EC2 → la instancia → Security |
| Sólo HTTPS. Por el 80 se redirige a HTTPS (301) y se atiende el desafío de Let's Encrypt | `nginx/templates/servidor-https.inc.template`, que se activa desde `.env` con `docker-compose.https.yml` | `curl -I http://indiecinema.duckdns.org/` |
| TLS 1.2 y 1.3, sin versiones anteriores, y cifrados con secreto hacia adelante (ECDHE) | `ssl_protocols` y `ssl_ciphers` en la misma plantilla | `openssl s_client -tls1_1 …` responde `protocol version` |
| Certificado de Let's Encrypt que se renueva solo: certbot por webroot y un hook que recarga nginx | `certbot.timer` en el servidor (README, «HTTPS en el VPS») | `sudo certbot certificates` |
| HSTS por un año: el navegador ya no intenta por HTTP | `Strict-Transport-Security` en `servidor-https.inc.template` | `curl -I https://indiecinema.duckdns.org/` |
| Cabeceras contra el sniffing de tipos, el clickjacking y la fuga del Referer, y sin la versión de nginx | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` y `server_tokens off` en `nginx/templates/sitio.inc.template` | la misma respuesta |
| Un solo punto de entrada: sólo nginx publica puertos. MySQL y los subsistemas quedan en la red interna del compose | `ports` de `docker-compose.yml` | en el servidor escuchan sólo el 22, el 80 y el 443 (`ss -tln`) |
| El buzón de prueba (Mailpit), que simula el envío de correos, sólo escucha en la máquina | `127.0.0.1:8025` en `docker-compose.yml` | se ve con un túnel: `ssh -L 8025:127.0.0.1:8025 …` |
| Las rutas `/interno/` (las APIs entre subsistemas) y el `auth_request` de la sesión no se alcanzan desde afuera | `nginx/templates/sitio.inc.template` | `nginx/comprobar-desde-afuera.sh https://indiecinema.duckdns.org`, que también corre la CI |
| Límite de pedidos por IP al ingresar, registrarse y pedir una sala | `limit_req_zone` en `nginx/templates/indiecinema.conf.template` | superar el límite responde 429 |
| Los metadatos de la instancia (de donde saldrían credenciales de AWS) sólo con IMDSv2, que pide un token | configuración de la instancia en AWS | sin token, `curl http://169.254.169.254/latest/meta-data/` responde 401 |

## Sistema operativo

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Ubuntu 26.04 LTS con las actualizaciones de seguridad automáticas | `unattended-upgrades` activo y `/etc/apt/apt.conf.d/20auto-upgrades` | `systemctl status unattended-upgrades` |
| SSH sólo con clave: sin contraseñas y sin entrar como root | `PasswordAuthentication no`, `KbdInteractiveAuthentication no` y `PermitRootLogin no` | `sudo sshd -T` |
| Bloqueo de quien prueba claves por SSH: 5 intentos en 10 minutos dejan la IP 10 minutos afuera | jaula `sshd` de fail2ban | `sudo fail2ban-client status sshd` |
| La clave del despliegue continuo (el secreto de GitHub) sólo corre `herramientas/desplegar.sh`: no elige el comando ni abre una terminal o un túnel | `restrict,command=…` en su línea de `~/.ssh/authorized_keys` (README, «Despliegue continuo») | con esa clave, `ssh … id` despliega en vez de correr `id` |
| Un solo usuario con acceso, `ubuntu`, que despliega con Docker sin ser root | grupo `docker` | `id ubuntu` |
| Los secretos no están en el repositorio, sólo los lee el usuario del despliegue, y gitleaks revisa cada pull request y cada push a `main` | `.env` (600) y cada `config.ini` (640), que ignora `.gitignore`; `.gitleaks.toml` y la CI | `stat -c '%a %n' .env */config/config.ini` |
| Cada subsistema corre como `www-data`, sin ninguna capacidad de root, sin poder ganar privilegios y con el sistema de archivos de sólo lectura | `php/Dockerfile` y `x-subsistema` en `docker-compose.yml` (`cap_drop: [ALL]`, `no-new-privileges`, `read_only`) | `docker compose exec programacion id` |
| Un subsistema comprometido no lee el código ni los secretos de los otros: cada uno monta sólo su carpeta | `volumes` de cada subsistema en `docker-compose.yml` | — |
| Docker aísla los contenedores con AppArmor y seccomp | perfiles por defecto de Docker | `docker info` |
