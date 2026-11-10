<?php
declare(strict_types=1);

namespace Library;

use mysqli;

/** Additive manager for the fixed classroom legacy schema. */
final class Schema
{
    public function __construct(private readonly mysqli $database)
    {
    }

    /**
     * Performs schema validation and returns PII-free aggregate diagnostics.
     * @return array<string, int|array<string, int>>
     */
    public function preflight(): array
    {
        $this->assertValidState();
        $legacy = ['libros', 'usuarios', 'prestamos'];
        $rows = [];
        foreach ($legacy as $table) {
            $rows[$table] = $this->tableExists($table) ? $this->count("SELECT COUNT(*) FROM `{$table}`") : 0;
        }
        $report = [
            'legacy_tables_present' => array_sum(array_map(fn(string $table): int => $this->tableExists($table) ? 1 : 0, $legacy)),
            'legacy_rows' => $rows,
            'null_references' => ['prestamos.user_id' => 0, 'prestamos.book_id' => 0],
            'orphaned_references' => ['prestamos.user_id' => 0, 'prestamos.book_id' => 0],
            'duplicate_outstanding_loans' => 0,
            'availability_mismatches' => 0,
            'duplicate_emails' => 0,
        ];

        if (!$this->tableExists('prestamos')) {
            return $report;
        }

        $report['null_references']['prestamos.user_id'] = $this->count('SELECT COUNT(*) FROM prestamos WHERE id_usuario IS NULL');
        $report['null_references']['prestamos.book_id'] = $this->count('SELECT COUNT(*) FROM prestamos WHERE id_libro IS NULL');
        $report['orphaned_references']['prestamos.user_id'] = $this->count('SELECT COUNT(*) FROM prestamos p LEFT JOIN usuarios u ON p.id_usuario = u.id_usuario WHERE p.id_usuario IS NOT NULL AND u.id_usuario IS NULL');
        $report['orphaned_references']['prestamos.book_id'] = $this->count('SELECT COUNT(*) FROM prestamos p LEFT JOIN libros b ON p.id_libro = b.id_libro WHERE p.id_libro IS NOT NULL AND b.id_libro IS NULL');
        $returnedFilter = $this->tableExists('loan_returns')
            ? ' AND NOT EXISTS (SELECT 1 FROM loan_returns r WHERE r.loan_id = p.id_prestamo)'
            : '';
        $report['duplicate_outstanding_loans'] = $this->count("SELECT COUNT(*) FROM (SELECT id_libro FROM prestamos p WHERE id_libro IS NOT NULL{$returnedFilter} GROUP BY id_libro HAVING COUNT(*) > 1) AS duplicate_books");
        $report['availability_mismatches'] = $this->count("SELECT COUNT(*) FROM libros b WHERE (b.disponible = 1 AND EXISTS (SELECT 1 FROM prestamos p WHERE p.id_libro = b.id_libro{$returnedFilter})) OR (b.disponible = 0 AND NOT EXISTS (SELECT 1 FROM prestamos p WHERE p.id_libro = b.id_libro{$returnedFilter}))");
        $report['duplicate_emails'] = $this->count("SELECT COUNT(*) FROM (SELECT correo FROM usuarios WHERE correo <> '' GROUP BY correo HAVING COUNT(*) > 1) AS duplicate_emails");
        return $report;
    }

    /** @return array{created_legacy_tables:list<string>, created_additive_tables:list<string>} */
    public function apply(): array
    {
        // This public API shares the same no-write validation boundary as CLI.
        $this->preflight();
        $createdLegacy = [];
        foreach ($this->legacyDefinitions() as $table => $definition) {
            if (!$this->tableExists($table)) {
                $this->database->query($definition);
                $createdLegacy[] = $table;
            }
        }
        $createdAdditive = [];
        foreach ($this->additiveDefinitions() as $table => $definition) {
            if (!$this->tableExists($table)) {
                $this->database->query($definition);
                $createdAdditive[] = $table;
            }
        }
        return ['created_legacy_tables' => $createdLegacy, 'created_additive_tables' => $createdAdditive];
    }

    private function assertValidState(): void
    {
        $legacy = ['libros', 'usuarios', 'prestamos'];
        $present = array_filter($legacy, fn(string $table): bool => $this->tableExists($table));
        if ($present !== [] && count($present) !== count($legacy)) {
            throw new \LogicException('Legacy schema is incomplete.');
        }
        if (count($present) === count($legacy)) {
            foreach ($this->legacyContract() as $table => $columns) {
                $this->assertTableColumns($table, $columns);
            }
            $this->assertLegacyForeignKeys();
        }
        foreach ($this->additiveContract() as $table => $columns) {
            if ($this->tableExists($table)) {
                $this->assertTableColumns($table, $columns);
            }
        }
        if ($this->tableExists('loan_returns')) {
            $this->assertPrimaryKey('loan_returns', 'loan_id');
        }
        if ($this->tableExists('accounts')) {
            $this->assertUniqueKey('accounts', 'username');
            $this->assertUniqueKey('accounts', 'user_id');
        }
    }

    /** @return array<string, array<string, array{type:string, length:?int, nullable:string, auto:bool}>> */
    private function legacyContract(): array
    {
        return [
            'libros' => [
                'id_libro' => ['type' => 'int', 'length' => null, 'nullable' => 'NO', 'auto' => true],
                'titulo' => ['type' => 'varchar', 'length' => 150, 'nullable' => 'NO', 'auto' => false],
                'autor' => ['type' => 'varchar', 'length' => 100, 'nullable' => 'NO', 'auto' => false],
                'disponible' => ['type' => 'tinyint', 'length' => null, 'nullable' => 'YES', 'auto' => false],
            ],
            'usuarios' => [
                'id_usuario' => ['type' => 'int', 'length' => null, 'nullable' => 'NO', 'auto' => true],
                'nombre' => ['type' => 'varchar', 'length' => 100, 'nullable' => 'NO', 'auto' => false],
                'correo' => ['type' => 'varchar', 'length' => 100, 'nullable' => 'NO', 'auto' => false],
            ],
            'prestamos' => [
                'id_prestamo' => ['type' => 'int', 'length' => null, 'nullable' => 'NO', 'auto' => true],
                'id_usuario' => ['type' => 'int', 'length' => null, 'nullable' => 'YES', 'auto' => false],
                'id_libro' => ['type' => 'int', 'length' => null, 'nullable' => 'YES', 'auto' => false],
                'fecha_salida' => ['type' => 'date', 'length' => null, 'nullable' => 'NO', 'auto' => false],
            ],
        ];
    }

    /** @return array<string, array<string, array{type:string, length:?int, nullable:string, auto:bool}>> */
    private function additiveContract(): array
    {
        return [
            'accounts' => [
                'id' => ['type' => 'bigint', 'length' => null, 'nullable' => 'NO', 'auto' => true],
                'user_id' => ['type' => 'int', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'username' => ['type' => 'varchar', 'length' => 100, 'nullable' => 'NO', 'auto' => false],
                'password_hash' => ['type' => 'varchar', 'length' => 255, 'nullable' => 'NO', 'auto' => false],
                'role' => ['type' => 'enum', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'active' => ['type' => 'tinyint', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'created_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'updated_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
            ],
            'loan_requests' => [
                'id' => ['type' => 'bigint', 'length' => null, 'nullable' => 'NO', 'auto' => true],
                'user_id' => ['type' => 'int', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'book_id' => ['type' => 'int', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'status' => ['type' => 'enum', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'loan_id' => ['type' => 'int', 'length' => null, 'nullable' => 'YES', 'auto' => false],
                'requested_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'confirmed_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'YES', 'auto' => false],
                'confirmed_by_account_id' => ['type' => 'bigint', 'length' => null, 'nullable' => 'YES', 'auto' => false],
                'created_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'updated_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
            ],
            'loan_returns' => [
                'loan_id' => ['type' => 'int', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'returned_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'returned_by_account_id' => ['type' => 'bigint', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'created_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
            ],
            'auth_attempts' => [
                'id' => ['type' => 'bigint', 'length' => null, 'nullable' => 'NO', 'auto' => true],
                'identifier_hash' => ['type' => 'char', 'length' => 64, 'nullable' => 'NO', 'auto' => false],
                'ip_hash' => ['type' => 'char', 'length' => 64, 'nullable' => 'NO', 'auto' => false],
                'window_started_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'attempt_count' => ['type' => 'int', 'length' => null, 'nullable' => 'NO', 'auto' => false],
                'updated_at' => ['type' => 'datetime', 'length' => null, 'nullable' => 'NO', 'auto' => false],
            ],
        ];
    }

    /** @param array<string, array{type:string, length:?int, nullable:string, auto:bool}> $expected */
    private function assertTableColumns(string $table, array $expected): void
    {
        $actual = $this->columns($table);
        foreach ($expected as $name => $contract) {
            if (!isset($actual[$name])
                || $actual[$name]['type'] !== $contract['type']
                || ($contract['length'] !== null && $actual[$name]['length'] !== $contract['length'])
                || $actual[$name]['nullable'] !== $contract['nullable']
                || $actual[$name]['auto'] !== $contract['auto']) {
                throw new \LogicException("Table {$table} does not match the required migration contract.");
            }
        }
    }

    private function assertLegacyForeignKeys(): void
    {
        foreach (['id_usuario' => 'usuarios', 'id_libro' => 'libros'] as $column => $reference) {
            $statement = $this->database->prepare('SELECT 1 FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND referenced_table_name = ? LIMIT 1');
            $table = 'prestamos';
            $statement->bind_param('sss', $table, $column, $reference);
            $statement->execute();
            if ($statement->get_result()->num_rows !== 1) {
                throw new \LogicException('Legacy loan foreign keys are missing.');
            }
        }
    }

    private function assertPrimaryKey(string $table, string $column): void
    {
        $statement = $this->database->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND column_name = ? LIMIT 1');
        $index = 'PRIMARY';
        $statement->bind_param('sss', $table, $index, $column);
        $statement->execute();
        if ($statement->get_result()->num_rows !== 1) {
            throw new \LogicException("Table {$table} has an invalid primary key.");
        }
    }

    private function assertUniqueKey(string $table, string $column): void
    {
        $statement = $this->database->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND non_unique = 0 LIMIT 1');
        $statement->bind_param('ss', $table, $column);
        $statement->execute();
        if ($statement->get_result()->num_rows !== 1) {
            throw new \LogicException("Table {$table} is missing a required unique key.");
        }
    }

    /** @return array<string, array{type:string, length:?int, nullable:string, auto:bool}> */
    private function columns(string $table): array
    {
        $statement = $this->database->prepare('SELECT column_name AS name, data_type AS type, character_maximum_length AS length, is_nullable AS nullable, extra AS extra_info FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->bind_param('s', $table);
        $statement->execute();
        $result = [];
        foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $column) {
            $result[$column['name']] = [
                'type' => strtolower($column['type']),
                'length' => $column['length'] === null ? null : (int) $column['length'],
                'nullable' => $column['nullable'],
                'auto' => str_contains(strtolower($column['extra_info']), 'auto_increment'),
            ];
        }
        return $result;
    }

    /** @return array<string, string> */
    private function legacyDefinitions(): array
    {
        return [
            'libros' => 'CREATE TABLE libros (id_libro INT NOT NULL AUTO_INCREMENT, titulo VARCHAR(150) NOT NULL, autor VARCHAR(100) NOT NULL, disponible TINYINT(1) DEFAULT 1, PRIMARY KEY (id_libro)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'usuarios' => 'CREATE TABLE usuarios (id_usuario INT NOT NULL AUTO_INCREMENT, nombre VARCHAR(100) NOT NULL, correo VARCHAR(100) NOT NULL, PRIMARY KEY (id_usuario)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'prestamos' => 'CREATE TABLE prestamos (id_prestamo INT NOT NULL AUTO_INCREMENT, id_libro INT NULL, id_usuario INT NULL, fecha_salida DATE NOT NULL, PRIMARY KEY (id_prestamo), CONSTRAINT prestamos_usuario_fk FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario), CONSTRAINT prestamos_libro_fk FOREIGN KEY (id_libro) REFERENCES libros (id_libro)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    /** @return array<string, string> */
    private function additiveDefinitions(): array
    {
        return [
            'accounts' => "CREATE TABLE accounts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, username VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, role ENUM('reader', 'librarian', 'admin') NOT NULL DEFAULT 'reader', active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY accounts_username_unique (username), UNIQUE KEY accounts_user_id_unique (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'loan_requests' => "CREATE TABLE loan_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, book_id INT NOT NULL, status ENUM('pending', 'confirmed') NOT NULL DEFAULT 'pending', loan_id INT NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, confirmed_at DATETIME NULL, confirmed_by_account_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY loan_requests_user_status_index (user_id, status), KEY loan_requests_book_status_index (book_id, status), KEY loan_requests_loan_index (loan_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'loan_returns' => 'CREATE TABLE loan_returns (loan_id INT NOT NULL PRIMARY KEY, returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, returned_by_account_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'auth_attempts' => 'CREATE TABLE auth_attempts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, identifier_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL, window_started_at DATETIME NOT NULL, attempt_count INT UNSIGNED NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY auth_attempts_window_unique (identifier_hash, ip_hash, window_started_at), KEY auth_attempts_window_index (window_started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $statement->bind_param('s', $table);
        $statement->execute();
        return $statement->get_result()->num_rows === 1;
    }

    private function count(string $query): int
    {
        $row = $this->database->query($query)->fetch_row();
        return (int) $row[0];
    }
}
