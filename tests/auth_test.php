<?php
declare(strict_types=1);

use Library\Auth;
use Library\AuthorizationException;
use Library\Session;
use Library\ValidationException;

require dirname(__DIR__) . '/src/app/bootstrap.php';
require __DIR__ . '/support/auth_fixture.php';

function authFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function authSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        authFail($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function authThrows(string $class, Closure $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        authFail($message . ' (got ' . $error::class . ')');
    }
    authFail($message . ' (did not throw)');
}

function authCli(array $arguments, string $stdin = ''): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/create-admin.php');
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $environment = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
        $environment[$name] = (string) getenv($name);
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) {
        authFail('Could not start create-admin CLI.');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$db = authFixtureConnection();
resetAuthFixture($db);
startAuthTestSession('main');
$now = new DateTimeImmutable('2025-01-01 12:00:00');
$clock = static function () use (&$now): DateTimeImmutable { return $now; };
$session = new Session(900, false, $clock);
$auth = new Auth($db, $session, $clock, 2, 300);
$adminUser = addFixtureUser($db, 'Admin', 'admin@example.test');
$readerUser = addFixtureUser($db, 'Reader', 'reader@example.test');
$librarianUser = addFixtureUser($db, 'Librarian', 'librarian@example.test');

// Bootstrap is intentionally outside the Auth admin API; seed only the first synthetic admin.
$db->query("INSERT INTO accounts (user_id, username, password_hash, role, active) VALUES ({$adminUser}, 'admin', '" . $db->real_escape_string(password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 12])) . "', 'admin', 1)");
$adminId = (int) $db->insert_id;
$db->query("INSERT INTO accounts (user_id, username, password_hash, role, active) VALUES ({$readerUser}, 'reader', '" . $db->real_escape_string(password_hash('reader password 123', PASSWORD_BCRYPT, ['cost' => 12])) . "', 'reader', 1)");
$readerId = (int) $db->insert_id;
$db->query("INSERT INTO accounts (user_id, username, password_hash, role, active) VALUES ({$librarianUser}, 'librarian', '" . $db->real_escape_string(password_hash('librarian password 123', PASSWORD_BCRYPT, ['cost' => 12])) . "', 'librarian', 1)");
$librarianId = (int) $db->insert_id;

$before = session_id();
$result = $auth->login(' ADMIN ', 'correct horse battery staple', '127.0.0.1');
authSame(true, $result['ok'], 'Valid normalized login succeeds.');
authSame('admin', $result['account']['role'], 'Login exposes only the safe account role.');
authSame(false, array_key_exists('password_hash', $result['account']), 'Safe account result excludes the password hash.');
authSame(false, array_key_exists('password', $result['account']), 'Safe account result excludes plaintext passwords.');
authSame(false, hash_equals($before, session_id()), 'Login rotates the session identifier.');
authSame($adminId, $auth->requireAccount()['id'], 'Current session resolves the server account identity.');
authSame($adminId, $auth->requireRole('admin')['id'], 'Admin role is allowed.');
authThrows(AuthorizationException::class, fn() => $auth->requireRole('reader'), 'Admin is not misrepresented as a reader role.');

$token = $session->csrfToken();
authSame(true, $session->verifyCsrf($token), 'CSRF token verifies with constant-time-compatible helper contract.');
authSame(false, $session->verifyCsrf('wrong'), 'Invalid CSRF token is refused.');
authSame(false, $session->verifyCsrf(null), 'Absent CSRF token is refused.');

// Expiry must restart an anonymous persisted session rather than leaving $_SESSION unbound.
$expiredToken = $token;
$expiredSessionId = session_id();
$now = $now->modify('+901 seconds');
$freshToken = $session->csrfToken();
authSame(null, $session->accountId(), 'Idle expiry removes the former authenticated account.');
authSame(false, $session->verifyCsrf($expiredToken), 'Idle expiry rejects the old CSRF token.');
authSame(true, $session->verifyCsrf($freshToken), 'Fresh CSRF token verifies immediately after idle expiry.');
authSame(false, hash_equals($expiredSessionId, session_id()), 'Idle expiry rotates to a fresh anonymous session identifier.');
session_write_close();
$reopenedSession = new Session(900, false, $clock);
authSame(true, $reopenedSession->verifyCsrf($freshToken), 'Fresh CSRF token persists across a real session close and reopen.');
$auth = new Auth($db, $reopenedSession, $clock, 2, 300);
authSame(true, $auth->login('admin', 'correct horse battery staple', '127.0.0.1')['ok'], 'Fresh CSRF session can complete a valid login.');

$auth->logout();
authSame(null, $auth->currentAccount(), 'Logout invalidates the authenticated session.');
$wrong = $auth->login('missing', 'not the password', '127.0.0.1');
$badPassword = $auth->login('reader', 'not the password', '127.0.0.1');
authSame($wrong, $badPassword, 'Unknown user and wrong password have the same generic result.');
authSame(['ok' => false, 'account' => null], $wrong, 'Failed login has a stable safe response.');
$inactive = $auth->login('reader', 'reader password 123', '127.0.0.2');
authSame(true, $inactive['ok'], 'Active reader login succeeds before deactivation.');
$auth->logout();
$db->query("UPDATE accounts SET active = 0 WHERE id = {$readerId}");
authSame(['ok' => false, 'account' => null], $auth->login('reader', 'reader password 123', '127.0.0.2'), 'Inactive login returns the same generic failure.');
$db->query("UPDATE accounts SET active = 1 WHERE id = {$readerId}");

// Login limit is database backed, bounded and tested with a controlled clock rather than sleeps.
$auth->login('reader', 'bad', '127.0.0.9');
$auth->login('reader', 'bad', '127.0.0.9');
authSame(['ok' => false, 'account' => null], $auth->login('reader', 'reader password 123', '127.0.0.9'), 'A bounded identifier/IP window throttles even a valid credential.');
authSame(2, authScalar($db, "SELECT attempt_count FROM auth_attempts WHERE ip_hash = '" . hash('sha256', '127.0.0.9') . "'"), 'Throttle counter is atomically bounded at the configured maximum.');
$now = $now->modify('+301 seconds');
authSame(true, $auth->login('reader', 'reader password 123', '127.0.0.9')['ok'], 'A new controlled rate-limit window permits valid login.');
$auth->logout();
authThrows(ValidationException::class, fn() => $auth->login('reader', 'reader password 123', 'untrusted forwarded header'), 'Invalid server-supplied IP is rejected.');

$auth->login('reader', 'reader password 123', '127.0.0.1');
authSame($readerId, $auth->requireRole('reader')['id'], 'Reader role is allowed for its own identity.');
authThrows(AuthorizationException::class, fn() => $auth->requireRole('librarian', 'admin'), 'Reader cannot obtain staff role through arguments.');
authSame($readerUser, $auth->currentAccount()['user_id'], 'Reader gets only own linked library identity.');
$auth->logout();
$auth->login('librarian', 'librarian password 123', '127.0.0.1');
authSame($librarianId, $auth->requireRole('librarian', 'admin')['id'], 'Librarian is allowed staff role.');
$auth->logout();

$auth->login('admin', 'correct horse battery staple', '127.0.0.1');
$newUser = addFixtureUser($db, 'New Reader', 'new@example.test');
$created = $auth->createAccount($newUser, 'New.Reader', 'new reader password 123', 'reader');
authSame('new.reader', $created['username'], 'Provisioning uses the same normalized username as login.');
authSame('reader', $created['role'], 'Provisioning retains the validated role.');
authThrows(ValidationException::class, fn() => $auth->createAccount($newUser, 'new.reader', 'another password 123', 'reader'), 'Duplicate account is rejected safely.');
authSame(1, authScalar($db, "SELECT COUNT(*) FROM accounts WHERE user_id = {$newUser}"), 'Duplicate provision denial makes no extra account write.');
authThrows(ValidationException::class, fn() => Auth::assertPassword(str_repeat('a', 11)), 'Eleven-byte passwords are refused.');
Auth::assertPassword(str_repeat('a', 12));
Auth::assertPassword(str_repeat('a', 72));
authThrows(ValidationException::class, fn() => Auth::assertPassword(str_repeat('a', 73)), 'Passwords over bcrypt-safe byte bound are refused.');
foreach (["\0valid password", "valid\0 password", "valid password\0"] as $nulPassword) {
    authThrows(ValidationException::class, fn() => Auth::assertPassword($nulPassword), 'NUL passwords are rejected before hashing.');
    authThrows(ValidationException::class, fn() => $auth->createAccount($newUser, 'nul-password', $nulPassword, 'reader'), 'NUL provisioning is rejected as validation.');
}
authSame(1, authScalar($db, "SELECT COUNT(*) FROM accounts WHERE user_id = {$newUser}"), 'NUL provisioning denial makes no account write.');
$nulLogin = $auth->login('admin', "valid password\0suffix", '127.0.0.77');
authSame(['ok' => false, 'account' => null], $nulLogin, 'NUL login uses the generic failed-login result.');
authSame(1, authScalar($db, "SELECT attempt_count FROM auth_attempts WHERE ip_hash = '" . hash('sha256', '127.0.0.77') . "'"), 'NUL login consumes the normal bounded rate-limit attempt.');
authThrows(ValidationException::class, fn() => $auth->createAccount($newUser, 'bad-role', 'safe password 123', 'owner'), 'Invalid role is refused.');
$accounts = $auth->listAccounts();
authSame(false, array_key_exists('password_hash', $accounts[0]), 'Admin listing never exposes hashes.');

$auth->setAccountActive($readerId, false);
$auth->logout();
$session->login($readerId);
authSame(null, $auth->currentAccount(), 'Deactivation invalidates an existing account session when it is checked again.');
startAuthTestSession('last-admin');
$lastAdminAuth = new Auth($db, new Session(900, false, $clock), $clock, 2, 300);
$lastAdminAuth->login('admin', 'correct horse battery staple', '127.0.0.1');
authThrows(ValidationException::class, fn() => $lastAdminAuth->setAccountActive($adminId, false), 'The last active admin cannot be disabled.');
authSame(1, authScalar($db, "SELECT active FROM accounts WHERE id = {$adminId}"), 'Last-admin denial makes no write.');

// CLI is first-admin-only and validates command interface before connecting/writing.
resetAuthFixture($db);
[$exit, $stdout, $stderr] = authCli(['--help']);
authSame(0, $exit, 'CLI help exits successfully.');
authSame(1, authScalar($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \'accounts\''), 'CLI help writes nothing.');
[$exit, $stdout, $stderr] = authCli(['--unknown']);
authSame(64, $exit, 'CLI unknown option exits with usage status.');
authSame('', $stdout, 'CLI unknown option has no stdout.');
authSame("Error: unknown option --unknown\n", $stderr, 'CLI unknown option has stable stderr.');
$db->query('DROP TABLE accounts');
[$exit, $stdout, $stderr] = authCli(['--username', 'rootadmin', '--name', 'Root Admin', '--email', 'root@example.test'], "bootstrap password 123\n");
authSame(1, $exit, 'CLI refuses bootstrap when required tables are absent.');
resetAuthFixture($db);
$db->query("ALTER TABLE accounts ADD CONSTRAINT reject_rollback_admin CHECK (username <> 'rollbackadmin')");
[$exit, $stdout, $stderr] = authCli(['--username', 'rollbackadmin', '--name', 'Rolled Back', '--email', 'rollback@example.test'], "bootstrap password 123\n");
authSame(1, $exit, 'CLI reports a transactional bootstrap failure.');
authSame(0, authScalar($db, "SELECT COUNT(*) FROM usuarios WHERE nombre = 'Rolled Back'"), 'Failed CLI bootstrap rolls back its newly created legacy user.');
authSame(0, authScalar($db, 'SELECT COUNT(*) FROM accounts'), 'Failed CLI bootstrap rolls back its account insert.');
resetAuthFixture($db);
[$exit, $stdout, $stderr] = authCli(['--username', 'nuladmin', '--name', 'Nul Admin', '--email', 'nul@example.test'], "valid password\0suffix\n");
authSame(64, $exit, 'CLI NUL password is rejected as input validation.');
authSame('', $stdout, 'CLI NUL password emits no stdout.');
authSame("Error: invalid bootstrap input\n", $stderr, 'CLI NUL password has stable validation stderr.');
authSame(0, authScalar($db, 'SELECT COUNT(*) FROM accounts'), 'CLI NUL password makes no account write.');
authSame(0, authScalar($db, "SELECT COUNT(*) FROM usuarios WHERE nombre = 'Nul Admin'"), 'CLI NUL password makes no legacy-user write.');
resetAuthFixture($db);
[$exit, $stdout, $stderr] = authCli(['--username', 'rootadmin', '--name', 'Root Admin', '--email', 'root@example.test'], "bootstrap password 123\n");
authSame(0, $exit, 'CLI creates exactly the first administrator.');
authSame(1, authScalar($db, "SELECT COUNT(*) FROM accounts WHERE username = 'rootadmin' AND role = 'admin'"), 'CLI inserts first active admin.');
authSame(1, authScalar($db, "SELECT COUNT(*) FROM usuarios WHERE nombre = 'Root Admin'"), 'CLI atomically creates the requested legacy user.');
[$exit, $stdout, $stderr] = authCli(['--username', 'second', '--user-id', '1'], "second password 123\n");
authSame(1, $exit, 'CLI refuses any second bootstrap.');
authSame(1, authScalar($db, 'SELECT COUNT(*) FROM accounts'), 'Second bootstrap refusal makes no account write.');

resetAuthFixture($db);
$existingUser = addFixtureUser($db, 'Existing', 'existing@example.test');
[$exit, $stdout, $stderr] = authCli(['--username', 'linkedadmin', '--user-id', (string) $existingUser], "linked password 123\n");
authSame(0, $exit, 'CLI can link the sole first admin to an existing user.');
authSame(1, authScalar($db, "SELECT COUNT(*) FROM accounts WHERE user_id = {$existingUser} AND role = 'admin'"), 'CLI leaves existing legacy user untouched and creates account link.');

echo "OK auth_test\n";
