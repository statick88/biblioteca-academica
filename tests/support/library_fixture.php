<?php
declare(strict_types=1);

use Library\Auth;
use Library\Database;
use Library\Schema;
use Library\Session;

const LIBRARY_FIXTURE_DATABASE = 'library_fixture';

function libraryFixtureConnection(): mysqli
{
    if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '32768' || getenv('DB_NAME') !== LIBRARY_FIXTURE_DATABASE || getenv('DB_USER') !== 'root' || getenv('DB_PASSWORD') !== 'library_test_only_01a1c355') {
        throw new RuntimeException('Refusing library fixture outside the exact isolated target.');
    }
    return Database::connect();
}

function resetLibraryFixture(mysqli $database): void
{
    foreach (['auth_attempts', 'loan_returns', 'loan_requests', 'accounts', 'prestamos', 'usuarios', 'libros'] as $table) {
        $database->query("DROP TABLE IF EXISTS `{$table}`");
    }
    (new Schema($database))->apply();
}

function libraryAddUser(mysqli $database, string $name, string $email): int
{
    $statement = $database->prepare('INSERT INTO usuarios (nombre, correo) VALUES (?, ?)');
    $statement->bind_param('ss', $name, $email);
    $statement->execute();
    return (int) $database->insert_id;
}

function libraryAddBook(mysqli $database, string $title, string $author, int $available = 1): int
{
    $statement = $database->prepare('INSERT INTO libros (titulo, autor, disponible) VALUES (?, ?, ?)');
    $statement->bind_param('ssi', $title, $author, $available);
    $statement->execute();
    return (int) $database->insert_id;
}

function libraryAddAccount(mysqli $database, int $userId, string $username, string $role): int
{
    $hash = password_hash('library test password 123', PASSWORD_BCRYPT, ['cost' => 12]);
    $active = 1;
    $statement = $database->prepare('INSERT INTO accounts (user_id, username, password_hash, role, active) VALUES (?, ?, ?, ?, ?)');
    $statement->bind_param('isssi', $userId, $username, $hash, $role, $active);
    $statement->execute();
    return (int) $database->insert_id;
}

function librarySession(string $name): Session
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $path = dirname(__DIR__) . '/runtime/library-' . $name;
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create library test session path.');
    }
    session_save_path($path);
    session_name('library_test_' . $name);
    session_id('');
    return new Session(900, false);
}

function libraryAuth(mysqli $database, string $sessionName, string $username): Auth
{
    $session = librarySession($sessionName);
    $auth = new Auth($database, $session);
    $result = $auth->login($username, 'library test password 123', '127.0.0.1');
    if ($result['ok'] !== true) {
        throw new RuntimeException('Fixture authentication failed.');
    }
    return $auth;
}

function libraryScalar(mysqli $database, string $sql): int
{
    return (int) $database->query($sql)->fetch_row()[0];
}
