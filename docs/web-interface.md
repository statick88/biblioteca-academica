# Interfaz web

La aplicación usa HTML renderizado en servidor, CSS propio y JavaScript progresivo. `src/index.php` es el front controller: solo admite las páginas `login`, `dashboard`, `books`, `users`, `requests`, `loans` y `accounts`, y las acciones POST documentadas en `docs/architecture.md`.

`Library\Web` concentra el límite HTTP: encabezados, escape UTF-8, validación de forma/tamaño, CSRF, mensajes y redirecciones PRG. Las vistas nunca consultan SQL; `Auth` y `LibraryService` siguen siendo la autoridad de identidad, roles, propiedad y reglas de préstamo. Todas las mutaciones POST validan CSRF antes de invocar esos servicios. Las respuestas no exponen detalles de infraestructura.

## Flujos

- Un lector busca el catálogo y solicita solo para su identidad vinculada. Ve únicamente sus solicitudes y préstamos.
- Bibliotecario y administrador crean/actualizan libros y usuarios, confirman solicitudes y registran devoluciones.
- Solo administrador aprovisiona cuentas y activa/desactiva cuentas. La asignación de rol ocurre al aprovisionar; no existe edición de roles inventada.
- No hay borrados ni alta pública. Las filas históricas sin referencias se muestran con un marcador seguro.

La barra lateral es persistente en escritorio. En móvil permanece visible y navegable sin JavaScript; al cargar JavaScript se habilita de forma progresiva un control colapsable, con Escape y retorno de foco. Formularios funcionan sin JavaScript; éste solo mejora menú, confirmación y texto de envío. Los errores de validación vuelven al formulario autorizado con resumen, valores no secretos permitidos y estado 422. Estados se expresan con texto, no solamente color.

## Ejecución local y pruebas

Use una base aislada y un usuario MySQL de mínimos privilegios para la aplicación. Para HTTP local explícito use `APP_ENV=local`; en producción omítalo para conservar cookies `Secure`.

```sh
php -S 127.0.0.1:8080 -t src src/router.php
DB_HOST=127.0.0.1 DB_PORT=32768 DB_NAME=library_fixture DB_USER=root DB_PASSWORD=library_test_only_01a1c355 php tests/http_test.php
```

El router integrado solo expone `assets` y el front controller; deniega `app`, `views`, rutas de pruebas y traversal. Las pruebas levantan un servidor breve en localhost, usan sesiones separadas y solo mutan el fixture exacto guardado.
