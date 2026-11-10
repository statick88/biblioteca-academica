<?php
declare(strict_types=1);

// Exercise the actual destructive-suite entry point against a TCP sentinel, never MySQL.
function probe(array $overrides): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        throw new RuntimeException('Cannot create isolated TCP sentinel.');
    }
    $address = stream_socket_get_name($server, false);
    $port = substr($address, strrpos($address, ':') + 1);
    if ($port === '32768') {
        fclose($server);
        throw new RuntimeException('Sentinel must not use the allowed fixture port.');
    }
    $environment = array_replace([
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port, 'DB_NAME' => 'library_fixture',
        'DB_USER' => 'fixture_probe', 'DB_PASSWORD' => 'synthetic_probe_only',
    ], $overrides);
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/migration_test.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, dirname(__DIR__), $environment
    );
    if (!is_resource($process)) {
        fclose($server);
        throw new RuntimeException('Cannot start fixture entry point.');
    }
    fclose($pipes[0]);
    $connection = @stream_socket_accept($server, 0.3);
    $connected = is_resource($connection);
    if ($connected) {
        fclose($connection);
    }
    fclose($server);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr, $connected];
}

$expectedError = "FAIL: Refusing destructive fixture setup outside 127.0.0.1:32768/library_fixture.\n";
foreach ([
    'wrong port' => [],
    'missing port' => ['DB_PORT' => ''],
    'wrong host' => ['DB_HOST' => 'localhost'],
    'wrong database' => ['DB_NAME' => 'not_the_fixture'],
] as $name => $overrides) {
    [$exit, $stdout, $stderr, $connected] = probe($overrides);
    if ($exit !== 1 || $stdout !== '' || $stderr !== $expectedError || $connected) {
        fwrite(STDERR, "FAIL: $name must exit 1 with exact refusal, empty stdout, and zero database connections (exit=$exit, connected=" . ($connected ? 'yes' : 'no') . ").\n");
        exit(1);
    }
}
echo "OK: fixture rejects wrong/missing port and retains host/database guards before connection.\n";
