<?php
declare(strict_types=1);

use Library\AuthorizationException;
use Library\LibraryService;
use Library\ValidationException;

require dirname(__DIR__) . '/src/app/bootstrap.php';
require __DIR__ . '/support/library_fixture.php';

function libraryFail(string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
function librarySame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) { libraryFail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)); } }
function libraryThrows(string $class, Closure $call, string $message): void { try { $call(); } catch (Throwable $error) { if ($error instanceof $class) { return; } libraryFail($message . '; got ' . $error::class); } libraryFail($message . '; did not throw'); }
/** @return list<array{0:int,1:string,2:string}> */
function libraryRace(string $operation, array $ids): array
{
    if (count($ids) !== 2) {
        libraryFail('A race requires exactly two public operation identifiers.');
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/library_concurrency.php') . ' ' . escapeshellarg($operation);
    $environment = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '32768', 'DB_NAME' => 'library_fixture', 'DB_USER' => 'root', 'DB_PASSWORD' => 'library_test_only_01a1c355'];
    $processes = [];
    for ($index = 0; $index < 2; $index++) {
        $pipes = [];
        $workerCommand = $command . ' ' . escapeshellarg((string) $ids[$index]) . " 'librarian' " . escapeshellarg((string) $index);
        $process = proc_open($workerCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
        if (!is_resource($process)) { libraryFail('Could not start concurrency worker.'); }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $results[] = [proc_close($process), $stdout, $stderr];
    }
    return $results;
}

$db = libraryFixtureConnection();
resetLibraryFixture($db);
$readerId = libraryAddUser($db, 'Reader One', 'reader@example.test');
$otherReaderId = libraryAddUser($db, 'Reader Two', 'other@example.test');
$librarianId = libraryAddUser($db, 'Librarian', 'staff@example.test');
$adminId = libraryAddUser($db, 'Admin', 'admin@example.test');
libraryAddAccount($db, $readerId, 'reader', 'reader');
libraryAddAccount($db, $otherReaderId, 'otherreader', 'reader');
libraryAddAccount($db, $librarianId, 'librarian', 'librarian');
libraryAddAccount($db, $adminId, 'admin', 'admin');
$bookId = libraryAddBook($db, 'Distributed Systems', 'Tanenbaum');
$secondBookId = libraryAddBook($db, 'Clean Architecture', 'Martin');
$thirdBookId = libraryAddBook($db, 'Race Conditions', 'Herlihy');
function libraryServiceAs(mysqli $database, string $name, string $username): LibraryService
{
    return new LibraryService($database, libraryAuth($database, $name, $username));
}


librarySame(1, count(libraryServiceAs($db, 'reader', 'reader')->listBooks('architecture')), 'Catalog search is case-insensitive and public to readers.');
librarySame(0, count(libraryServiceAs($db, 'reader', 'reader')->listBooks('no match')), 'Catalog search permits empty result lists.');
libraryThrows(AuthorizationException::class, fn() => libraryServiceAs($db, 'reader', 'reader')->createBook('Denied', 'Denied'), 'Reader cannot create books.');
libraryThrows(AuthorizationException::class, fn() => libraryServiceAs($db, 'reader', 'reader')->listUsers(), 'Reader cannot list library users.');
librarySame(3, libraryScalar($db, 'SELECT COUNT(*) FROM libros'), 'Reader denial leaves books unchanged.');
$newBook = libraryServiceAs($db, 'staff', 'librarian')->createBook('  Unicode ñ title ', '  Author  ');
librarySame('Unicode ñ title', $newBook['title'], 'Book creation trims UTF-8 text.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->createBook('', 'Author'), 'Empty book title is rejected.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->updateBook($newBook['id'], str_repeat('á', 151), 'Author'), 'Book title character length is enforced.');
$updatedBook = libraryServiceAs($db, 'staff', 'librarian')->updateBook($newBook['id'], 'Updated', 'Writer');
librarySame('Updated', $updatedBook['title'], 'Book updates preserve identifier and update fields.');
$newUser = libraryServiceAs($db, 'admin', 'admin')->createUser(' New User ', 'new.user@example.test');
librarySame('New User', $newUser['name'], 'Staff creates library users.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->createUser('Name', 'not-an-email'), 'Invalid email is rejected.');
librarySame($newUser['id'], libraryServiceAs($db, 'staff', 'librarian')->updateUser($newUser['id'], 'Updated User', 'new.user@example.test')['id'], 'Staff updates library users.');
librarySame(true, count(libraryServiceAs($db, 'admin', 'admin')->listUsers('updated')) >= 1, 'Staff user search returns matching rows.');

$invalidUtf8 = "invalid\xFF";
$bookRowsBeforeInvalidUtf8 = libraryScalar($db, 'SELECT COUNT(*) FROM libros');
$bookNextIdBeforeInvalidUtf8 = libraryScalar($db, "SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'libros'");
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->createBook($invalidUtf8, 'Author'), 'Invalid UTF-8 book creation is rejected before SQL.');
librarySame($bookRowsBeforeInvalidUtf8, libraryScalar($db, 'SELECT COUNT(*) FROM libros'), 'Invalid UTF-8 book creation leaves rows unchanged.');
librarySame($bookNextIdBeforeInvalidUtf8, libraryScalar($db, "SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'libros'"), 'Invalid UTF-8 book creation does not advance the identifier counter.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->updateBook($newBook['id'], $invalidUtf8, 'Author'), 'Invalid UTF-8 book update is rejected before SQL.');
librarySame($bookRowsBeforeInvalidUtf8, libraryScalar($db, 'SELECT COUNT(*) FROM libros'), 'Invalid UTF-8 book update leaves rows unchanged.');
librarySame($bookNextIdBeforeInvalidUtf8, libraryScalar($db, "SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'libros'"), 'Invalid UTF-8 book update does not advance the identifier counter.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'reader', 'reader')->listBooks($invalidUtf8), 'Invalid UTF-8 book search is rejected before SQL.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->createUser($invalidUtf8, 'utf8@example.test'), 'Invalid UTF-8 user creation is rejected before SQL.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->updateUser($newUser['id'], $invalidUtf8, 'utf8@example.test'), 'Invalid UTF-8 user update is rejected before SQL.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->listUsers($invalidUtf8), 'Invalid UTF-8 user search is rejected before SQL.');
librarySame('Updated User', libraryServiceAs($db, 'staff', 'librarian')->listUsers('Updated User')[0]['name'], 'Invalid UTF-8 user input preserves existing user state.');

$request = libraryServiceAs($db, 'reader', 'reader')->requestBook($bookId);
librarySame($readerId, $request['user_id'], 'Request derives the current account user identity.');
librarySame(0, libraryScalar($db, 'SELECT COUNT(*) FROM prestamos'), 'Request does not create a loan.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'reader', 'reader')->requestBook($bookId), 'Duplicate pending own-book request is refused.');
librarySame(1, libraryScalar($db, 'SELECT COUNT(*) FROM loan_requests'), 'Duplicate request leaves request rows unchanged.');
librarySame(1, count(libraryServiceAs($db, 'reader', 'reader')->listRequests()), 'Reader sees own request.');
librarySame(0, count(libraryServiceAs($db, 'other', 'otherreader')->listRequests()), 'Reader cannot see another reader request.');
libraryThrows(AuthorizationException::class, fn() => libraryServiceAs($db, 'reader', 'reader')->confirmRequest($request['id']), 'Reader cannot confirm requests.');
$loan = libraryServiceAs($db, 'staff', 'librarian')->confirmRequest($request['id']);
librarySame($bookId, $loan['book_id'], 'Staff confirmation creates a loan for requested book.');
librarySame(1, libraryScalar($db, 'SELECT COUNT(*) FROM prestamos'), 'Confirmation inserts one legacy loan.');
librarySame(0, libraryScalar($db, "SELECT disponible FROM libros WHERE id_libro = {$bookId}"), 'Confirmation derives unavailable status.');
librarySame(1, libraryScalar($db, "SELECT COUNT(*) FROM loan_requests WHERE id = {$request['id']} AND status = 'confirmed' AND loan_id = {$loan['id']} AND confirmed_by_account_id IS NOT NULL"), 'Confirmation records actor, status, and loan atomically.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->confirmRequest($request['id']), 'Repeated confirmation is refused.');
librarySame(1, libraryScalar($db, 'SELECT COUNT(*) FROM prestamos'), 'Repeated confirmation changes no loans.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'other', 'otherreader')->requestBook($bookId), 'Unavailable book requests are refused.');
librarySame(1, count(libraryServiceAs($db, 'reader', 'reader')->listLoans()), 'Reader sees own loan.');
librarySame(0, count(libraryServiceAs($db, 'other', 'otherreader')->listLoans()), 'Reader cannot see another loan.');
libraryThrows(AuthorizationException::class, fn() => libraryServiceAs($db, 'reader', 'reader')->returnLoan($loan['id']), 'Reader cannot return a loan.');
libraryServiceAs($db, 'staff', 'librarian')->returnLoan($loan['id']);
librarySame(1, libraryScalar($db, "SELECT disponible FROM libros WHERE id_libro = {$bookId}"), 'Return makes book available when no outstanding loans remain.');
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->returnLoan($loan['id']), 'Repeated return is refused.');
librarySame(1, libraryScalar($db, 'SELECT COUNT(*) FROM loan_returns'), 'Repeated return leaves return records unchanged.');

$legacyBook = libraryAddBook($db, 'Legacy collision', 'Historian', 0);
$db->query("INSERT INTO prestamos (id_libro, id_usuario, fecha_salida) VALUES ({$legacyBook}, {$readerId}, CURDATE()), ({$legacyBook}, {$otherReaderId}, CURDATE())");
$db->query("UPDATE libros SET disponible = 1 WHERE id_libro = {$legacyBook}");
$legacyCatalog = libraryServiceAs($db, 'reader', 'reader')->listBooks('collision');
librarySame(false, $legacyCatalog[0]['available'], 'Catalog ignores stale available flag while an active legacy loan exists.');
$db->query("UPDATE libros SET disponible = 0 WHERE id_libro = {$legacyBook}");
$legacyLoans = libraryServiceAs($db, 'staff', 'librarian')->listLoans();
librarySame(true, count(array_filter($legacyLoans, fn(array $row): bool => $row['book_id'] === $legacyBook)) === 2, 'Staff sees duplicate outstanding legacy loans.');
$firstLegacyLoan = (int) $db->query("SELECT MIN(id_prestamo) FROM prestamos WHERE id_libro = {$legacyBook}")->fetch_row()[0];
libraryServiceAs($db, 'staff', 'librarian')->returnLoan($firstLegacyLoan);
librarySame(0, libraryScalar($db, "SELECT disponible FROM libros WHERE id_libro = {$legacyBook}"), 'One return does not free a book with another outstanding legacy loan.');
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$db->query("INSERT INTO prestamos (id_libro, id_usuario, fecha_salida) VALUES (NULL, NULL, CURDATE()), (999999, 999999, CURDATE())");
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$staffLoans = libraryServiceAs($db, 'staff', 'librarian')->listLoans();
librarySame(true, count(array_filter($staffLoans, fn(array $row): bool => $row['book_id'] === null || $row['book_id'] === 999999)) === 2, 'Staff sees null and orphaned historical loans safely.');
$orphanLoan = (int) $db->query('SELECT MAX(id_prestamo) FROM prestamos')->fetch_row()[0];
libraryThrows(ValidationException::class, fn() => libraryServiceAs($db, 'staff', 'librarian')->returnLoan($orphanLoan), 'Return refuses orphaned legacy references without repair.');

$distinctRequest = libraryServiceAs($db, 'reader', 'reader')->requestBook($thirdBookId);
$otherDistinctRequest = libraryServiceAs($db, 'other', 'otherreader')->requestBook($thirdBookId);
$distinctConfirmRace = libraryRace('confirm', [$distinctRequest['id'], $otherDistinctRequest['id']]);
$distinctConfirmCodes = array_map(static fn(array $result): int => $result[0], $distinctConfirmRace);
sort($distinctConfirmCodes);
librarySame([0, 2], $distinctConfirmCodes, 'Distinct pending requests for one book yield one confirmation and one refusal.');
librarySame(1, libraryScalar($db, "SELECT COUNT(*) FROM prestamos WHERE id_libro = {$thirdBookId}"), 'Distinct-request race creates exactly one loan.');
librarySame(1, libraryScalar($db, "SELECT COUNT(*) FROM loan_requests WHERE book_id = {$thirdBookId} AND status = 'confirmed' AND loan_id IS NOT NULL AND confirmed_at IS NOT NULL AND confirmed_by_account_id IS NOT NULL"), 'Distinct-request race records actor, time, and loan only for the winner.');
librarySame(1, libraryScalar($db, "SELECT COUNT(*) FROM loan_requests WHERE book_id = {$thirdBookId} AND status = 'pending' AND loan_id IS NULL AND confirmed_at IS NULL AND confirmed_by_account_id IS NULL"), 'Distinct-request race leaves loser request pending and unchanged.');
librarySame(0, libraryScalar($db, "SELECT disponible FROM libros WHERE id_libro = {$thirdBookId}"), 'Distinct-request race marks the book unavailable once.');

$raceRequest = libraryServiceAs($db, 'other', 'otherreader')->requestBook($secondBookId);
$confirmRace = libraryRace('confirm', [$raceRequest['id'], $raceRequest['id']]);
$confirmCodes = array_map(static fn(array $result): int => $result[0], $confirmRace); sort($confirmCodes);
librarySame([0, 2], $confirmCodes, 'Concurrent confirmation has one applied operation and one bounded refusal.');
librarySame(1, libraryScalar($db, "SELECT COUNT(*) FROM prestamos WHERE id_libro = {$secondBookId}"), 'Concurrent confirmation cannot double-loan one book.');
$raceLoan = (int) $db->query("SELECT id_prestamo FROM prestamos WHERE id_libro = {$secondBookId}")->fetch_row()[0];
$returnRace = libraryRace('return', [$raceLoan, $raceLoan]);
$returnCodes = array_map(static fn(array $result): int => $result[0], $returnRace); sort($returnCodes);
librarySame([0, 2], $returnCodes, 'Concurrent return has one applied operation and one bounded refusal.');
librarySame(1, libraryScalar($db, "SELECT COUNT(*) FROM loan_returns WHERE loan_id = {$raceLoan}"), 'Concurrent return cannot create duplicate return records.');

$dashboard = libraryServiceAs($db, 'reader', 'reader')->dashboard();
librarySame($readerId, $dashboard['user_id'], 'Dashboard is scoped to the current account identity.');
librarySame(true, $dashboard['metrics']['loans'] >= 1, 'Dashboard computes reader metrics from current state.');
librarySame(true, libraryServiceAs($db, 'admin', 'admin')->dashboard()['metrics']['books'] >= 1, 'Staff dashboard exposes actual global metrics.');
resetLibraryFixture($db);

echo "OK library_test\n";
