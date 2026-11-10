<?php
declare(strict_types=1);

use Library\{Auth, AuthorizationException, Database, LibraryService, Session, ValidationException, Web};
require __DIR__ . '/app/bootstrap.php';

$environment = getenv('APP_ENV') ?: 'production';
$sessionPath = getenv('SESSION_SAVE_PATH');
if ($sessionPath !== false && $sessionPath !== '') { session_save_path($sessionPath); }
$session = new Session(1800, $environment !== 'local');
$web = new Web($session);
$web->headers(($web->method() === 'GET' && (($_GET['page'] ?? 'login') === 'login')));
$pages = ['login', 'dashboard', 'books', 'users', 'requests', 'loans', 'accounts'];
$actions = ['login', 'logout', 'create_book', 'edit_book', 'create_user', 'edit_user', 'request', 'confirm', 'return', 'create_account', 'activate', 'deactivate'];
if (!in_array($web->method(), ['GET', 'POST'], true)) { header('Allow: GET, POST'); $web->error(405, 'Método no permitido.'); }
try {
    $database = Database::connect();
    $auth = new Auth($database, $session);
    $service = new LibraryService($database, $auth);
    if ($web->method() === 'POST') {
        $action = $_POST['action'] ?? null;
        if (!is_string($action) || !in_array($action, $actions, true)) { $web->error(400, 'La acción solicitada no existe.'); }
        $web->csrfOrFail();
        // Authorize the action before validating fields or rendering a correction form.
        if (in_array($action, ['create_account', 'activate', 'deactivate'], true)) {
            $auth->requireRole('admin');
        } elseif (in_array($action, ['create_book', 'edit_book', 'create_user', 'edit_user', 'confirm', 'return'], true)) {
            $auth->requireRole('librarian', 'admin');
        } elseif ($action === 'request') {
            $auth->requireAccount();
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        switch ($action) {
            case 'login':
                $result = $auth->login($web->string('username', 100), $web->raw('password', 128), $ip);
                if (!$result['ok']) { $web->redirect('login', 'No fue posible iniciar sesión con esas credenciales.'); }
                $web->redirect('dashboard', 'Sesión iniciada.');
            case 'logout': $auth->logout(); $web->redirect('login', 'Sesión cerrada.');
            case 'create_book': $service->createBook($web->string('title', 600), $web->string('author', 400)); $web->redirect('books', 'Libro registrado.');
            case 'edit_book':
                $id = $web->id('book_id'); selfBook($service, $id); $service->updateBook($id, $web->string('title', 600), $web->string('author', 400)); $web->redirect('books', 'Libro actualizado.');
            case 'create_user': $service->createUser($web->string('name', 400), $web->string('email', 400)); $web->redirect('users', 'Usuario de biblioteca registrado.');
            case 'edit_user':
                $id = $web->id('user_id'); selfUser($service, $id); $service->updateUser($id, $web->string('name', 400), $web->string('email', 400)); $web->redirect('users', 'Usuario de biblioteca actualizado.');
            case 'request': $service->requestBook($web->id('book_id')); $web->redirect('requests', 'Solicitud registrada; espere la confirmación del personal.');
            case 'confirm': $service->confirmRequest($web->id('request_id')); $web->redirect('loans', 'Préstamo confirmado.');
            case 'return': $service->returnLoan($web->id('loan_id')); $web->redirect('loans', 'Devolución registrada.');
            case 'create_account':
                $userId = $web->id('user_id'); selfUser($service, $userId); $auth->createAccount($userId, $web->string('username', 100), $web->raw('password', 128), $web->string('role', 16)); $web->redirect('accounts', 'Cuenta aprovisionada.');
            case 'activate': $auth->setAccountActive($web->id('account_id'), true); $web->redirect('accounts', 'Cuenta activada.');
            case 'deactivate': $auth->setAccountActive($web->id('account_id'), false); $web->redirect('accounts', 'Cuenta desactivada.');
        }
    }
    $page = $_GET['page'] ?? 'dashboard';
    if (!is_string($page) || !in_array($page, $pages, true)) { $web->error(404, 'La página solicitada no existe.'); }
    $account = $auth->currentAccount();
    if ($page === 'login') { if ($account !== null) { $web->redirect('dashboard'); } render('login', compact('web')); }
    if ($account === null) { $web->redirect('login'); }
    $data = ['page'=>$page, 'web'=>$web, 'auth'=>$auth, 'service'=>$service, 'account'=>$account, 'notice'=>(is_string($_GET['notice'] ?? null) ? $_GET['notice'] : '')];
    $data['search'] = $web->query('q', $page === 'users' ? 100 : 150);
    $data['dashboard'] = $page === 'dashboard' ? $service->dashboard() : null;
    $data['books'] = $page === 'books' ? $service->listBooks($data['search']) : [];
    $data['users'] = in_array($page, ['users', 'accounts'], true) ? $service->listUsers($data['search']) : [];
    $data['requests'] = $page === 'requests' ? $service->listRequests() : [];
    $data['loans'] = $page === 'loans' ? $service->listLoans() : [];
    $data['accounts'] = $page === 'accounts' ? $auth->listAccounts() : [];
    render('app', $data);
} catch (AuthorizationException) {
    if (($web->method()) === 'POST') { $web->error(403, 'No tiene permisos para realizar esta acción.'); }
    $web->redirect('login');
} catch (ValidationException $error) {
    if ($web->method() === 'POST' && isset($action) && is_string($action) && isset($auth, $service)) {
        $formPage = match ($action) {
            'create_book', 'edit_book' => 'books',
            'create_user', 'edit_user' => 'users',
            'create_account', 'activate', 'deactivate' => 'accounts',
            'request' => 'books',
            'confirm' => 'requests',
            'return' => 'loans',
            default => null,
        };
        $account = $auth->currentAccount();
        if ($formPage !== null && $account !== null) {
            $form = [];
            foreach (['title', 'author', 'name', 'email', 'username', 'role', 'book_id', 'user_id', 'request_id', 'loan_id', 'account_id'] as $field) {
                if (isset($_POST[$field]) && is_string($_POST[$field]) && mb_check_encoding($_POST[$field], 'UTF-8') && strlen($_POST[$field]) <= 600) { $form[$field] = $_POST[$field]; }
            }
            http_response_code(422);
            $data = ['page'=>$formPage, 'web'=>$web, 'auth'=>$auth, 'service'=>$service, 'account'=>$account, 'notice'=>'', 'form'=>$form, 'formError'=>Web::messageFor($error), 'search'=>'', 'dashboard'=>null, 'books'=>[], 'users'=>[], 'requests'=>[], 'loans'=>[], 'accounts'=>[]];
            if ($formPage === 'books') { $data['books'] = $service->listBooks(); }
            if (in_array($formPage, ['users', 'accounts'], true)) { $data['users'] = $service->listUsers(); }
            if ($formPage === 'requests') { $data['requests'] = $service->listRequests(); }
            if ($formPage === 'loans') { $data['loans'] = $service->listLoans(); }
            if ($formPage === 'accounts') { $data['accounts'] = $auth->listAccounts(); }
            render('app', $data);
        }
    }
    $web->error($web->method() === 'POST' ? 422 : 400, Web::messageFor($error));
} catch (Throwable) { $web->error(503, 'El servicio no está disponible temporalmente.'); }

function selfBook(LibraryService $service, int $id): void { foreach ($service->listBooks() as $book) { if ($book['id'] === $id) { return; } } throw new ValidationException('Unknown book.'); }
function selfUser(LibraryService $service, int $id): void { foreach ($service->listUsers() as $user) { if ($user['id'] === $id) { return; } } throw new ValidationException('Unknown library user.'); }
function render(string $view, array $data): never { extract($data, EXTR_SKIP); require __DIR__ . '/views/' . $view . '.php'; exit; }
