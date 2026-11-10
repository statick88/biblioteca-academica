<?php
declare(strict_types=1);

namespace Library;

/** Small HTTP boundary: routing primitives, validated form input, CSRF and escaped rendering. */
final class Web
{
    public function __construct(private readonly Session $session) {}

    public function headers(bool $authPage = false): void
    {
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        if ($authPage) { header('Cache-Control: no-store'); }
    }

    public function csrf(): string { return $this->session->csrfToken(); }
    public function csrfOrFail(): void
    {
        $submitted = $_POST['csrf'] ?? null;
        if (!is_string($submitted) || !mb_check_encoding($submitted, 'UTF-8') || !$this->session->verifyCsrf($submitted)) {
            $this->error(403, 'La solicitud no pudo validarse. Actualice la página e inténtelo nuevamente.');
        }
    }
    public function method(): string { return $_SERVER['REQUEST_METHOD'] ?? 'GET'; }
    public function redirect(string $page, string $message = ''): never
    {
        $allowed = ['login', 'dashboard', 'books', 'users', 'requests', 'loans', 'accounts'];
        if (!in_array($page, $allowed, true)) { $page = 'dashboard'; }
        $query = 'page=' . rawurlencode($page);
        if ($message !== '') { $query .= '&notice=' . rawurlencode($message); }
        header('Location: index.php?' . $query, true, 303); exit;
    }
    public function string(string $name, int $max, bool $required = true): string
    {
        $value = $this->raw($name, $max);
        $value = trim($value);
        if ($required && $value === '') { throw new ValidationException('Invalid form input.'); }
        return $value;
    }
    public function raw(string $name, int $max): string
    {
        $value = $_POST[$name] ?? null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) { $this->error(400, 'La entrada enviada no es válida.'); }
        if (strlen($value) > $max) { throw new ValidationException('Invalid form input.'); }
        return $value;
    }
    public function id(string $name): int
    {
        $value = $_POST[$name] ?? null;
        if (!is_string($value) || !ctype_digit($value) || (int)$value < 1) { $this->error(400, 'El identificador enviado no es válido.'); }
        return (int)$value;
    }
    public function query(string $name, int $max): string
    {
        $value = $_GET[$name] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max) { $this->error(400, 'La búsqueda enviada no es válida.'); }
        return trim($value);
    }
    public function error(int $status, string $message): never
    {
        http_response_code($status);
        $safe = self::escape($message);
        require dirname(__DIR__) . '/views/error.php'; exit;
    }
    public static function escape(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function messageFor(ValidationException $error): string
    {
        return match ($error->getMessage()) {
            'Unknown book.' => 'El libro solicitado ya no está disponible.',
            'Unknown library user.' => 'La persona usuaria ya no está disponible.',
            'Invalid account.' => 'La cuenta indicada no está disponible.',
            default => 'No fue posible completar la operación. Revise los datos e inténtelo nuevamente.',
        };
    }
}
