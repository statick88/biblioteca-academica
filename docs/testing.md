# Pruebas locales

Las pruebas de integración son destructivas para su fixture: pueden reiniciar o borrar sus datos. Nunca las apunte a producción, a una base de aula ni al volumen de Compose. Ejecute los comandos desde la raíz del repositorio con PHP CLI (`mysqli`, `mbstring`, sesiones y `proc_open`/`shell_exec` habilitados) y el ejecutable `curl` disponible.

## Fixture MySQL desechable

Use exactamente el destino protegido. La contraseña siguiente es una credencial sintética **solo de pruebas**, no una cuenta predeterminada de la aplicación:

```sh
docker run --rm -d --name library-fixture-test \
  -e MYSQL_ROOT_PASSWORD=library_test_only_01a1c355 \
  -e MYSQL_DATABASE=library_fixture \
  -p 127.0.0.1:32768:3306 mysql:8.0 \
  --default-authentication-plugin=mysql_native_password
```

Espere a que MySQL acepte conexiones. Antes de ejecutar suites, fije la guarda explícita:

```sh
export DB_HOST=127.0.0.1 DB_PORT=32768 DB_NAME=library_fixture
export DB_USER=root DB_PASSWORD=library_test_only_01a1c355
```

Primero puede comprobar la guarda sin MySQL: `php tests/fixture_guard_test.php` usa un servidor TCP sintético y exige salida `1`, stdout vacío, rechazo exacto en stderr y cero conexiones para destinos no autorizados. La guarda de migración rechaza con `FAIL: Refusing destructive fixture setup outside 127.0.0.1:32768/library_fixture.` antes de conectar.

Ejecute esa prueba, las cinco suites de integración y el lint PHP:

```sh
php tests/fixture_guard_test.php
php tests/migration_test.php
php tests/auth_test.php
php tests/library_test.php
php tests/http_test.php
php tests/usuario_test.php
find src bin tests -type f -name '*.php' -not -path 'tests/runtime/*' -print0 | xargs -0 -n1 php -l
node --check src/assets/app.js
node --check tests/browser_smoke.mjs
```

Al terminar, detenga únicamente el contenedor propio de prueba:

```sh
docker stop library-fixture-test
```

`--rm` elimina ese contenedor al detenerse; no limpie volúmenes o contenedores ajenos. Si el puerto 32768 está ocupado, no redirija las suites a otra base: sus guardas rechazan destinos distintos.

## Comprobación de navegador (opcional)

`tests/browser_smoke.mjs` usa `fetch` y WebSocket nativos de Node (se comprobó con Node 26.13). Necesita Chrome headless con CDP en `9333` y perfil propio bajo `tests/runtime`. La guarda admite únicamente `BROWSER_APP_ORIGIN=http://127.0.0.1:PUERTO`; no admite el nombre `localhost` ni un origen remoto. Cada rol usa un contexto de incógnito separado.

Después de las suites, prepare nuevamente los datos sintéticos: el siguiente comando **reinicia el fixture**, no instala usuarios de demostración en la aplicación. Mantenga las variables de prueba anteriores:

```sh
php -r 'require "tests/support/http_fixture.php"; httpFixtureGuard(); httpFixtureReset();'
mkdir -p tests/runtime/browser-sessions tests/runtime/chrome-profile
APP_ENV=local SESSION_SAVE_PATH="$PWD/tests/runtime/browser-sessions" php -S 127.0.0.1:8080 -t src src/router.php
```

Deje ese servidor en su terminal. En otra terminal, desde la misma raíz, inicie un Chrome dedicado; no use su perfil personal:

```sh
# Linux: sustituya el ejecutable si su distribución usa otro nombre.
google-chrome --headless=new --disable-background-networking --no-first-run \
  --remote-debugging-address=127.0.0.1 --remote-debugging-port=9333 \
  --user-data-dir="$PWD/tests/runtime/chrome-profile" about:blank
# En macOS use como ejecutable:
# '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
```

Con ambos procesos activos, una tercera terminal puede ejecutar la secuencia. El lector crea la solicitud que el bibliotecario confirma y devuelve; no cambie ese orden ni reinicie el fixture entre roles:

```sh
BROWSER_APP_ORIGIN=http://127.0.0.1:8080 BROWSER_TEST_USER=reader node tests/browser_smoke.mjs
BROWSER_APP_ORIGIN=http://127.0.0.1:8080 BROWSER_TEST_USER=librarian node tests/browser_smoke.mjs
BROWSER_APP_ORIGIN=http://127.0.0.1:8080 BROWSER_TEST_USER=admin node tests/browser_smoke.mjs
```

Al terminar, detenga el servidor PHP y ese Chrome dedicado en sus terminales. Los perfiles, cookies y capturas quedan ignorados por Git; no los publique. Detenga también el contenedor propio de fixture cuando ya no lo necesite.

Esta jornada verifica el flujo funcional renderizado; no reemplaza la prueba independiente agotada de alcance exacto de dashboard/listados globales descrita en [verification.md](verification.md).

## Límites

La evidencia histórica incluye resultados observados, no cobertura medida ni una puerta Sonar. Las pruebas de navegador, contenedor y base real requieren servicios locales y no se ejecutan como parte de esta documentación. Consulte [verification.md](verification.md) antes de interpretar un resultado como preparación de producción.
