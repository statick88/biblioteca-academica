# Library architecture

## Scope and stack

This is an academic application using PHP 8.2+, mysqli/MySQL 8, server-rendered HTML, CSS and progressive JavaScript. No framework or new package is required. UI copy follows the existing Spanish convention. Browser JavaScript is an enhancement, never an authorization boundary.

## Layers and contracts

- `src/app/bootstrap.php`: autoload `Library\` classes from `src/app/`. `Database` reads environment configuration; `Session` controls sessions and `Web` emits response headers. Configuration uses `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`; no credentials in source.
- `src/app/Database.php`: shared connection factory `Database::connect(): mysqli`; strict mysqli errors and utf8mb4. CLI configuration comes from process environment, not an implicit .env parser.
- `src/app/Schema.php`: additive migration and read-only preflight, runnable only through CLI. HTTP GET never creates tables or sample data.
- `src/app/Auth.php`: session authentication and server-side role and ownership policy, CSRF, password hashing, generic login failures and shared database-backed login throttling.
- `src/app/LibraryService.php`: books, library users, requests, confirmation and returns. Receives mysqli explicitly; all variable SQL uses prepared statements. Transactional writes lock the book row before checking active loans.
- `src/index.php`: explicit route/POST-action allowlists, validation/error-to-HTTP mapping and view selection. No database passwords, SQL, or business rules here.
- `src/views/`: escaped PHP templates, layout/navigation, login, dashboard/catalog, library users, loan requests/history and administration. No direct database access in templates.
- `src/assets/`: CSS tokens/layout and JS navigation, confirmation and submission feedback. Core forms work without JS.

Las firmas públicas y campos de resultado del servicio están documentados en [library-service.md](library-service.md) y son el contrato de la interfaz actual. Las pruebas usan métodos públicos y endpoints HTTP, no aserciones directas sobre almacenamiento de la aplicación.

## Legacy data and additive evolution

Preserve `libros`, `usuarios`, `prestamos`, their identifiers and every existing row. No automatic deletion, email deduplication, password assignment or reinterpretation of past returns. Existing loans have no return marker and are treated as outstanding until staff records their return. A preflight must surface null/orphaned references, duplicate outstanding loans per book, duplicate emails and availability conflicts without printing personal data. Historical invalid rows remain visible to staff, never silently repaired.

Add separate tables rather than modifying legacy columns: `accounts` links at most one login to a library user, `loan_requests` stores pending/confirmed requests, `loan_returns` stores at most one return per legacy loan, `auth_attempts` backs throttling. Account names are unique login identifiers independent of duplicate legacy email addresses. Existing library users have no login until an administrator provisions one. No public signup or invented password.

Migration command defaults to read-only preflight; an explicit apply flag prepares new tables. It does not run on the actual database during implementation. MySQL DDL is not transactionally reversible: preflight must run before DDL and partial application must be rerunnable. An isolated fixture database, not the existing Docker volume, is the integration target. Real migration requires a verified backup and operator authorization after preflight.

## Roles and workflows

| Capability | Reader | Librarian | Administrator |
|---|---|---|---|
| Browse/search books | yes | yes | yes |
| View own requests/loans | yes | yes | yes |
| Request a book for self | yes | yes | yes |
| View all library users/loans | no | yes | yes |
| Create/edit books and library users | no | yes | yes |
| Confirm requests and record returns | no | yes | yes |
| Provision/deactivate accounts and assign roles | no | no | yes |

Loan requests do not reserve a book. Confirmation serializes on the book row and refuses any already-outstanding loan, even if the legacy `disponible` flag is inconsistent. Confirmation inserts one loan, marks the book unavailable and marks the request confirmed in one transaction. Returns serialize on the same book and are recorded once; duplicate requests for a return cannot mutate anything. A returned book becomes available only if no other outstanding legacy loan remains. Readers cannot choose another user's identity in POST data. Staff operations must recheck permissions on every request.

There are no destructive delete operations in this iteration. Editing preserves identifiers and loan history. Existing inconsistent legacy rows are reported, not repaired automatically. Returns for invalid references must fail safely with an actionable staff-facing message.

## Security boundaries

Untrusted HTTP values enter through explicit validation. Sessions use strict mode, HttpOnly and SameSite cookies; Secure is required in HTTPS environments, with an explicit local HTTP development mode. Regenerate the session identifier after login, invalidate it on logout, enforce inactivity expiration and re-read active account/role from storage. CSRF protects every state-changing form, including logout. Passwords use password_hash/password_verify, no default accounts. Provision the first administrator through a CLI command with a secret supplied privately through standard input or environment, never a command-line argument recorded in shell history.

Escape all dynamic text with UTF-8 HTML encoding. Emit CSP without unsafe inline scripts, frame protection, no-sniff, no-store on authenticated pages and safe error responses. Do not reveal SQL, connection values, stack traces or account existence. Log error categories without credentials or personal data.

## UI and verification

Use a restrained academic library design, semantic navigation, one clear page heading, associated labels, visible keyboard focus, error summaries, empty/success/error states, responsive tables and layouts at 320/768/1024/1440px. Never present invented dashboard metrics. Client validation complements server validation. Login permissions and form visibility are consistent but backend remains authoritative.

Test first where deterministic: authentication/authorization and CSRF rejection; invalid input and unchanged state; search/create/update; reader request and ownership; staff confirmation/return; repeated and competing actions; preservation through migration and rerun. Integration runs against a disposable MySQL container with no legacy volume. Browser checks use an isolated Chrome profile and synthetic data. PHP syntax checks, JS syntax checks and public HTTP journeys complement service tests. Independent verification is required for migration, authentication and concurrency risk. No production readiness is claimed without deployment-specific hardening.
