<?php
declare(strict_types=1);

namespace Library;

/** Server-side session state with an inactivity bound and reusable CSRF helpers. */
final class Session
{
    /** @var callable():\DateTimeImmutable */
    private $clock;

    public function __construct(
        private readonly int $timeoutSeconds = 1800,
        private readonly bool $secureCookies = true,
        ?callable $clock = null,
    ) {
        if ($timeoutSeconds < 60 || $timeoutSeconds > 86400) {
            throw new \InvalidArgumentException('Session timeout is outside the permitted range.');
        }
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function begin(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->start();
        }
        if ($this->expireIfIdle()) {
            // Destroyed session IDs/tokens must never be resumed after idle expiry.
            $this->start();
            session_regenerate_id(true);
        }
    }

    public function login(int $accountId): void
    {
        $this->begin();
        session_regenerate_id(true);
        $_SESSION = [
            'account_id' => $accountId,
            'last_activity' => $this->now()->getTimestamp(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
    }

    public function accountId(): ?int
    {
        $this->begin();
        $value = $_SESSION['account_id'] ?? null;
        return is_int($value) && $value > 0 ? $value : null;
    }

    public function touch(): void
    {
        $this->begin();
        if (isset($_SESSION['account_id'])) {
            $_SESSION['last_activity'] = $this->now()->getTimestamp();
        }
    }

    public function csrfToken(): string
    {
        $this->begin();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public function verifyCsrf(?string $submitted): bool
    {
        $this->begin();
        $token = $_SESSION['csrf'] ?? null;
        return is_string($submitted) && is_string($token) && hash_equals($token, $submitted);
    }

    public function logout(): void
    {
        $this->begin();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    private function expireIfIdle(): bool
    {
        $lastActivity = $_SESSION['last_activity'] ?? null;
        if (is_int($lastActivity) && $this->now()->getTimestamp() - $lastActivity > $this->timeoutSeconds) {
            $_SESSION = [];
            session_destroy();
            return true;
        }
        return false;
    }

    private function start(): void
    {
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }
}
