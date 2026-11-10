<?php
declare(strict_types=1);

use Library\Database;
use Library\Schema;

const AUTH_FIXTURE_DATABASE = 'library_fixture';

function authFixtureConnection(): mysqli
{
    if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '32768' || getenv('DB_NAME') !== AUTH_FIXTURE_DATABASE) {
        throw new RuntimeException('Refusing auth fixture outside the isolated database.');
    }

    return Database::connect();
}

function resetAuthFixture(mysqli $database): void
{
    foreach (['auth_attempts', 'loan_returns', 'loan_requests', 'accounts', 'prestamos', 'usuarios', 'libros'] as $table) {
        $database->query("DROP TABLE IF EXISTS `{$table}`");
    }
    (new Schema($database))->apply();
}

function addFixtureUser(mysqli $database, string $name, string $email): int
{
    $statement = $database->prepare('INSERT INTO usuarios (nombre, correo) VALUES (?, ?)');
    $statement->bind_param('ss', $name, $email);
    $statement->execute();
    return (int) $database->insert_id;
}

function authScalar(mysqli $database, string $sql): int
{
    return (int) $database->query($sql)->fetch_row()[0];
}

function startAuthTestSession(string $name): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $path = dirname(__DIR__) . '/runtime/' . $name;
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create test session path.');
    }
    session_save_path($path);
    session_name('auth_test_' . $name);
    session_id('');
}
