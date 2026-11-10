<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/app/bootstrap.php';
require __DIR__ . '/library_fixture.php';

function httpFail(string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
function httpSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) { httpFail("{$message} expected=" . var_export($expected, true) . ' actual=' . var_export($actual, true)); } }
function httpContains(string $needle, string $haystack, string $message): void { if (!str_contains($haystack, $needle)) { httpFail($message . ' (body: ' . substr(strip_tags($haystack), 0, 300) . ')'); } }
function httpFixtureGuard(): void { libraryFixtureConnection()->close(); }
function httpFixtureReset(): void {
    $db = libraryFixtureConnection(); resetLibraryFixture($db);
    $reader = libraryAddUser($db, 'Reader', 'reader@example.test');
    $staff = libraryAddUser($db, 'Librarian', 'staff@example.test');
    $admin = libraryAddUser($db, 'Administrator', 'admin@example.test');
    libraryAddAccount($db, $reader, 'reader', 'reader'); libraryAddAccount($db, $staff, 'librarian', 'librarian'); libraryAddAccount($db, $admin, 'admin', 'admin');
    libraryAddBook($db, 'Arquitectura de software', 'Bass'); $db->close();
}
function httpFixtureCount(string $table): int { $db = libraryFixtureConnection(); $value = (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetch_row()[0]; $db->close(); return $value; }
function httpFixtureFirstId(string $table): int { $db = libraryFixtureConnection(); $value = (int) $db->query("SELECT MIN(id" . ($table === 'libros' ? '_libro' : ($table === 'usuarios' ? '_usuario' : ($table === 'prestamos' ? '_prestamo' : ''))) . ") FROM `{$table}`")->fetch_row()[0]; $db->close(); return $value; }
function httpFixtureLastId(string $table): int { $db = libraryFixtureConnection(); $field = match ($table) { 'libros' => 'id_libro', 'usuarios' => 'id_usuario', 'prestamos' => 'id_prestamo', default => 'id' }; $value = (int) $db->query("SELECT MAX(`{$field}`) FROM `{$table}`")->fetch_row()[0]; $db->close(); return $value; }
function httpStartServer(bool $configured = true): array {
    $address = '127.0.0.1:' . random_int(20000, 30000); $runtime = __DIR__ . '/../runtime'; if (!is_dir($runtime)) { mkdir($runtime, 0700, true); }
    $sessions = $runtime . '/http-sessions'; if (!is_dir($sessions)) { mkdir($sessions, 0700, true); }
    $command = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' -t src src/router.php';
    $env = array_merge($_ENV, ['DB_HOST'=>$configured ? '127.0.0.1' : '', 'DB_PORT'=>$configured ? '32768' : '', 'DB_NAME'=>$configured ? 'library_fixture' : '', 'DB_USER'=>$configured ? 'root' : '', 'DB_PASSWORD'=>$configured ? 'library_test_only_01a1c355' : '', 'APP_ENV'=>'local','SESSION_SAVE_PATH'=>$sessions]);
    $pipes = []; $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) { httpFail('Could not start PHP server.'); } fclose($pipes[0]);
    for ($i = 0; $i < 30; $i++) { usleep(100000); $probe = @file_get_contents("http://{$address}/index.php?page=login"); if ($probe !== false || !$configured) { return [$process, $pipes, "http://{$address}"]; } }
    httpFail('PHP test server did not start.');
}
function httpStopServer(array $server): void { [$process, $pipes] = $server; foreach ([1,2] as $index) { if (is_resource($pipes[$index])) { fclose($pipes[$index]); } } proc_terminate($process); proc_close($process); }
function httpClient(array $server): object { return new HttpFixtureClient($server[2]); }
function httpLogin(array $server, string $username): HttpFixtureClient { $client = httpClient($server); $page = $client->request('GET', '/index.php?page=login'); $response = $client->request('POST', '/index.php', ['action'=>'login','csrf'=>httpCsrf($page['body']),'username'=>$username,'password'=>'library test password 123']); httpSame(303, $response['status'], "{$username} login succeeds."); return $client; }
function httpCsrf(string $html): string { if (preg_match('/name="csrf"\s+value="([a-f0-9]{64})"/', $html, $matches) !== 1) { httpFail('No CSRF token found in response body of ' . strlen($html) . ' bytes.'); } return $matches[1]; }
final class HttpFixtureClient {
    private string $jar;
    public function __construct(private readonly string $base) { $this->jar = tempnam(__DIR__ . '/../runtime', 'cookie-') ?: throw new RuntimeException('Could not create cookie jar.'); }
    /** @return array{status:int,headers:string,body:string} */
    public function request(string $method, string $path, ?array $form = null): array {
        $command = 'curl -sS -i -X ' . escapeshellarg($method) . ' -b ' . escapeshellarg($this->jar) . ' -c ' . escapeshellarg($this->jar);
        if ($form !== null) { $command .= ' --data ' . escapeshellarg(http_build_query($form)); }
        $command .= ' ' . escapeshellarg($this->base . $path);
        $output = shell_exec($command); if (!is_string($output)) { httpFail('HTTP client failed.'); }
        $parts = preg_split("/\r?\n\r?\n/", $output, 2); $headers = $parts[0] ?? ''; $body = $parts[1] ?? '';
        preg_match('#HTTP/\S+\s+(\d+)#', $headers, $match); return ['status'=>(int)($match[1] ?? 0),'headers'=>$headers,'body'=>$body];
    }
}
