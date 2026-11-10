<?php
declare(strict_types=1);

/** Public HTTP boundary tests. Run only against the guarded synthetic fixture. */
require __DIR__ . '/support/http_fixture.php';

httpFixtureGuard();
$missingConfig = httpStartServer(false);
try {
    $missing = httpClient($missingConfig)->request('GET', '/index.php?page=login');
    httpSame(503, $missing['status'], 'Missing database configuration fails safely.');
    if (str_contains($missing['body'], 'DB_HOST')) { httpFail('Missing configuration error exposes internal detail.'); }
} finally { httpStopServer($missingConfig); }
httpFixtureReset();
$server = httpStartServer();
try {
    $anonymous = httpClient($server);
    httpSame(303, $anonymous->request('GET', '/index.php?page=dashboard')['status'], 'Protected dashboard redirects anonymous visitors.');
    httpSame(404, $anonymous->request('GET', '/index.php?page=unknown')['status'], 'Unknown page is not routed.');
    httpSame(405, $anonymous->request('PUT', '/index.php')['status'], 'Unsupported methods are rejected.');
    httpSame(403, $anonymous->request('GET', '/app/Auth.php')['status'], 'Direct application source is denied.');

    $login = $anonymous->request('GET', '/index.php?page=login');
    httpSame(200, $login['status'], 'Login page renders.');
    httpContains("Content-Security-Policy: default-src 'self'", $login['headers'], 'Login has a self-only CSP.');
    httpContains('Cache-Control: no-store', $login['headers'], 'Login response is not stored.');
    $csrf = httpCsrf($login['body']);
    $attempts = httpFixtureCount('auth_attempts');
    httpSame(403, $anonymous->request('POST', '/index.php', ['action' => 'login', 'username' => 'reader', 'password' => 'library test password 123'])['status'], 'Login without CSRF is rejected before Auth.');
    httpSame($attempts, httpFixtureCount('auth_attempts'), 'Invalid login CSRF creates no throttle write.');
    $failedLogin = $anonymous->request('POST', '/index.php', ['action' => 'login', 'csrf' => $csrf, 'username' => 'reader', 'password' => 'incorrect password']);
    httpSame(303, $failedLogin['status'], 'Valid CSRF login failure uses PRG.');
    httpContains('No fue posible iniciar sesión con esas credenciales.', $anonymous->request('GET', '/index.php?page=login&notice=No%20fue%20posible%20iniciar%20sesi%C3%B3n%20con%20esas%20credenciales.')['body'], 'Login failure remains generic.');
    $freshLogin = $anonymous->request('GET', '/index.php?page=login');
    httpSame(303, $anonymous->request('POST', '/index.php', ['action' => 'login', 'csrf' => httpCsrf($freshLogin['body']), 'username' => 'reader', 'password' => 'library test password 123'])['status'], 'Valid login uses PRG.');

    $reader = $anonymous;
    $books = $reader->request('GET', '/index.php?page=books');
    httpContains('Catálogo', $books['body'], 'Reader sees catalog.');
    $bookId = httpFixtureFirstId('libros');
    $readerCsrf = httpCsrf($books['body']);
    httpSame(303, $reader->request('POST', '/index.php', ['action' => 'request', 'csrf' => $readerCsrf, 'book_id' => (string) $bookId])['status'], 'Reader can request a catalog book.');
    $requestId = httpFixtureFirstId('loan_requests');
    $beforeLoans = httpFixtureCount('prestamos');
    httpSame(403, $reader->request('POST', '/index.php', ['action' => 'confirm', 'csrf' => $readerCsrf, 'request_id' => (string) $requestId])['status'], 'Reader cannot confirm loans.');
    httpSame($beforeLoans, httpFixtureCount('prestamos'), 'Denied confirmation changes no loan rows.');
    httpSame(403, $reader->request('POST', '/index.php', ['action' => 'create_user', 'csrf' => $readerCsrf, 'name' => 'No', 'email' => 'no@example.test'])['status'], 'Reader cannot manage users.');
    httpSame(403, $reader->request('POST', '/index.php', ['action' => 'create_account', 'csrf' => $readerCsrf, 'user_id' => '1', 'username' => 'blocked', 'password' => 'library test password 123', 'role' => 'reader'])['status'], 'Reader cannot administer accounts.');

    $readerUserRows = httpFixtureCount('usuarios');
    $readerAccountRows = httpFixtureCount('accounts');
    $invalidDeniedUser = $reader->request('POST', '/index.php', ['action' => 'create_user', 'csrf' => $readerCsrf, 'name' => '', 'email' => 'invalid']);
    httpSame(403, $invalidDeniedUser['status'], 'Reader is denied before invalid staff fields reach correction rendering.');
    $invalidDeniedAccount = $reader->request('POST', '/index.php', ['action' => 'create_account', 'csrf' => $readerCsrf, 'user_id' => '1', 'username' => '', 'password' => 'secret must never be echoed', 'role' => 'reader']);
    httpSame(403, $invalidDeniedAccount['status'], 'Reader is denied before invalid admin fields reach correction rendering.');
    httpSame($readerUserRows, httpFixtureCount('usuarios'), 'Denied invalid user form leaves users unchanged.');
    httpSame($readerAccountRows, httpFixtureCount('accounts'), 'Denied invalid account form leaves accounts unchanged.');
    if (str_contains($invalidDeniedAccount['body'], 'secret must never be echoed') || str_contains($invalidDeniedUser['body'], 'AuthorizationException')) {
        httpFail('Denied correction response exposed a password or internal exception.');
    }

    $staff = httpLogin($server, 'librarian');
    $staffBooks = $staff->request('GET', '/index.php?page=books');
    $staffCsrf = httpCsrf($staffBooks['body']);
    httpSame(303, $staff->request('POST', '/index.php', ['action' => 'create_book', 'csrf' => $staffCsrf, 'title' => '<b>Seguro</b>', 'author' => 'Autor'])['status'], 'Librarian can create books.');
    $hostileBook = httpFixtureLastId('libros');
    httpContains('&lt;b&gt;Seguro&lt;/b&gt;', $staff->request('GET', '/index.php?page=books')['body'], 'Stored book titles are escaped.');
    httpSame(303, $staff->request('POST', '/index.php', ['action' => 'edit_book', 'csrf' => $staffCsrf, 'book_id' => (string) $hostileBook, 'title' => 'Editado', 'author' => 'Autor'])['status'], 'Librarian can edit a book.');
    httpSame(303, $staff->request('POST', '/index.php', ['action' => 'create_user', 'csrf' => $staffCsrf, 'name' => 'Nueva persona', 'email' => 'nueva@example.test'])['status'], 'Librarian can create users.');
    httpSame(303, $staff->request('POST', '/index.php', ['action' => 'confirm', 'csrf' => $staffCsrf, 'request_id' => (string) $requestId])['status'], 'Librarian can confirm requests.');
    $loanId = httpFixtureFirstId('prestamos');
    httpSame(303, $staff->request('POST', '/index.php', ['action' => 'return', 'csrf' => $staffCsrf, 'loan_id' => (string) $loanId])['status'], 'Librarian can return loans.');

    $admin = httpLogin($server, 'admin');
    $accounts = $admin->request('GET', '/index.php?page=accounts');
    $adminCsrf = httpCsrf($accounts['body']);
    $newUserId = httpFixtureLastId('usuarios');
    httpSame(303, $admin->request('POST', '/index.php', ['action' => 'create_account', 'csrf' => $adminCsrf, 'user_id' => (string) $newUserId, 'username' => 'newreader', 'password' => 'library test password 123', 'role' => 'reader'])['status'], 'Admin can provision accounts.');
    $newAccount = httpFixtureLastId('accounts');
    httpSame(303, $admin->request('POST', '/index.php', ['action' => 'deactivate', 'csrf' => $adminCsrf, 'account_id' => (string) $newAccount])['status'], 'Admin can deactivate accounts.');
    $invalidBookRows = httpFixtureCount('libros');
    $invalidBook = $staff->request('POST', '/index.php', ['action' => 'create_book', 'csrf' => $staffCsrf, 'title' => 'Conservar este título', 'author' => '']);
    httpSame(422, $invalidBook['status'], 'Business validation renders a correction response.');
    httpContains('Conservar este título', $invalidBook['body'], 'Invalid form retains whitelisted non-secret text.');
    httpContains('role="alert"', $invalidBook['body'], 'Invalid form exposes an error summary.');
    httpSame($invalidBookRows, httpFixtureCount('libros'), 'Rejected invalid form leaves book rows unchanged.');
    httpSame(400, $admin->request('POST', '/index.php', ['action' => 'unknown', 'csrf' => $adminCsrf])['status'], 'Unknown action is rejected.');
    httpSame(400, $admin->request('POST', '/index.php', ['action' => 'create_book', 'csrf' => $adminCsrf, 'title' => ['array'], 'author' => 'x'])['status'], 'Array input is rejected safely.');
    httpSame(303, $admin->request('POST', '/index.php', ['action' => 'logout', 'csrf' => $adminCsrf])['status'], 'Logout uses POST and PRG.');
    echo "OK http_test\n";
} finally {
    httpStopServer($server);
    httpFixtureReset();
}
