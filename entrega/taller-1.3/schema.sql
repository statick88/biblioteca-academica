-- Esquema documental derivado de src/app/Schema.php.
-- NO ejecutar sobre tablas existentes: usar bin/migrate.php con preflight.
-- Conserva las tres tablas heredadas y agrega cuatro tablas de soporte.
-- No incluye credenciales, cuentas ni registros de muestra.
-- CREATE TABLE IF NOT EXISTS no actualiza columnas existentes; el CLI valida el contrato.

CREATE TABLE libros (
    id_libro INT NOT NULL AUTO_INCREMENT,
    titulo VARCHAR(150) NOT NULL,
    autor VARCHAR(100) NOT NULL,
    disponible TINYINT(1) DEFAULT 1,
    PRIMARY KEY (id_libro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE usuarios (
    id_usuario INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(100) NOT NULL,
    PRIMARY KEY (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE prestamos (
    id_prestamo INT NOT NULL AUTO_INCREMENT,
    id_libro INT NULL,
    id_usuario INT NULL,
    fecha_salida DATE NOT NULL,
    PRIMARY KEY (id_prestamo),
    CONSTRAINT prestamos_usuario_fk FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario),
    CONSTRAINT prestamos_libro_fk FOREIGN KEY (id_libro) REFERENCES libros (id_libro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('reader', 'librarian', 'admin') NOT NULL DEFAULT 'reader',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY accounts_username_unique (username),
    UNIQUE KEY accounts_user_id_unique (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    status ENUM('pending', 'confirmed') NOT NULL DEFAULT 'pending',
    loan_id INT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    confirmed_by_account_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY loan_requests_user_status_index (user_id, status),
    KEY loan_requests_book_status_index (book_id, status),
    KEY loan_requests_loan_index (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_returns (
    loan_id INT NOT NULL PRIMARY KEY,
    returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    returned_by_account_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY auth_attempts_window_unique (identifier_hash, ip_hash, window_started_at),
    KEY auth_attempts_window_index (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
