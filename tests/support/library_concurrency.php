<?php
declare(strict_types=1);

use Library\LibraryService;

require dirname(__DIR__, 2) . '/src/app/bootstrap.php';
require __DIR__ . '/library_fixture.php';

if ($argc !== 5 || !in_array($argv[1], ['confirm', 'return'], true)) {
    fwrite(STDERR, "Invalid library concurrency invocation.\n");
    exit(64);
}

[$script, $operation, $id, $username, $sessionName] = $argv;
try {
    $database = libraryFixtureConnection();
    $service = new LibraryService($database, libraryAuth($database, 'race-' . $sessionName, $username));
    if ($operation === 'confirm') {
        $service->confirmRequest((int) $id);
    } else {
        $service->returnLoan((int) $id);
    }
    echo "applied\n";
    exit(0);
} catch (Library\ValidationException) {
    echo "refused\n";
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . "\n");
    exit(1);
}
