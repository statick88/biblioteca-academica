# Biblioteca Académica

Aplicación académica de biblioteca, renderizada en servidor con PHP, para gestionar catálogo, usuarios de biblioteca, solicitudes, préstamos, devoluciones y acceso por roles. Repositorio público: [`statick88/biblioteca-academica`](https://github.com/statick88/biblioteca-academica).

## Alcance y funciones

- Catálogo y búsqueda para cuentas autenticadas.
- Solicitudes propias de lectores; confirmación y devolución por personal.
- Administración de libros, usuarios de biblioteca y cuentas según el rol.
- Migración aditiva por CLI: conserva las tablas y filas históricas.
- Sin alta pública, cuentas predeterminadas ni migraciones desde HTTP.

| Rol | Alcance |
| --- | --- |
| Lector | Consulta catálogo y solo sus solicitudes y préstamos. |
| Bibliotecario | Además gestiona libros/usuarios, confirma solicitudes y registra devoluciones. |
| Administrador | Además crea, activa y desactiva cuentas. |

## Requisitos

- PHP 8.2+ con `mysqli`, `mbstring` y sesiones.
- MySQL 8.
- Servidor web con `src` como document root, o el servidor integrado de PHP para desarrollo local.
- Docker es opcional. Node y Chrome solo son necesarios para la comprobación de navegador descrita en [docs/testing.md](docs/testing.md).

No se requieren paquetes de aplicación de Composer, npm ni framework.

## Ruta rápida segura (instalación vacía)

1. Clone en un directorio nuevo, sin renombrar una instalación existente:

   ```sh
   git clone https://github.com/statick88/biblioteca-academica.git biblioteca-academica
   cd biblioteca-academica
   ```

2. Siga [la instalación](docs/installation.md): cree por separado el usuario MySQL de ejecución, configure variables de proceso y ejecute el preflight antes de `--apply`.
3. Cree el primer administrador por stdin según [autenticación](docs/authentication.md).
4. Para HTTP local, use explícitamente `APP_ENV=local` y abra `http://127.0.0.1:8080/index.php?page=login`.

Para una base con datos, no use esa ruta como si fuera una instalación vacía: siga [migración y preservación](docs/migration.md) y confirme primero la copia de seguridad, el preflight y la autorización del operador.

## Mapa del proyecto

| Área | Referencia |
| --- | --- |
| Instalación, variables y Docker opcional | [docs/installation.md](docs/installation.md) |
| Migración aditiva y datos históricos | [docs/migration.md](docs/migration.md) |
| Login, sesiones y primer administrador | [docs/authentication.md](docs/authentication.md) |
| Capas y decisiones | [docs/architecture.md](docs/architecture.md) |
| Contrato de `LibraryService` | [docs/library-service.md](docs/library-service.md) |
| Rutas y flujos web | [docs/web-interface.md](docs/web-interface.md) |
| Pruebas reproducibles | [docs/testing.md](docs/testing.md) |
| Evidencia histórica y límites aceptados | [docs/verification.md](docs/verification.md) |
| UML conceptual y clases PHP reales | [entrega/taller-1.3/README.md](entrega/taller-1.3/README.md) |

`Libro`, `Usuario` y `Prestamo` son entidades conceptuales del UML; no son clases PHP individuales. Las operaciones actuales están en `Library\LibraryService`; los diagramas actuales ya están en `entrega/taller-1.3` y no se regeneran desde esta guía.

## Estado de publicación y verificación

El repositorio se publica como proyecto académico, sin despliegue de la aplicación. No es una certificación de producción, versión liberada ni garantía de endurecimiento. La migración no se ejecutó sobre datos de aula reales. La evidencia disponible, las comprobaciones que no tuvieron dictamen independiente y sus límites aceptados están preservados en [docs/verification.md](docs/verification.md); no se sustituyen por una afirmación de cobertura o aprobación.

## Participar y reportar riesgos

- [CONTRIBUTING.md](CONTRIBUTING.md): cambios y comprobaciones locales.
- [SECURITY.md](SECURITY.md): reporte responsable y límites académicos.
- [CHANGELOG.md](CHANGELOG.md): cambios documentados sin inventar versiones o etiquetas.
