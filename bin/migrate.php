<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/app/bootstrap.php';

use Library\Database;
use Library\Schema;

const HELP = "Usage: php bin/migrate.php [--apply]\n\nOptions:\n  --apply  Create missing legacy and additive tables.\n  --help   Show this help message.\n";

$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    fwrite(STDOUT, HELP);
    exit(0);
}

if ($arguments !== [] && $arguments !== ['--apply']) {
    $option = $arguments[0] ?? '';
    fwrite(STDERR, "Error: unknown option {$option}\n");
    exit(64);
}

try {
    $schema = new Schema(Database::connect());
    if ($arguments === ['--apply']) {
        $result = ['mode' => 'apply', 'result' => $schema->apply()];
    } else {
        $result = ['mode' => 'preflight', 'report' => $schema->preflight()];
    }

    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable) {
    // Operators receive a safe category only; driver messages can contain hosts
    // or connection details and are not suitable for a CLI transcript.
    fwrite(STDERR, "Error: migration command failed.\n");
    exit(1);
}
