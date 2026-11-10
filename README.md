# Biblioteca académica

Proyecto PHP 8.2 para una biblioteca con catálogo, usuarios, solicitudes, préstamos, devoluciones y control de acceso por roles. Es una aplicación renderizada en servidor: no requiere paquetes, CDN ni registro público.

## Configuración

Defina `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD` en el proceso. Use un usuario de base de datos explícitamente limitado a la base de la aplicación; **no** use `root` en producción. El uso de root puede ser una compatibilidad local de desarrollo, nunca una configuración recomendada.

Primero revise y aplique la migración aditiva según [docs/migration.md](docs/migration.md). No hay migración, DDL ni datos de demostración desde HTTP. Cree el primer administrador solo con el comando CLI descrito en [docs/authentication.md](docs/authentication.md); no hay cuentas ni contraseñas predeterminadas.

Para desarrollo HTTP local use explícitamente `APP_ENV=local` (cookies Secure desactivadas solo para HTTP local):

```sh
APP_ENV=local DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=library DB_USER=library_user DB_PASSWORD=replace_with_local_secret php -S 127.0.0.1:8080 -t src src/router.php
```

Abra `http://127.0.0.1:8080/index.php?page=login`. Apache usa el mismo document root (`src`) y bloquea `app`, `views`, `bin` y pruebas. Docker Compose conserva el volumen heredado `mysql_data_final`; no lo reinicia ni borra. Proporcione explícitamente `DB_USER` y `DB_PASSWORD` para el proceso web; Compose falla cerrado si faltan. Conserve `DB_ROOT_PASSWORD`, `DB_NAME`, `WEB_PORT` y `DB_PORT` para compatibilidad de Compose. Cree un usuario de aplicación de mínimos privilegios antes de arrancar; el usuario root de MySQL es solo una compatibilidad de desarrollo, no un valor predeterminado de la aplicación.

## Roles

- **Lector:** catálogo y sus propios préstamos/solicitudes; solicita libros para sí mismo.
- **Bibliotecario:** además administra libros/usuarios y confirma/devolver préstamos.
- **Administrador:** además aprovisiona y activa/desactiva cuentas.

## Verificación

El fixture está guardado para `127.0.0.1:32768`, `library_fixture`, usuario `root` y clave sintética. No apunta al volumen de Docker ni a una base real.

```sh
DB_HOST=127.0.0.1 DB_PORT=32768 DB_NAME=library_fixture DB_USER=root DB_PASSWORD=library_test_only_01a1c355 php tests/http_test.php
DB_HOST=127.0.0.1 DB_PORT=32768 DB_NAME=library_fixture DB_USER=root DB_PASSWORD=library_test_only_01a1c355 php tests/usuario_test.php
```

La segunda prueba conserva el resultado de registro de usuario del monolito anterior en el límite HTTP público, en lugar de extraer clases desde `index.php`. Más detalles de rutas, accesibilidad y contratos están en [docs/web-interface.md](docs/web-interface.md).
