# Despliegue en Coolify — Tapanko Vallarta

## 1. Base de datos
1. En Coolify: **+ New Resource → Database → MySQL** (o MariaDB). Anota el host interno, puerto, nombre de BD, usuario y contraseña que muestra Coolify.
2. Importa `schema_vallarta.sql` (desde el terminal del servicio o con phpMyAdmin/HeidiSQL conectado por el puerto público temporal). Crea las 7 tablas y el usuario `admin` con contraseña temporal `vallarta2026`.

## 2. Aplicación
1. Sube esta carpeta a un repositorio Git (GitHub/GitLab, puede ser privado). Coolify despliega desde Git.
2. En Coolify: **+ New Resource → Application → tu repositorio**, Build Pack **Dockerfile** (usa el `Dockerfile` de la raíz). Puerto expuesto: **80**.
3. En **Environment Variables** captura las de `.env.example` (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_TIMEZONE, TZ).
   - `DB_HOST` es el host interno del servicio MySQL (la app y la BD deben estar en el mismo proyecto/red de Coolify).
4. Asigna el dominio (ej. `rppue.tudominio.com`); Coolify genera el certificado HTTPS.
5. Deploy. Entra con `admin` / `vallarta2026` y cambia la contraseña.

## 3. Tarea programada (inasistencias)
En la aplicación → **Scheduled Tasks**:
- Comando: `php /var/www/html/scripts/verificar_inasistencias.php`
- Frecuencia: una vez al día (ej. `0 8 * * *`).

## Notas
- Los turnos (5:00 pm a 4:59 pm) y las horas de cierre dependen de `TZ` y `DB_TIMEZONE`; no los dejes en UTC.
- Los folios de esta instancia son `VTA###`; los de CDMX son `MX###`. Son sistemas y bases de datos independientes.
- `schema_vallarta.sql` y este archivo no entran en la imagen (ver `.dockerignore`).
