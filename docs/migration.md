# Library database migration

`php bin/migrate.php` runs a **read-only** aggregate preflight. It reports only counts for legacy table rows, null and orphaned loan references, duplicate outstanding loans by book, book availability mismatches, and duplicate legacy emails. It never prints personal values or changes data.

`php bin/migrate.php --apply` creates missing tables only. It is additive: it preserves every row and identifier in `libros`, `usuarios`, and `prestamos`; it does not deduplicate emails, repair legacy loans or availability values, insert demo records, create accounts, or modify legacy columns. The command is CLI-only. `--help` describes the interface; all other flags fail before a database connection or write.

Use process environment configuration; the PHP code does not parse `.env` files. Do not put real credentials in source or command-line arguments.

| Variable | Required | Format | Safe placeholder |
| --- | --- | --- | --- |
| `DB_HOST` | yes | MySQL host name or address | `127.0.0.1` |
| `DB_PORT` | yes | integer from 1 to 65535 | `3306` |
| `DB_NAME` | yes | target database name | `library` |
| `DB_USER` | yes | database login name | `library_user` |
| `DB_PASSWORD` | yes | secret supplied by process environment | `replace_with_local_secret` |

## Operator sequence

1. Take and verify a backup of the target database.
2. Run `php bin/migrate.php` and review the aggregate preflight counts.
3. Obtain explicit operator authorization for the named target.
4. Run `php bin/migrate.php --apply`.
5. Re-run the same apply command if an earlier attempt was interrupted.

MySQL DDL implicitly commits. Consequently, `--apply` is not transactionally reversible, but table-existence checks make it safe to rerun after a partial create. Before any DDL, both `Schema::apply()` and the CLI run the same read-only preflight. An entirely empty database is permitted; one or two legacy tables, malformed legacy tables, or malformed existing additive tables are rejected without repair or writes. This task does not authorize running the command against production or the legacy classroom volume.

The original legacy definition is fixed rather than inferred: `libros` has `titulo VARCHAR(150)`, `autor VARCHAR(100)`, nullable `disponible TINYINT(1) DEFAULT 1`; `usuarios` has non-null `nombre VARCHAR(100)` and `correo VARCHAR(100)`; `prestamos` has nullable `id_usuario`/`id_libro`, `fecha_salida DATE NOT NULL`, and foreign keys to the original identifiers. Validation accepts harmless MySQL metadata normalization (for example, integer display widths) but does not accept English aliases or silently reinterpret types. Historical bad **data** remains valid migration input and is counted by preflight; fixture tests temporarily disable foreign-key enforcement only to create that historical-data case, never in application migration code.

## Additive schema contract

The new tables intentionally have no foreign keys into legacy data: legacy rows may contain null or orphaned references and must remain untouched. `LibraryService` valida las referencias dentro de sus transacciones.

### `accounts`

| Field | Contract |
| --- | --- |
| `id` | Additive account identifier. |
| `user_id` | Unique link to one `usuarios` legacy identifier. |
| `username` | Unique login name; independent of legacy email duplicates. |
| `password_hash` | Password API output only; never plaintext. |
| `role` | `reader`, `librarian`, or `admin`. |
| `active` | Account eligibility flag. |
| `created_at`, `updated_at` | Audit timestamps. |

No existing library user gains an account automatically.

### `loan_requests`

`user_id` and `book_id` identify a requested legacy user/book. `status` is `pending` or `confirmed`; confirmation stores the resulting legacy `loan_id`, `confirmed_at`, and `confirmed_by_account_id`. `requested_at`, `created_at`, and `updated_at` are audit times. Requests do not reserve a book.

### `loan_returns`

`loan_id` is the primary key, so a legacy loan can be returned at most once. `returned_at` and `returned_by_account_id` identify the staff action; `created_at` is the record timestamp.

### `auth_attempts`

Authentication throttling consumes `identifier_hash`, `ip_hash`, `window_started_at`, `attempt_count`, and `updated_at`. Hashes, rather than raw usernames or IP addresses, minimize sensitive data. The unique tuple `(identifier_hash, ip_hash, window_started_at)` supports atomic counter updates per rate-limit window.

## Integración actual de autenticación y préstamos

`Auth` aprovisiona cuentas y escribe `auth_attempts` mediante consultas preparadas, hash de contraseñas, fallos genéricos y comprobaciones de cuenta activa/rol en el servidor. No infiere la identidad de inicio de sesión desde el correo heredado ni modifica datos heredados. El flujo de préstamos serializa confirmaciones y devoluciones sobre la fila de libro heredada y usa `loan_returns` como marcador de retorno.
