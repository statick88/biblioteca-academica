<?php
declare(strict_types=1);

use Library\Auth;
use Library\Database;
use Library\Schema;
use Library\ValidationException;

require dirname(__DIR__) . '/src/app/bootstrap.php';

function usage(): string
{
    return "Usage: php bin/create-admin.php --username NAME (--user-id ID | --name NAME --email EMAIL)\n\nPassword is read once from standard input; it is never accepted as an option.\n\nOptions:\n  --username NAME  Unique login name.\n  --user-id ID     Link the first admin to an existing library user.\n  --name NAME      Create the first library user (requires --email).\n  --email EMAIL    Email for a newly created library user (requires --name).\n  --help           Show this help message.\n";
}

function failCli(string $message, int $exit = 1): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit($exit);
}

function requiredTables(mysqli $database): bool
{
    // Read-only contract validation is safe here; bootstrap must never apply DDL.
    try {
        (new Schema($database))->preflight();
    } catch (LogicException) {
        return false;
    }
    $result = $database->query("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('usuarios', 'accounts')");
    return count(array_column($result->fetch_all(MYSQLI_ASSOC), 'name')) === 2;
}

function parsedOptions(array $arguments): array
{
    $allowed = ['--username' => 'username', '--user-id' => 'user_id', '--name' => 'name', '--email' => 'email'];
    $options = [];
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($argument === '--help') {
            if ($arguments !== []) {
                failCli('unknown option ' . (string) $arguments[0], 64);
            }
            echo usage();
            exit(0);
        }
        if (!isset($allowed[$argument])) {
            failCli('unknown option ' . $argument, 64);
        }
        if ($arguments === [] || str_starts_with((string) $arguments[0], '--') || isset($options[$allowed[$argument]])) {
            failCli('invalid option usage', 64);
        }
        $options[$allowed[$argument]] = array_shift($arguments);
    }
    if (!isset($options['username'])) {
        failCli('missing required --username option', 64);
    }
    $hasUser = isset($options['user_id']);
    $hasNew = isset($options['name']) || isset($options['email']);
    if ($hasUser === $hasNew || ($hasNew && (!isset($options['name']) || !isset($options['email'])))) {
        failCli('provide --user-id or both --name and --email', 64);
    }
    return $options;
}

if (PHP_SAPI !== 'cli') {
    failCli('this command is CLI-only', 64);
}
$options = parsedOptions(array_slice($argv, 1));
try {
    $username = Auth::validatedUsername((string) $options['username']);
    if (isset($options['user_id']) && (!ctype_digit((string) $options['user_id']) || (int) $options['user_id'] < 1)) {
        throw new ValidationException('Invalid library user.');
    }
    if (isset($options['name']) && (trim((string) $options['name']) === '' || strlen((string) $options['name']) > 100)) {
        throw new ValidationException('Invalid library user name.');
    }
    if (isset($options['email']) && (strlen((string) $options['email']) > 100 || filter_var($options['email'], FILTER_VALIDATE_EMAIL) === false)) {
        throw new ValidationException('Invalid library user email.');
    }
    $password = stream_get_contents(STDIN);
    if ($password === false) {
        throw new ValidationException('Password could not be read.');
    }
    $password = rtrim($password, "\r\n");
    Auth::assertPassword($password);
} catch (ValidationException) {
    failCli('invalid bootstrap input', 64);
}

try {
    $database = Database::connect();
    if (!requiredTables($database)) {
        failCli('required tables are not present');
    }
    $lock = $database->query("SELECT GET_LOCK('library_create_first_admin', 10)")->fetch_row();
    if (!is_array($lock) || (int) $lock[0] !== 1) {
        failCli('could not acquire bootstrap lock');
    }
    try {
        $database->begin_transaction();
        if ((int) $database->query('SELECT COUNT(*) FROM accounts')->fetch_row()[0] !== 0) {
            throw new RuntimeException('An account already exists.');
        }
        if (isset($options['user_id'])) {
            $userId = (int) $options['user_id'];
            $user = $database->prepare('SELECT id_usuario FROM usuarios WHERE id_usuario = ? LIMIT 1 FOR UPDATE');
            $user->bind_param('i', $userId);
            $user->execute();
            if ($user->get_result()->num_rows !== 1) {
                throw new ValidationException('Unknown library user.');
            }
        } else {
            $name = trim((string) $options['name']);
            $email = trim((string) $options['email']);
            $user = $database->prepare('INSERT INTO usuarios (nombre, correo) VALUES (?, ?)');
            $user->bind_param('ss', $name, $email);
            $user->execute();
            $userId = (int) $database->insert_id;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $role = 'admin';
        $active = 1;
        $account = $database->prepare('INSERT INTO accounts (user_id, username, password_hash, role, active) VALUES (?, ?, ?, ?, ?)');
        $account->bind_param('isssi', $userId, $username, $hash, $role, $active);
        $account->execute();
        $database->commit();
        echo "First administrator created.\n";
    } catch (Throwable $error) {
        $database->rollback();
        failCli('bootstrap refused');
    } finally {
        $database->query("SELECT RELEASE_LOCK('library_create_first_admin')");
    }
} catch (Throwable) {
    failCli('bootstrap failed');
}
