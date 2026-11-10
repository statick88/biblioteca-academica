<?php
declare(strict_types=1);

use Library\Schema;

require dirname(__DIR__) . '/src/app/bootstrap.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function assertThrows(Closure $operation, string $message): void
{
    try {
        $operation();
    } catch (LogicException) {
        return;
    }
    fail($message);
}

require __DIR__ . '/support/migration_fixture.php';

function runCli(array $arguments): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/migrate.php');
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $environment = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
        $environment[$name] = (string) getenv($name);
    }
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) {
        fail('Could not start migration CLI.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$db = fixtureConnection();
resetFixture($db);

// Default, help, and rejected flags retain their exact public CLI contract and write nothing.
[$exit, $stdout, $stderr] = runCli([]);
assertSame(0, $exit, 'Default preflight exits successfully.');
assertSame("{\"mode\":\"preflight\",\"report\":{\"legacy_tables_present\":0,\"legacy_rows\":{\"libros\":0,\"usuarios\":0,\"prestamos\":0},\"null_references\":{\"prestamos.user_id\":0,\"prestamos.book_id\":0},\"orphaned_references\":{\"prestamos.user_id\":0,\"prestamos.book_id\":0},\"duplicate_outstanding_loans\":0,\"availability_mismatches\":0,\"duplicate_emails\":0}}\n", $stdout, 'Default CLI prints only aggregate preflight output.');
assertSame('', $stderr, 'Default CLI has no stderr output.');
assertSame(0, tableCount($db), 'Default preflight writes no tables.');

[$exit, $stdout, $stderr] = runCli(['--help']);
assertSame(0, $exit, 'Help exits successfully.');
assertSame("Usage: php bin/migrate.php [--apply]\n\nOptions:\n  --apply  Create missing legacy and additive tables.\n  --help   Show this help message.\n", $stdout, 'Help text is stable.');
assertSame('', $stderr, 'Help has no stderr output.');
assertSame(0, tableCount($db), 'Help performs zero writes.');

[$exit, $stdout, $stderr] = runCli(['--unexpected']);
assertSame(64, $exit, 'Unknown flag has usage exit status.');
assertSame('', $stdout, 'Unknown flag does not emit stdout.');
assertSame("Error: unknown option --unexpected\n", $stderr, 'Unknown flag error is stable and safe.');
assertSame(0, tableCount($db), 'Rejected flags perform zero writes.');

// An empty fixture is the one allowed missing-legacy state. Apply reproduces independent original definitions exactly.
[$exit, $stdout, $stderr] = runCli(['--apply']);
assertSame(0, $exit, 'Apply accepts an entirely empty fixture.');
assertSame("{\"mode\":\"apply\",\"result\":{\"created_legacy_tables\":[\"libros\",\"usuarios\",\"prestamos\"],\"created_additive_tables\":[\"accounts\",\"loan_requests\",\"loan_returns\",\"auth_attempts\"]}}\n", $stdout, 'Apply reports every table created from an empty fixture.');
assertSame('', $stderr, 'Apply has no stderr output.');
assertSame(7, tableCount($db), 'Apply creates exactly three original and four additive tables.');
assertSame([
    'id_libro' => ['type' => 'int', 'nullable' => 'NO', 'default' => null],
    'titulo' => ['type' => 'varchar(150)', 'nullable' => 'NO', 'default' => null],
    'autor' => ['type' => 'varchar(100)', 'nullable' => 'NO', 'default' => null],
    'disponible' => ['type' => 'tinyint(1)', 'nullable' => 'YES', 'default' => '1'],
], columnMetadata($db, 'libros'), 'Fresh libros matches authoritative legacy metadata.');
assertSame([
    'id_usuario' => ['type' => 'int', 'nullable' => 'NO', 'default' => null],
    'nombre' => ['type' => 'varchar(100)', 'nullable' => 'NO', 'default' => null],
    'correo' => ['type' => 'varchar(100)', 'nullable' => 'NO', 'default' => null],
], columnMetadata($db, 'usuarios'), 'Fresh usuarios matches authoritative legacy metadata.');
assertSame([
    'id_prestamo' => ['type' => 'int', 'nullable' => 'NO', 'default' => null],
    'id_libro' => ['type' => 'int', 'nullable' => 'YES', 'default' => null],
    'id_usuario' => ['type' => 'int', 'nullable' => 'YES', 'default' => null],
    'fecha_salida' => ['type' => 'date', 'nullable' => 'NO', 'default' => null],
], columnMetadata($db, 'prestamos'), 'Fresh prestamos matches authoritative legacy metadata.');
assertSame(2, scalar($db, "SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'prestamos' AND referenced_table_name IN ('usuarios', 'libros')"), 'Fresh prestamos retains both original foreign keys.');
assertSame(0, scalar($db, 'SELECT COUNT(*) FROM usuarios'), 'Apply creates no demo users.');
assertSame(['id', 'user_id', 'username', 'password_hash', 'role', 'active', 'created_at', 'updated_at'], columnNames($db, 'accounts'), 'Accounts exposes the documented Auth contract.');
assertSame(['id', 'user_id', 'book_id', 'status', 'loan_id', 'requested_at', 'confirmed_at', 'confirmed_by_account_id', 'created_at', 'updated_at'], columnNames($db, 'loan_requests'), 'Loan requests expose the documented workflow contract.');
assertSame(['loan_id', 'returned_at', 'returned_by_account_id', 'created_at'], columnNames($db, 'loan_returns'), 'Loan returns enforce one row per loan with actor and time.');
assertSame(['id', 'identifier_hash', 'ip_hash', 'window_started_at', 'attempt_count', 'updated_at'], columnNames($db, 'auth_attempts'), 'Auth attempts stores only hashed rate-limit identifiers.');

[$exit, $stdout, $stderr] = runCli(['--apply']);
assertSame(0, $exit, 'Apply is rerunnable after a complete migration.');
assertSame("{\"mode\":\"apply\",\"result\":{\"created_legacy_tables\":[],\"created_additive_tables\":[]}}\n", $stdout, 'Complete rerun reports no new tables.');
assertSame('', $stderr, 'Complete rerun has no stderr output.');

// Valid original legacy data, including historical invalid rows, is reported but never changed.
resetFixture($db);
createLegacyFixture($db, true);
$schema = new Schema($db);
$preflight = $schema->preflight();
assertSame(3, $preflight['legacy_tables_present'], 'Preflight accepts all original legacy tables.');
assertSame(['libros' => 2, 'usuarios' => 2, 'prestamos' => 3], $preflight['legacy_rows'], 'Preflight reports aggregate legacy rows only.');
assertSame(['prestamos.user_id' => 1, 'prestamos.book_id' => 1], $preflight['null_references'], 'Preflight counts null foreign-key references.');
assertSame(['prestamos.user_id' => 1, 'prestamos.book_id' => 0], $preflight['orphaned_references'], 'Preflight counts orphaned references.');
assertSame(1, $preflight['duplicate_outstanding_loans'], 'Preflight counts duplicate active loans.');
assertSame(1, $preflight['availability_mismatches'], 'Preflight counts availability mismatch.');
assertSame(1, $preflight['duplicate_emails'], 'Preflight counts duplicate emails without exposing them.');
assertSame(3, tableCount($db), 'Preflight does not create additive tables.');
$applied = $schema->apply();
assertSame([], $applied['created_legacy_tables'], 'Public apply preserves original legacy tables.');
assertSame(['accounts', 'loan_requests', 'loan_returns', 'auth_attempts'], $applied['created_additive_tables'], 'Public apply adds only additive tables.');
assertSame(2, scalar($db, 'SELECT COUNT(*) FROM libros'), 'Apply preserves book row count.');
assertSame(2, scalar($db, 'SELECT COUNT(*) FROM usuarios'), 'Apply preserves user row count.');
assertSame(3, scalar($db, 'SELECT COUNT(*) FROM prestamos'), 'Apply preserves loan row count.');
assertSame(7, scalar($db, 'SELECT id_libro FROM libros WHERE id_libro = 7'), 'Apply preserves book IDs.');
assertSame(11, scalar($db, 'SELECT id_usuario FROM usuarios WHERE id_usuario = 11'), 'Apply preserves user IDs.');
assertSame(21, scalar($db, 'SELECT id_prestamo FROM prestamos WHERE id_prestamo = 21'), 'Apply preserves loan IDs.');

// Partial legacy and malformed legacy are invalid states, never creation prompts.
resetFixture($db);
createIncompleteLegacySchema($db);
$schema = new Schema($db);
assertThrows(fn() => $schema->preflight(), 'Incomplete legacy preflight must reject instead of returning all-zero counts.');
assertSame(1, tableCount($db), 'Incomplete legacy preflight changes no state.');
assertThrows(fn() => $schema->apply(), 'Incomplete legacy public apply must preflight before DDL.');
assertSame(1, tableCount($db), 'Incomplete legacy apply changes no state.');
[$exit, $stdout, $stderr] = runCli(['--apply']);
assertSame(1, $exit, 'Incomplete legacy CLI apply fails.');
assertSame('', $stdout, 'Incomplete legacy CLI apply emits no stdout.');
assertSame("Error: migration command failed.\n", $stderr, 'Incomplete legacy CLI apply has safe exact stderr.');
assertSame(1, tableCount($db), 'Incomplete legacy CLI apply creates no missing tables.');

resetFixture($db);
createIdOnlyLegacySchema($db);
$schema = new Schema($db);
assertThrows(fn() => $schema->preflight(), 'ID-only legacy preflight must reject missing required columns.');
assertSame(3, tableCount($db), 'ID-only preflight changes no state.');
assertThrows(fn() => $schema->apply(), 'ID-only public apply must reject before DDL.');
assertSame(3, tableCount($db), 'ID-only apply creates no additive tables.');

// Existing additive tables are also validated before any DDL; malformed return table cannot be bypassed by apply.
resetFixture($db);
createMalformedLoanReturns($db);
$schema = new Schema($db);
assertThrows(fn() => $schema->preflight(), 'Malformed loan_returns preflight must fail.');
assertSame(4, tableCount($db), 'Malformed additive preflight changes no state.');
assertThrows(fn() => $schema->apply(), 'Malformed loan_returns public apply must reject before creating other additive tables.');
assertSame(4, tableCount($db), 'Malformed additive public apply creates no tables.');
[$exit, $stdout, $stderr] = runCli(['--apply']);
assertSame(1, $exit, 'Malformed additive CLI apply fails.');
assertSame('', $stdout, 'Malformed additive CLI apply emits no stdout.');
assertSame("Error: migration command failed.\n", $stderr, 'Malformed additive CLI apply has safe exact stderr.');
assertSame(4, tableCount($db), 'Malformed additive CLI apply creates no tables.');

resetFixture($db);
createMalformedAccounts($db);
$schema = new Schema($db);
assertThrows(fn() => $schema->preflight(), 'Malformed accounts preflight must fail.');
assertSame(4, tableCount($db), 'Malformed accounts preflight changes no state.');
assertThrows(fn() => $schema->apply(), 'Malformed accounts public apply must reject before DDL.');
assertSame(4, tableCount($db), 'Malformed accounts public apply creates no tables.');

// An already-valid partial additive migration remains supported and rerunnable.
resetFixture($db);
createOriginalLegacySchema($db);
$schema = new Schema($db);
$first = $schema->apply();
assertSame(['accounts', 'loan_requests', 'loan_returns', 'auth_attempts'], $first['created_additive_tables'], 'Fixture creates additive baseline.');
$db->query('DROP TABLE auth_attempts');
$partial = $schema->apply();
assertSame([], $partial['created_legacy_tables'], 'Partial additive rerun leaves legacy untouched.');
assertSame(['auth_attempts'], $partial['created_additive_tables'], 'Partial additive rerun creates only missing valid additive table.');

resetFixture($db);
echo "OK migration_test\n";
