# Verification record

The academic application was checked against a disposable MySQL fixture, synthetic accounts and an isolated Chrome profile. No migration was applied to the existing classroom database or its Docker volume. This is not a production-readiness or full WCAG certification.

## Functional regression

The final sequential regression completed with exit `0`:

```sh
export DB_HOST=127.0.0.1 DB_PORT=32768 DB_NAME=library_fixture
export DB_USER=root DB_PASSWORD=library_test_only_01a1c355
php tests/migration_test.php
php tests/auth_test.php
php tests/library_test.php
php tests/http_test.php
php tests/usuario_test.php
```

The password above is a synthetic test-container credential, not an application default. Fixture helpers refuse other targets before setup/cleanup. The HTTP registration test replaces the old monolith's class-extraction test; registration remains covered through the current public interface.

| Area | Observed checks |
|---|---|
| Migration | Original schema/column order, aggregate preflight, preservation, rerun, malformed-schema refusal before writes |
| Authentication | Hash/password validation, session regeneration and expiry/CSRF persistence, generic login errors, role/deactivation checks, shared throttle, first-admin rollback/concurrency |
| Library service | Validated UTF-8, create/edit/search, reader ownership, request without reservation, atomic confirmation/return, repeated actions, same and distinct request races, duplicate legacy loans and orphan visibility |
| HTTP | Allowlists, CSRF before mutation, authorization before invalid-form correction, safe errors/escaping, secret-free field retention, PRG, role journeys and registration |
| Syntax | PHP lint across application/CLI/tests; Node syntax checks for UI JS and browser script; Git whitespace check |

## Fixture guard correction before publication

The prepublication check found that the migration fixture originally validated only host/database, not the port. It now requires exactly `127.0.0.1:32768/library_fixture` before connecting. `php tests/fixture_guard_test.php` first failed against the old helper (an unauthorized connection reached a synthetic TCP sentinel, never MySQL); after the correction it passes with exact exit/stdout/stderr assertions and zero connections for wrong or missing port and invalid host/database.

The original and corrected migration suite were also run sequentially on an owned disposable MySQL instance at the allowed target: both exited `0` with identical stdout/stderr. The other four integration suites, PHP/JS syntax, whitespace and standalone `docker-compose --env-file /dev/null config --quiet` checks passed after the correction. This later standalone Compose success does not change the historical plugin-command failure below. Secret scanning found no leaks; no coverage percentage or SonarQube approval was measured. No real database was used.

The independent prepublication follow-up closed the fixture-guard and public-artifact privacy findings. A focused four-lens native review of the guard/test change was approved and acknowledged; this does not retroactively approve earlier application units. Its host/database test-coverage advisory was non-blocking and remains a follow-up, not a claim of exhaustive guard coverage.

## Browser runtime

A new run explicitly authorized after a cookie-sharing harness failure completed with exit `0` for each synthetic role. `tests/browser_smoke.mjs` creates a separate incognito context for each role; creating a tab alone would reuse login cookies.

Observed in Chrome:

- Login, labelled navigation and catalog rendering.
- No document-level horizontal overflow at 320, 768, 1024 and 1440 px; table regions may scroll independently.
- Mobile navigation with JavaScript and without JavaScript.
- Reader request, librarian confirmation and return through rendered forms.
- Administrator access to account-provisioning controls.
- No uncaught JavaScript errors or failed network requests in those journeys.

Screenshots are generated under ignored `tests/artifacts/browser/`. They were inspected during the run, not committed as runtime output. The browser script requires a dedicated localhost Chrome CDP endpoint on port `9333`, a prepared synthetic HTTP fixture and `BROWSER_APP_ORIGIN`. It must never be pointed at a real user profile or a production origin.

## Apache and containers

The updated image built successfully. A temporary Apache container used a read-only project mount and no real database credentials/volume. Observed:

| Request | Status |
|---|---|
| `/index.php?page=login` without DB configuration | `503`, safe application error |
| `/app/Auth.php`, `/views/app.php`, `/router.php` | `403` |
| `/assets/app.css`, `/assets/app.js` | `200` |
| `/unknown` | `404` |

The original `404` document-root issue and subsequent self-rewrite `500` were reproduced and corrected. The optional Compose configuration check could not run in this host: `docker compose --env-file /dev/null config --quiet` was rejected with `unknown flag: --env-file`. It was not retried and no plugin was installed. The actual Apache image/routing checks above are separate passing evidence, not a Compose validation. The real Compose stack was not started against `mysql_data_final`. Runtime database credentials are explicit; production still requires HTTPS, least-privileged accounts, backup/restore procedures and deployment-specific validation.

## UML and documentation

The two Mermaid sources are embedded unchanged in the student report. PNGs were rendered successfully using Mermaid CLI `12.2.0` already available in the local cache, in offline/no-install mode and with a separate Chrome profile. `schema.sql` is a documentary projection of the seven `Schema` DDL definitions, not the supported operator migration interface.

## Failed, unavailable and accepted proof

- **Independent HTTP verification:** the subagent failed without a verdict. It was not retried. The user explicitly accepted continuing; functional HTTP/browser checks do not count as that independent verdict.
- **Exact independent dashboard/staff-global scope proof:** the extra harness incorrectly shared PHP session state; its scope assertions were discarded. The user accepted this specific missing proof. Basic reader isolation passed the public service suite; no privacy defect was inferred from the invalid harness.
- **Native review:** assessment could not resolve the then-untracked inventory. The migration review START returned `native-start-retained-selection-candidate-mismatch` without creating a lineage. The user left only that commit unreviewed explicitly. Later unavailable assessments did not produce native approval or a reviewDue continuation. The final documentation-only range was assessed successfully as `medium` with `reviewDue=false`; this is not native approval of the earlier implementation units. No review switch was changed and no authority was invented.
- **Historical token identity:** a formatter changed significant tokens without retaining an original copy. Visible defects were corrected, and the user authorized functional validation as a new candidate. That candidate passed; no historical source-equivalence claim is made.
- **Earlier failing checks:** migration/schema/preflight, session-expiry CSRF, NUL/UTF-8 validation, HTTP invalid-form authorization, no-JS mobile navigation and Apache configuration failures were recorded before their corrections. They are not retroactively reported as initial passes.
- **Not exercised:** real-data migration, actual production deployment, complete accessibility audit, production load, password recovery and every possible UI field/error combination.

## Code boundaries

| Unit | Public source |
|---|---|
| Architecture and preservation plan | `docs/architecture.md` |
| Additive migration | `src/app/Schema.php`, `bin/migrate.php` |
| Authentication and roles | `src/app/Auth.php`, `src/app/Session.php` |
| Catalog and loan workflow | `src/app/LibraryService.php` |
| HTTP interface and container setup | `src/app/Web.php`, `src/views/`, `Dockerfile` |

This public repository starts from a clean application snapshot. Internal task logs and the original local development history are deliberately not published; the source paths above, not private commit identifiers, identify each unit. During the original implementation no production deployment or real-data mutation was performed. No PR, merge or version tag is implied by repository publication. Large PHP view branches remain a maintainability limitation.
