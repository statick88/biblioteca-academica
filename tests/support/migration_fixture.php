<?php
declare(strict_types=1);

use Library\Database;

const FIXTURE_DATABASE = 'library_fixture';

function fixtureConnection(): mysqli
{
    if (getenv('DB_NAME') !== FIXTURE_DATABASE
        || getenv('DB_HOST') !== '127.0.0.1'
        || getenv('DB_PORT') !== '32768') {
        fail('Refusing destructive fixture setup outside 127.0.0.1:32768/library_fixture.');
    }

    return Database::connect();
}

function resetFixture(mysqli $db): void
{
    foreach (['auth_attempts', 'loan_returns', 'loan_requests', 'accounts', 'prestamos', 'usuarios', 'libros'] as $table) {
        $db->query("DROP TABLE IF EXISTS `{$table}`");
    }
}

function createOriginalLegacySchema(mysqli $db): void
{
    // Independently copied authoritative legacy contract, not Schema implementation.
    $db->query('CREATE TABLE libros (
        id_libro INT NOT NULL AUTO_INCREMENT,
        titulo VARCHAR(150) NOT NULL,
        autor VARCHAR(100) NOT NULL,
        disponible TINYINT(1) DEFAULT 1,
        PRIMARY KEY (id_libro)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->query('CREATE TABLE usuarios (
        id_usuario INT NOT NULL AUTO_INCREMENT,
        nombre VARCHAR(100) NOT NULL,
        correo VARCHAR(100) NOT NULL,
        PRIMARY KEY (id_usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->query('CREATE TABLE prestamos (
        id_prestamo INT NOT NULL AUTO_INCREMENT,
        id_libro INT NULL,
        id_usuario INT NULL,
        fecha_salida DATE NOT NULL,
        PRIMARY KEY (id_prestamo),
        CONSTRAINT prestamos_usuario_fk FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario),
        CONSTRAINT prestamos_libro_fk FOREIGN KEY (id_libro) REFERENCES libros (id_libro)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function createLegacyFixture(mysqli $db, bool $inconsistent = false): void
{
    createOriginalLegacySchema($db);
    $db->query("INSERT INTO libros (id_libro, titulo, autor, disponible) VALUES (7, 'Legacy Book', 'Legacy Author', 1), (8, 'Second Book', 'Second Author', 1)");
    $db->query("INSERT INTO usuarios (id_usuario, nombre, correo) VALUES (11, 'Legacy Reader', 'reader@example.test'), (12, 'Duplicate Email', 'reader@example.test')");
    $db->query("INSERT INTO prestamos (id_prestamo, id_usuario, id_libro, fecha_salida) VALUES (21, 11, 7, '2024-01-01')");

    if ($inconsistent) {
        // Production constraints are preserved; fixture-only checks are disabled
        // solely to model historical invalid rows which preflight must count.
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query("INSERT INTO prestamos (id_prestamo, id_usuario, id_libro, fecha_salida) VALUES (22, 999, 7, '2024-01-02'), (23, NULL, NULL, '2024-01-03')");
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function createIncompleteLegacySchema(mysqli $db): void
{
    $db->query('CREATE TABLE libros (id_libro INT NOT NULL AUTO_INCREMENT, titulo VARCHAR(150) NOT NULL, autor VARCHAR(100) NOT NULL, disponible TINYINT(1) DEFAULT 1, PRIMARY KEY (id_libro)) ENGINE=InnoDB');
}

function createIdOnlyLegacySchema(mysqli $db): void
{
    $db->query('CREATE TABLE libros (id_libro INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $db->query('CREATE TABLE usuarios (id_usuario INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $db->query('CREATE TABLE prestamos (id_prestamo INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
}

function createMalformedLoanReturns(mysqli $db): void
{
    createOriginalLegacySchema($db);
    $db->query('CREATE TABLE loan_returns (loan_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function createMalformedAccounts(mysqli $db): void
{
    createOriginalLegacySchema($db);
    $db->query('CREATE TABLE accounts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function scalar(mysqli $db, string $sql): int
{
    $row = $db->query($sql)->fetch_row();
    return (int) $row[0];
}

/** @return list<string> */
function columnNames(mysqli $db, string $table): array
{
    $statement = $db->prepare('SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
    $statement->bind_param('s', $table);
    $statement->execute();
    return array_map(static fn(array $row): string => $row['name'], $statement->get_result()->fetch_all(MYSQLI_ASSOC));
}

/** @return array<string, array{type:string, nullable:string, default:?string}> */
function columnMetadata(mysqli $db, string $table): array
{
    $statement = $db->prepare('SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS default_value FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
    $statement->bind_param('s', $table);
    $statement->execute();
    $result = [];
    foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $result[$row['name']] = ['type' => strtolower($row['type']), 'nullable' => $row['nullable'], 'default' => $row['default_value']];
    }
    return $result;
}

function tableCount(mysqli $db): int
{
    return scalar($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
}
