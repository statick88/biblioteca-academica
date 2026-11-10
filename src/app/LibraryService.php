<?php
declare(strict_types = 1);
namespace Library;
use mysqli;
use mysqli_sql_exception;
/** Catalog, library-user, request, loan, and return application service. */
final class LibraryService {
    public function __construct(private readonly mysqli $database, private readonly Auth $auth) {
    }
    /** @return list<array{id:int,title:string,author:string,available:bool,outstanding_loans:int}> */
    public function listBooks(string $search = ''):array {
        $this->auth->requireAccount();
        self::assertUtf8($search);
        $search = trim($search);
        if(self::length($search) > 150) {
            throw new ValidationException('Book search is too long.');
        }
        $like = '%'.$search.'%';
        $statement = $this->database->prepare("SELECT b.id_libro, b.titulo, b.autor, COUNT(p.id_prestamo) AS outstanding_loans FROM libros b LEFT JOIN prestamos p ON p.id_libro = b.id_libro AND NOT EXISTS (SELECT 1 FROM loan_returns r WHERE r.loan_id = p.id_prestamo) WHERE b.titulo LIKE ? OR b.autor LIKE ? GROUP BY b.id_libro, b.titulo, b.autor ORDER BY b.titulo, b.id_libro");
        $statement->bind_param('ss', $like, $like);
        $statement->execute();
        return array_map(static fn(array $row):array=> ['id'=> (int) $row['id_libro'], 'title'=> (string) $row['titulo'], 'author'=> (string) $row['autor'], 'available'=> (int) $row['outstanding_loans'] === 0, 'outstanding_loans'=> (int) $row['outstanding_loans']], $statement->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    /** @return array{id:int,title:string,author:string,available:bool,outstanding_loans:int} */
    public function createBook(string $title, string $author):array {
        $this->auth->requireRole('librarian', 'admin');
        [$title, $author] = self::bookInput($title, $author);
        $available = 1;
        $statement = $this->database->prepare('INSERT INTO libros (titulo, autor, disponible) VALUES (?, ?, ?)');
        $statement->bind_param('ssi', $title, $author, $available);
        $statement->execute();
        return['id'=> (int) $this->database->insert_id, 'title'=> $title, 'author'=> $author, 'available'=> true, 'outstanding_loans'=> 0];
    }
    /** @return array{id:int,title:string,author:string,available:bool,outstanding_loans:int} */
    public function updateBook(int $id, string $title, string $author):array {
        $this->auth->requireRole('librarian', 'admin');
        self::id($id, 'book');
        [$title, $author] = self::bookInput($title, $author);
        $statement = $this->database->prepare('UPDATE libros SET titulo = ?, autor = ? WHERE id_libro = ?');
        $statement->bind_param('ssi', $title, $author, $id);
        $statement->execute();
        if($statement->affected_rows === 0 && ! $this->exists('libros', 'id_libro', $id)) {
            throw new ValidationException('Unknown book.');
        }
        return $this->book($id);
    }
    /** @return list<array{id:int,name:string,email:string}> */
    public function listUsers(string $search = ''):array {
        $this->auth->requireRole('librarian', 'admin');
        self::assertUtf8($search);
        $search = trim($search);
        if(self::length($search) > 100) {
            throw new ValidationException('User search is too long.');
        }
        $like = '%'.$search.'%';
        $statement = $this->database->prepare('SELECT id_usuario, nombre, correo FROM usuarios WHERE nombre LIKE ? OR correo LIKE ? ORDER BY nombre, id_usuario');
        $statement->bind_param('ss', $like, $like);
        $statement->execute();
        return array_map(static fn(array $row):array=> ['id'=> (int) $row['id_usuario'], 'name'=> (string) $row['nombre'], 'email'=> (string) $row['correo']], $statement->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    /** @return array{id:int,name:string,email:string} */
    public function createUser(string $name, string $email):array {
        $this->auth->requireRole('librarian', 'admin');
        [$name, $email] = self::userInput($name, $email);
        $statement = $this->database->prepare('INSERT INTO usuarios (nombre, correo) VALUES (?, ?)');
        $statement->bind_param('ss', $name, $email);
        $statement->execute();
        return['id'=> (int) $this->database->insert_id, 'name'=> $name, 'email'=> $email];
    }
    /** @return array{id:int,name:string,email:string} */
    public function updateUser(int $id, string $name, string $email):array {
        $this->auth->requireRole('librarian', 'admin');
        self::id($id, 'library user');
        [$name, $email] = self::userInput($name, $email);
        $statement = $this->database->prepare('UPDATE usuarios SET nombre = ?, correo = ? WHERE id_usuario = ?');
        $statement->bind_param('ssi', $name, $email, $id);
        $statement->execute();
        if($statement->affected_rows === 0 && ! $this->exists('usuarios', 'id_usuario', $id)) {
            throw new ValidationException('Unknown library user.');
        }
        return['id'=> $id, 'name'=> $name, 'email'=> $email];
    }
    /** @return array{id:int,user_id:int,book_id:int,status:string,loan_id:?int,requested_at:string,confirmed_at:?string,confirmed_by_account_id:?int} */
    public function requestBook(int $bookId):array {
        $account = $this->auth->requireAccount();
        self::id($bookId, 'book');
        $this->database->begin_transaction();
        try {
            $this->lockBook($bookId);
            if( ! $this->exists('usuarios', 'id_usuario', $account['user_id'])) {
                throw new ValidationException('Current library user is unavailable.');
            }
            if($this->outstanding($bookId) > 0) {
                throw new ValidationException('This book is unavailable until its outstanding loan is returned.');
            }
            $pending = $this->database->prepare("SELECT id FROM loan_requests WHERE user_id = ? AND book_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
            $pending->bind_param('ii', $account['user_id'], $bookId);
            $pending->execute();
            if($pending->get_result()->num_rows !== 0) {
                throw new ValidationException('You already have a pending request for this book.');
            }
            $status = 'pending';
            $insert = $this->database->prepare('INSERT INTO loan_requests (user_id, book_id, status) VALUES (?, ?, ?)');
            $insert->bind_param('iis', $account['user_id'], $bookId, $status);
            $insert->execute();
            $id = (int) $this->database->insert_id;
            $this->database->commit();
            return $this->request($id);
        }
        catch(\Throwable $error) {
            $this->database->rollback();
            throw $this->writeError($error);
        }
    }
    /** @return list<array{id:int,user_id:int,user_name:?string,book_id:int,book_title:?string,status:string,loan_id:?int,requested_at:string,confirmed_at:?string,confirmed_by_account_id:?int}> */
    public function listRequests():array {
        $account = $this->auth->requireAccount();
        $staff = in_array($account['role'], ['librarian', 'admin'], true);
        $sql = 'SELECT r.id, r.user_id, u.nombre AS user_name, r.book_id, b.titulo AS book_title, r.status, r.loan_id, r.requested_at, r.confirmed_at, r.confirmed_by_account_id FROM loan_requests r LEFT JOIN usuarios u ON u.id_usuario = r.user_id LEFT JOIN libros b ON b.id_libro = r.book_id';
        if( ! $staff) {
            $sql .= ' WHERE r.user_id = ?';
        }
        $sql .= ' ORDER BY r.requested_at DESC, r.id DESC';
        $statement = $this->database->prepare($sql);
        if( ! $staff) {
            $statement->bind_param('i', $account['user_id']);
        }
        $statement->execute();
        return array_map([$this, 'requestRow'], $statement->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    /** @return array{id:int,user_id:int,book_id:int,issued_on:string} */
    public function confirmRequest(int $requestId):array {
        $staff = $this->auth->requireRole('librarian', 'admin');
        self::id($requestId, 'request');
        $request = $this->one('SELECT book_id FROM loan_requests WHERE id = ? LIMIT 1', $requestId, 'i');
        if($request === null) {
            throw new ValidationException('Unknown request.');
        }
        $bookId = (int) $request['book_id'];
        $this->database->begin_transaction();
        try {
            $this->lockBook($bookId);
            $row = $this->one('SELECT id, user_id, book_id, status FROM loan_requests WHERE id = ? FOR UPDATE', $requestId, 'i');
            if($row === null || $row['status'] !== 'pending' || ! self::positive((int) $row['user_id']) || ! $this->exists('usuarios', 'id_usuario', (int) $row['user_id'])) {
                throw new ValidationException('This request cannot be confirmed.');
            }
            if($this->outstanding($bookId) > 0) {
                throw new ValidationException('This book already has an outstanding loan.');
            }
            $today = gmdate('Y-m-d');
            $insert = $this->database->prepare('INSERT INTO prestamos (id_libro, id_usuario, fecha_salida) VALUES (?, ?, ?)');
            $userId = (int) $row['user_id'];
            $insert->bind_param('iis', $bookId, $userId, $today);
            $insert->execute();
            $loanId = (int) $this->database->insert_id;
            $unavailable = 0;
            $updateBook = $this->database->prepare('UPDATE libros SET disponible = ? WHERE id_libro = ?');
            $updateBook->bind_param('ii', $unavailable, $bookId);
            $updateBook->execute();
            $status = 'confirmed';
            $updateRequest = $this->database->prepare('UPDATE loan_requests SET status = ?, loan_id = ?, confirmed_at = UTC_TIMESTAMP(), confirmed_by_account_id = ? WHERE id = ?');
            $updateRequest->bind_param('siii', $status, $loanId, $staff['id'], $requestId);
            $updateRequest->execute();
            $this->database->commit();
            return['id'=> $loanId, 'user_id'=> $userId, 'book_id'=> $bookId, 'issued_on'=> $today];
        }
        catch(\Throwable $error) {
            $this->database->rollback();
            throw $this->writeError($error);
        }
    }
    /** @return list<array{id:int,user_id:?int,user_name:?string,book_id:?int,book_title:?string,issued_on:string,returned:bool,returned_at:?string,returned_by_account_id:?int}> */
    public function listLoans():array {
        $account = $this->auth->requireAccount();
        $staff = in_array($account['role'], ['librarian', 'admin'], true);
        $sql = 'SELECT p.id_prestamo, p.id_usuario, u.nombre AS user_name, p.id_libro, b.titulo AS book_title, p.fecha_salida, r.returned_at, r.returned_by_account_id FROM prestamos p LEFT JOIN usuarios u ON u.id_usuario = p.id_usuario LEFT JOIN libros b ON b.id_libro = p.id_libro LEFT JOIN loan_returns r ON r.loan_id = p.id_prestamo';
        if( ! $staff) {
            $sql .= ' WHERE p.id_usuario = ?';
        }
        $sql .= ' ORDER BY p.fecha_salida DESC, p.id_prestamo DESC';
        $statement = $this->database->prepare($sql);
        if( ! $staff) {
            $statement->bind_param('i', $account['user_id']);
        }
        $statement->execute();
        return array_map(static fn(array $row):array=> ['id'=> (int) $row['id_prestamo'], 'user_id'=> $row['id_usuario'] === null?null:(int) $row['id_usuario'], 'user_name'=> $row['user_name'], 'book_id'=> $row['id_libro'] === null?null:(int) $row['id_libro'], 'book_title'=> $row['book_title'], 'issued_on'=> (string) $row['fecha_salida'], 'returned'=> $row['returned_at'] !== null, 'returned_at'=> $row['returned_at'], 'returned_by_account_id'=> $row['returned_by_account_id'] === null?null:(int) $row['returned_by_account_id']], $statement->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    public function returnLoan(int $loanId):void {
        $staff = $this->auth->requireRole('librarian', 'admin');
        self::id($loanId, 'loan');
        $loan = $this->one('SELECT id_libro FROM prestamos WHERE id_prestamo = ? LIMIT 1', $loanId, 'i');
        if($loan === null || ! self::positive((int)($loan['id_libro'] ?? 0))) {
            throw new ValidationException('This legacy loan has no valid book reference.');
        }
        $bookId = (int) $loan['id_libro'];
        $this->database->begin_transaction();
        try {
            $this->lockBook($bookId);
            $locked = $this->one('SELECT id_libro, id_usuario FROM prestamos WHERE id_prestamo = ? FOR UPDATE', $loanId, 'i');
            if($locked === null || ! self::positive((int)($locked['id_libro'] ?? 0)) || ! self::positive((int)($locked['id_usuario'] ?? 0)) || ! $this->exists('usuarios', 'id_usuario', (int) $locked['id_usuario'])) {
                throw new ValidationException('This legacy loan has invalid references and cannot be returned.');
            }
            $existing = $this->one('SELECT loan_id FROM loan_returns WHERE loan_id = ? FOR UPDATE', $loanId, 'i');
            if($existing !== null) {
                throw new ValidationException('This loan was already returned.');
            }
            $insert = $this->database->prepare('INSERT INTO loan_returns (loan_id, returned_by_account_id) VALUES (?, ?)');
            $insert->bind_param('ii', $loanId, $staff['id']);
            $insert->execute();
            if($this->outstanding($bookId) === 0) {
                $available = 1;
                $update = $this->database->prepare('UPDATE libros SET disponible = ? WHERE id_libro = ?');
                $update->bind_param('ii', $available, $bookId);
                $update->execute();
            }
            $this->database->commit();
        }
        catch(\Throwable $error) {
            $this->database->rollback();
            throw $this->writeError($error);
        }
    }
    /** @return array{user_id:int,role:string,metrics:array{books:int,available_books:int,requests:int,loans:int,outstanding_loans:int}} */
    public function dashboard():array {
        $account = $this->auth->requireAccount();
        $staff = in_array($account['role'], ['librarian', 'admin'], true);
        $books = $staff?(int) $this->database->query('SELECT COUNT(*) FROM libros')->fetch_row()[0]:0;
        $available = $staff?(int) $this->database->query('SELECT COUNT(*) FROM libros b WHERE NOT EXISTS (SELECT 1 FROM prestamos p WHERE p.id_libro = b.id_libro AND NOT EXISTS (SELECT 1 FROM loan_returns r WHERE r.loan_id = p.id_prestamo))')->fetch_row()[0]:0;
        $requests = $this->countFor($staff?'SELECT COUNT(*) FROM loan_requests':'SELECT COUNT(*) FROM loan_requests WHERE user_id = ?', $staff?null:$account['user_id']);
        $loans = $this->countFor($staff?'SELECT COUNT(*) FROM prestamos':'SELECT COUNT(*) FROM prestamos WHERE id_usuario = ?', $staff?null:$account['user_id']);
        $outstanding = $this->countFor($staff?'SELECT COUNT(*) FROM prestamos p WHERE NOT EXISTS (SELECT 1 FROM loan_returns r WHERE r.loan_id = p.id_prestamo)':'SELECT COUNT(*) FROM prestamos p WHERE p.id_usuario = ? AND NOT EXISTS (SELECT 1 FROM loan_returns r WHERE r.loan_id = p.id_prestamo)', $staff?null:$account['user_id']);
        return['user_id'=> $account['user_id'], 'role'=> $account['role'], 'metrics'=> ['books'=> $books, 'available_books'=> $available, 'requests'=> $requests, 'loans'=> $loans, 'outstanding_loans'=> $outstanding]];
    }
    /** Locks this shared resource first in every request/confirm/return mutation. */
    private function lockBook(int $bookId):void {
        if($this->one('SELECT id_libro FROM libros WHERE id_libro = ? FOR UPDATE', $bookId, 'i') === null) {
            throw new ValidationException('Unknown book.');
        }
    }
    private function outstanding(int $bookId):int {
        $row = $this->one('SELECT COUNT(*) AS count FROM prestamos p WHERE p.id_libro = ? AND NOT EXISTS (SELECT 1 FROM loan_returns r WHERE r.loan_id = p.id_prestamo)', $bookId, 'i');
        return (int) $row['count'];
    }
    /** @return array<string,mixed>|null */
    private function one(string $sql, int $value, string $types):?array {
        $statement = $this->database->prepare($sql);
        $statement->bind_param($types, $value);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row)?$row:null;
    }
    private function exists(string $table, string $column, int $id):bool {
        return $this->one("SELECT {$column} FROM {$table} WHERE {$column} = ? LIMIT 1", $id, 'i') !== null;
    }
    /** @return array{id:int,title:string,author:string,available:bool,outstanding_loans:int} */
    private function book(int $id):array {
        $row = $this->one('SELECT b.id_libro, b.titulo, b.autor, COUNT(p.id_prestamo) AS outstanding_loans FROM libros b LEFT JOIN prestamos p ON p.id_libro = b.id_libro AND NOT EXISTS (SELECT 1 FROM loan_returns r WHERE r.loan_id = p.id_prestamo) WHERE b.id_libro = ? GROUP BY b.id_libro, b.titulo, b.autor', $id, 'i');
        if($row === null) {
            throw new ValidationException('Unknown book.');
        }
        return['id'=> (int) $row['id_libro'], 'title'=> (string) $row['titulo'], 'author'=> (string) $row['autor'], 'available'=> (int) $row['outstanding_loans'] === 0, 'outstanding_loans'=> (int) $row['outstanding_loans']];
    }
    /** @return array{id:int,user_id:int,book_id:int,status:string,loan_id:?int,requested_at:string,confirmed_at:?string,confirmed_by_account_id:?int} */
    private function request(int $id):array {
        $row = $this->one('SELECT id, user_id, book_id, status, loan_id, requested_at, confirmed_at, confirmed_by_account_id FROM loan_requests WHERE id = ?', $id, 'i');
        if($row === null) {
            throw new ValidationException('Unknown request.');
        }
        return $this->requestRow($row);
    }
    /** @param array<string,mixed> $row @return array{id:int,user_id:int,user_name:?string,book_id:int,book_title:?string,status:string,loan_id:?int,requested_at:string,confirmed_at:?string,confirmed_by_account_id:?int} */
    private function requestRow(array $row):array {
        return['id'=> (int) $row['id'], 'user_id'=> (int) $row['user_id'], 'user_name'=> $row['user_name'] ?? null, 'book_id'=> (int) $row['book_id'], 'book_title'=> $row['book_title'] ?? null, 'status'=> (string) $row['status'], 'loan_id'=> $row['loan_id'] === null?null:(int) $row['loan_id'], 'requested_at'=> (string) $row['requested_at'], 'confirmed_at'=> $row['confirmed_at'], 'confirmed_by_account_id'=> $row['confirmed_by_account_id'] === null?null:(int) $row['confirmed_by_account_id']];
    }
    private function countFor(string $sql, ?int $userId):int {
        if($userId === null) {
            return (int) $this->database->query($sql)->fetch_row()[0];
        }
        return (int) $this->one(str_replace('COUNT(*)', 'COUNT(*) AS count', $sql), $userId, 'i')['count'];
    }
    private function writeError(\Throwable $error):\Throwable {
        if($error instanceof ValidationException || $error instanceof AuthorizationException) {
            return $error;
        }
        if($error instanceof mysqli_sql_exception && in_array($error->getCode(), [1205, 1213], true)) {
            return new ValidationException('The operation conflicted with another update; please try again.', 0, $error);
        }
        return $error;
    }
    /** @return array{0:string,1:string} */
    private static function bookInput(string $title, string $author):array {
        self::assertUtf8($title);
        self::assertUtf8($author);
        $title = trim($title);
        $author = trim($author);
        if($title === '' || $author === '' || self::length($title) > 150 || self::length($author) > 100) {
            throw new ValidationException('Book title or author is invalid.');
        }
        return[$title, $author];
    }
    /** @return array{0:string,1:string} */
    private static function userInput(string $name, string $email):array {
        self::assertUtf8($name);
        self::assertUtf8($email);
        $name = trim($name);
        $email = trim($email);
        if($name === '' || self::length($name) > 100 || self::length($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Library user name or email is invalid.');
        }
        return[$name, $email];
    }
    private static function assertUtf8(string $value):void {
        if( ! mb_check_encoding($value, 'UTF-8')) {
            throw new ValidationException('Text must be valid UTF-8.');
        }
    }
    private static function id(int $id, string $label):void {
        if( ! self::positive($id)) {
            throw new ValidationException("Invalid {$label} identifier.");
        }
    }
    private static function positive(int $id):bool {
        return $id > 0;
    }
    private static function length(string $value):int {
        return mb_strlen($value, 'UTF-8');
    }
}
