# Contribuir

Gracias por mejorar este proyecto académico. Mantenga los cambios pequeños, trazables y compatibles con la preservación de datos históricos.

## Antes de enviar un cambio

- No incluya credenciales, datos de estudiantes, archivos de entorno ni salidas de bases reales.
- No agregue migración, seed ni DDL por HTTP; documente los cambios de esquema en [docs/migration.md](docs/migration.md).
- Use PHP 8.2+, `declare(strict_types=1)`, tipos y validación explícita; conserve consultas parametrizadas y escape de vistas.
- Mantenga la interfaz y las guías técnicas en español, siguiendo la convención existente.
- Use mensajes de commit Conventional Commits, por ejemplo `docs: aclara instalación local` o `fix: rechaza solicitud duplicada`.

## Comprobaciones locales

Ejecute la guía de [pruebas](docs/testing.md) con el fixture desechable antes de proponer cambios de comportamiento. Para cambios de documentación, revise enlaces relativos y no convierta evidencia histórica limitada en una garantía.

No se documentan reglas ficticias de CI, etiquetas protegidas ni aprobaciones obligatorias: valide los cambios con las comprobaciones disponibles y describa qué no se ejecutó.
