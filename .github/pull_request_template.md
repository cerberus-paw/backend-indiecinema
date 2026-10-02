## Resumen

Implementa [IC-](https://nomicomateo.atlassian.net/browse/IC-): …

Sale de `main` / va después de #…

## Cambios

**…**
- …

## Notas

- **Para …:** …
- **Migraciones:** …

## Cómo probar

```
```

## Lista de verificación de seguridad

La revisa quien aprueba el PR (E1, seguridad en el desarrollo). Lo que no aplica se tacha con `~~…~~`.

- [ ] Ningún secreto en el diff: ni claves, tokens, `.env` ni `config.ini`; si hay una clave nueva, está en el ejemplo con `cambiar`.
- [ ] Toda consulta a la base es preparada (`BaseDeDatos`), sin valores pegados al SQL, y sólo sobre el esquema propio.
- [ ] Cada ruta nueva declara su rol mínimo, y el servicio verifica que el recurso es del usuario (un organizador no toca la sala de otro).
- [ ] Todo formulario que cambia algo es POST y lleva el token CSRF.
- [ ] Lo que viene del usuario se muestra con el autoescape de Twig, sin `|raw`.
- [ ] Los archivos subidos se validan por contenido y tamaño, se guardan con un nombre al azar fuera de `public/` y los entrega un controlador que verifica el rol.
- [ ] Cada ruta `/interno/` nueva declara qué subsistemas pueden llamarla.
- [ ] Los errores no le muestran detalles al usuario, y el log no registra datos personales.
- [ ] Si cambian las dependencias, el `composer.lock` está incluido y `composer audit` no marca nada.
- [ ] Las pruebas pasan (`composer pruebas -d nucleo` y las del subsistema).
