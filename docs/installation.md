# Instalación

Esta guía separa una instalación vacía de la migración de una base existente. PHP toma la configuración del entorno del proceso; **no interpreta archivos `.env`**. Si Compose lee un archivo de entorno del operador, ese archivo sigue siendo responsabilidad del operador; no hay un `.env.example` que copiar.

## Requisitos

PHP 8.2+ requiere las extensiones `mysqli`, `mbstring` y sesiones; MySQL debe ser 8. No hay dependencias de Composer, npm ni framework. Docker es opcional. Node y Chrome se reservan para las pruebas de navegador, no para ejecutar la aplicación.

## Instalación vacía

1. Cree una base vacía y dos identidades MySQL: una de migración con permiso DDL y otra de ejecución, limitada a la base de esta aplicación con `SELECT`, `INSERT` y `UPDATE`. La inicialización de MySQL de Compose crea la base y el usuario root; **no crea automáticamente** el usuario de ejecución `DB_USER`.
2. Configure estas variables para el proceso PHP o para el servicio web:

   | Variable | Uso |
   | --- | --- |
   | `DB_HOST` | Host MySQL. |
   | `DB_PORT` | Puerto MySQL que usa el proceso PHP. |
   | `DB_NAME` | Base de la aplicación. |
   | `DB_USER` | Usuario de ejecución de mínimo privilegio. |
   | `DB_PASSWORD` | Secreto de ese usuario. |
   | `APP_ENV` | `production` por defecto; use `local` solo para HTTP local. |
   | `SESSION_SAVE_PATH` | Opcional; ruta de almacenamiento de sesiones PHP. |

   No ponga secretos reales en comandos con historial, repositorio ni capturas.
3. Con credenciales de operador de migración, ejecute primero `php bin/migrate.php`; revise el preflight. Tras respaldo verificado y autorización explícita, ejecute `php bin/migrate.php --apply`. Cambie después a las credenciales de ejecución.
4. Cree el primer administrador con el secreto por stdin. En Bash, una entrada enmascarada evita incluirlo como argumento:

   ```bash
   read -rs -p 'Contraseña inicial: ' ADMIN_PASSWORD; echo
   printf '%s\n' "$ADMIN_PASSWORD" | php bin/create-admin.php --username admin --name 'Administrador inicial' --email admin@example.invalid
   unset ADMIN_PASSWORD
   ```

   También puede vincular un usuario de biblioteca ya existente con `--user-id`. La contraseña debe tener 12–72 bytes y no contener NUL. Consulte [autenticación](authentication.md) para las dos formas admitidas.
5. Para desarrollo HTTP local, use `APP_ENV=local`; las cookies Secure se desactivan únicamente para este caso:

   ```bash
   # Use las credenciales de ejecución, no las de migración.
   export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=library DB_USER=library_user
   read -rs -p 'Contraseña MySQL de ejecución: ' DB_PASSWORD; echo
   export DB_PASSWORD
   APP_ENV=local php -S 127.0.0.1:8080 -t src src/router.php
   ```

   Abra `http://127.0.0.1:8080/index.php?page=login`. En producción mantenga `APP_ENV=production` (o no lo defina) y proporcione HTTPS en el despliegue; la aplicación no configura TLS por sí misma.

## Base existente: migración de operador

No trate una base con préstamos históricos como una instalación nueva. Primero haga y verifique una copia de seguridad, ejecute el preflight sin `--apply`, revise sus conteos y obtenga autorización para ese destino. Luego aplique el DDL con credenciales de migración. No hay reparación, deduplicación ni borrado automáticos: préstamos existentes sin retorno se consideran pendientes hasta que personal registre el retorno; los retornos históricos no se pueden recuperar automáticamente.

La migración no se ejecuta por HTTP ni crea datos de demostración. El detalle de preservación, preflight y repetición tras una interrupción está en [migration.md](migration.md).

## Docker Compose opcional

La imagen usa `/var/www/library` como `WORKDIR` y Apache sirve `/var/www/library/src`. Se puede usar el plugin `docker compose` **o** el binario disponible `docker-compose`; en este entorno se detectó el segundo y no el plugin. Use la variante disponible:

```sh
# elija una sola forma según su instalación
docker compose up -d --build
# o
docker-compose up -d --build
```

Compose usa `DB_PORT` para el puerto host de MySQL y `DB_PORT_INTERNAL` (por defecto `3306`) para la conexión `web` → `db`; `WEB_PORT` publica Apache. `DB_ROOT_PASSWORD` es para inicialización/operación de MySQL, no para el proceso web. `DB_USER` y `DB_PASSWORD` del proceso web son obligatorios.

No ejecute `docker compose down -v` ni su equivalente: el archivo declara `mysql_data_final`. Cambiar únicamente el nombre remoto no altera los volúmenes. En una instalación existente, mover/renombrar la carpeta local o usar un clon nuevo sí puede cambiar el nombre de proyecto y el prefijo de volumen predeterminados. Antes de operar Compose, inspeccione etiquetas/metadatos del volumen y la configuración existente para conservar su nombre de proyecto establecido (`COMPOSE_PROJECT_NAME` o `-p`). No se debe asumir un identificador de proyecto, nombre de volumen real ni renombrar contenedores de producción para que coincidan con el repositorio.
