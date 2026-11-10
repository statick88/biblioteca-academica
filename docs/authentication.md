# Authentication and account administration

`Library\Auth` receives a `mysqli` connection and a `Library\Session`; controllers must construct both from `Database::connect()` and must never accept an account, role, or library user ID from a request as authorization evidence. `Auth` re-reads the active account row from `accounts` for every `currentAccount`, `requireAccount`, `requireRole`, and administrative call. It returns only safe account fields:

```php
array{id:int, user_id:int, username:string, role:'reader'|'librarian'|'admin', active:bool}
```

Password hashes and plaintext passwords are never returned. El lector usa únicamente el `user_id` de su cuenta actual para sus solicitudes y préstamos; los controladores de personal usan `requireRole('librarian', 'admin')`; la administración de cuentas usa `requireRole('admin')` internamente.

## Public API for controllers

- `login(string $username, string $password, string $ip): array{ok:bool, account:?array}` normalizes the username, validates the server-supplied remote IP, and returns either `['ok' => true, 'account' => safeAccount]` or exactly `['ok' => false, 'account' => null]`. Unknown usernames, wrong passwords, inactive accounts, and throttle refusal share that failure result. Controllers must obtain `ip` from the direct server connection, never a forwarded header.
- `currentAccount(): ?array` returns a current active safe account or invalidates a stale/deactivated/expired session and returns `null`.
- `requireAccount(): array` throws `AuthorizationException` when no active current account exists.
- `requireRole(string ...$roles): array` throws `ValidationException` for an invalid role name and `AuthorizationException` when the active current account lacks one of the requested roles.
- `logout(): void` invalidates the PHP session. Controllers must require a valid CSRF token before calling it from a state-changing request.
- `createAccount(int $userId, string $username, string $password, string $role): array` requires an authenticated admin, validates an existing legacy `usuarios.id_usuario`, and returns a safe account. It throws `ValidationException` for invalid input or a duplicate username/user link. No existing legacy email is used to select an account.
- `setAccountActive(int $id, bool $active): void` requires an authenticated admin. It throws `ValidationException` for an invalid target or an attempt to disable the last active admin. It locks relevant account rows transactionally; concurrent deactivate operations serialize so the invariant remains true.
- `listAccounts(): list<array>` requiere administrador autenticado y devuelve solo campos seguros; la interfaz actual solo lo expone en la página administrativa.

Usernames are trimmed, lowercased ASCII identifiers (`[a-z0-9][a-z0-9._-]{2,99}`), so provisioning and login use the same identity. Roles are exactly `reader`, `librarian`, and `admin`. Passwords are required to be 12–72 bytes and must contain no NUL byte. The upper bound is intentional because the selected bcrypt implementation silently truncates after 72 bytes. Hashing uses `password_hash(..., PASSWORD_BCRYPT, ['cost' => 12])`; verification uses `password_verify`.

## Session and CSRF boundary

`Session` enables `session.use_strict_mode`, HttpOnly/Lax cookies, a bounded inactivity timeout, random CSRF values, and session ID rotation at login. After expiry, the prior identity and CSRF value are destroyed; the next helper call begins a fresh anonymous persisted session with a new identifier and CSRF token. Its default cookie configuration sets `Secure`; local HTTP development must opt in explicitly by constructing `new Session(timeoutSeconds: 1800, secureCookies: false)`. Production HTTPS controllers must retain the default. `csrfToken(): string` supplies a token for forms; `verifyCsrf(?string): bool` uses `hash_equals` and returns false for absent or invalid tokens. Check it before every mutation, including login and logout; rejection must return before calling `Auth` or another mutating service.

`Auth` rate-limits failed attempts in `auth_attempts` by SHA-256 hashes of normalized identifier and direct IP. Counters use a bounded database window and atomic upsert, so no raw username/IP is stored and process-local counters are not trusted. Session IDs, CSRF tokens, passwords, and password hashes must never be logged.

## First administrator CLI

`php bin/create-admin.php --help` prints the command interface. Las únicas formas admitidas son `--username` con **uno** de estos grupos: `--user-id`, o `--name` y `--email`. En Bash, lea el secreto de forma enmascarada y páselo por stdin, sin ponerlo en el historial:

```bash
read -rs -p 'Contraseña inicial: ' ADMIN_PASSWORD; echo
printf '%s\n' "$ADMIN_PASSWORD" | php bin/create-admin.php --username admin --user-id 12
unset ADMIN_PASSWORD
```

Para crear también el usuario de biblioteca inicial, use ese pipeline **antes de** `unset ADMIN_PASSWORD` en lugar del de `--user-id`:

```bash
printf '%s\n' "$ADMIN_PASSWORD" | php bin/create-admin.php --username admin --name 'Administrador inicial' --email admin@example.invalid
unset ADMIN_PASSWORD
```

La contraseña se lee solo desde entrada estándar; no hay opción ni valor predeterminado de contraseña. Debe tener 12–72 bytes y no contener NUL. El comando es solo CLI, comprueba que existan `usuarios` y `accounts` sin invocar `Schema::apply`, y se niega si ya existe una cuenta. Un bloqueo con nombre de MySQL serializa procesos de bootstrap concurrentes. Crea el usuario heredado opcional y la cuenta administrativa en una transacción, o vincula el usuario existente sin modificarlo. Tras la primera cuenta, los administradores autenticados aprovisionan cuentas con `Auth::createAccount`.
