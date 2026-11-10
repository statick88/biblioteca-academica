# LibraryService frontend contract

`Library\LibraryService` is constructed with `mysqli` and `Library\Auth`. Every public method rechecks the current database-backed account through `Auth`; callers never supply an account, role, or library-user identity. All methods throw `AuthorizationException` for a missing/insufficient account and `ValidationException` for invalid input, unavailable state, unknown business objects, repeat actions, or a retryable database lock conflict. Database failures outside those categories propagate as infrastructure failures.

Strings are UTF-8 trimmed and character-limited; IDs are positive integers. `listBooks` returns actual availability from unreturned `prestamos`, not the legacy `libros.disponible` flag.

## Catalog and library users

- `listBooks(string $search = ''): list<Book>`: any authenticated role. `search` matches title or author, max 150 characters.
- `createBook(string $title, string $author): Book`, `updateBook(int $id, string $title, string $author): Book`: librarian/admin only. Title is required, max 150 characters; author is required, max 100. Availability cannot be posted or edited.
- `listUsers(string $search = ''): list<LibraryUser>`: librarian/admin only; search max 100 characters.
- `createUser(string $name, string $email): LibraryUser`, `updateUser(int $id, string $name, string $email): LibraryUser`: librarian/admin only. Name required/max 100, email valid/max 100. Duplicate emails are retained.

```text
Book        = {id:int, title:string, author:string, available:bool, outstanding_loans:int}
LibraryUser = {id:int, name:string, email:string}
```

## Requests, loans, and returns

- `requestBook(int $bookId): Request`: any authenticated role. The requesting `user_id` is always the current account's linked user. It locks the book, validates the linked legacy user, rejects unavailable books and duplicate pending requests for that user/book, and writes no loan.
- `listRequests(): list<RequestWithLabels>`: readers receive only their own rows; librarian/admin receive all rows. Nullable labels are safe for legacy/orphaned display.
- `confirmRequest(int $requestId): Loan`: librarian/admin only. Locks the book then request, rechecks its pending user/book state and all outstanding loans, creates exactly one legacy loan, sets `disponible=0`, and records confirmation account/time/loan ID in one transaction.
- `listLoans(): list<LoanWithLabels>`: readers receive only their own loans; librarian/admin receive all rows, including historical null/orphaned labels as `null`.
- `returnLoan(int $loanId): void`: librarian/admin only. Locks book first, rechecks references and absence of a return, records staff actor/time once, and sets `disponible=1` only when no other outstanding loan remains. Invalid historical references remain visible but cannot be repaired through this API.
- `dashboard(): Dashboard`: reader metrics are own-only; librarian/admin metrics are global and computed from current rows.

```text
Request = {id:int, user_id:int, book_id:int, status:'pending'|'confirmed', loan_id:?int,
           requested_at:string, confirmed_at:?string, confirmed_by_account_id:?int}
RequestWithLabels = Request & {user_name:?string, book_title:?string}
Loan = {id:int, user_id:int, book_id:int, issued_on:string}
LoanWithLabels = {id:int, user_id:?int, user_name:?string, book_id:?int, book_title:?string,
                  issued_on:string, returned:bool, returned_at:?string, returned_by_account_id:?int}
Dashboard = {user_id:int, role:'reader'|'librarian'|'admin',
             metrics:{books:int, available_books:int, requests:int, loans:int, outstanding_loans:int}}
```

UI routes must apply CSRF validation before invoking mutations; this service enforces identity/role/ownership but does not accept HTTP requests itself.

## Validation evidence limitation

The focused fixture suite covers reader isolation and service behavior. An independent exact dashboard/staff-global-list scope probe was exhausted because its harness shared PHP session state; its result is not claimed as proof. The user explicitly accepted this verification limitation. It must not be rerun or represented as independently verified by this unit.
