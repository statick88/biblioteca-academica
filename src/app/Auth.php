<?php
declare(strict_types=1);

namespace Library;

use mysqli;
use mysqli_sql_exception;

/** Authentication and database-rechecked role policy. */
final class Auth
{
    private const ROLES = ['reader', 'librarian', 'admin'];
    private const MAX_PASSWORD_BYTES = 72; // bcrypt truncates input after 72 bytes.

    /** @var callable():\DateTimeImmutable */
    private $clock;

    public function __construct(
        private readonly mysqli $database,
        private readonly Session $session,
        ?callable $clock = null,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 20 || $windowSeconds < 60 || $windowSeconds > 86400) {
            throw new \InvalidArgumentException('Invalid authentication rate limit configuration.');
        }
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return array{ok:bool, account:?array{id:int,user_id:int,username:string,role:string,active:bool}} */
    public function login(string $username, string $password, string $ip): array
    {
        $normalized = self::normalizeUsername($username);
        $this->assertIp($ip);
        if ($normalized === null || !$this->passwordHasValidLength($password)) {
            return $this->failedLogin($username, $ip);
        }
        if ($this->isThrottled($normalized, $ip)) {
            return $this->failure();
        }

        $statement = $this->database->prepare('SELECT id, user_id, username, password_hash, role, active FROM accounts WHERE username = ? LIMIT 1');
        $statement->bind_param('s', $normalized);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        if (!is_array($row) || (int) $row['active'] !== 1 || !password_verify($password, $row['password_hash'])) {
            return $this->failedLogin($normalized, $ip);
        }

        $account = $this->safeAccount($row);
        $this->session->login($account['id']);
        return ['ok' => true, 'account' => $account];
    }

    /** @return array{id:int,user_id:int,username:string,role:string,active:bool}|null */
    public function currentAccount(): ?array
    {
        $id = $this->session->accountId();
        if ($id === null) {
            return null;
        }
        $statement = $this->database->prepare('SELECT id, user_id, username, role, active FROM accounts WHERE id = ? LIMIT 1');
        $statement->bind_param('i', $id);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        if (!is_array($row) || (int) $row['active'] !== 1) {
            $this->session->logout();
            return null;
        }
        $this->session->touch();
        return $this->safeAccount($row);
    }

    /** @return array{id:int,user_id:int,username:string,role:string,active:bool} */
    public function requireAccount(): array
    {
        $account = $this->currentAccount();
        if ($account === null) {
            throw new AuthorizationException('Authentication is required.');
        }
        return $account;
    }

    /** @return array{id:int,user_id:int,username:string,role:string,active:bool} */
    public function requireRole(string ...$roles): array
    {
        $account = $this->requireAccount();
        foreach ($roles as $role) {
            if (!in_array($role, self::ROLES, true)) {
                throw new ValidationException('Invalid role.');
            }
        }
        if ($roles === [] || !in_array($account['role'], $roles, true)) {
            throw new AuthorizationException('The current account lacks the required role.');
        }
        return $account;
    }

    public function logout(): void
    {
        $this->session->logout();
    }

    /** @return array{id:int,user_id:int,username:string,role:string,active:bool} */
    public function createAccount(int $userId, string $username, string $password, string $role): array
    {
        $this->requireRole('admin');
        $normalized = self::validatedUsername($username);
        self::assertPassword($password);
        self::assertRole($role);
        if ($userId < 1) {
            throw new ValidationException('Invalid library user.');
        }

        $this->database->begin_transaction();
        try {
            $user = $this->database->prepare('SELECT id_usuario FROM usuarios WHERE id_usuario = ? LIMIT 1 FOR UPDATE');
            $user->bind_param('i', $userId);
            $user->execute();
            if ($user->get_result()->num_rows !== 1) {
                throw new ValidationException('Invalid library user.');
            }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $active = 1;
            $insert = $this->database->prepare('INSERT INTO accounts (user_id, username, password_hash, role, active) VALUES (?, ?, ?, ?, ?)');
            $insert->bind_param('isssi', $userId, $normalized, $hash, $role, $active);
            $insert->execute();
            $id = (int) $this->database->insert_id;
            $this->database->commit();
            return ['id' => $id, 'user_id' => $userId, 'username' => $normalized, 'role' => $role, 'active' => true];
        } catch (mysqli_sql_exception $exception) {
            $this->database->rollback();
            throw new ValidationException('Account username or library user is already linked.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    public function setAccountActive(int $id, bool $active): void
    {
        $this->requireRole('admin');
        if ($id < 1) {
            throw new ValidationException('Invalid account.');
        }
        $this->database->begin_transaction();
        try {
            $target = $this->database->prepare('SELECT id, role, active FROM accounts WHERE id = ? FOR UPDATE');
            $target->bind_param('i', $id);
            $target->execute();
            $row = $target->get_result()->fetch_assoc();
            if (!is_array($row)) {
                throw new ValidationException('Invalid account.');
            }
            if (!$active && $row['role'] === 'admin' && (int) $row['active'] === 1) {
                $admins = $this->database->query("SELECT id FROM accounts WHERE role = 'admin' AND active = 1 FOR UPDATE");
                if ($admins->num_rows <= 1) {
                    throw new ValidationException('At least one active administrator is required.');
                }
            }
            $value = $active ? 1 : 0;
            $update = $this->database->prepare('UPDATE accounts SET active = ? WHERE id = ?');
            $update->bind_param('ii', $value, $id);
            $update->execute();
            $this->database->commit();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /** @return list<array{id:int,user_id:int,username:string,role:string,active:bool}> */
    public function listAccounts(): array
    {
        $this->requireRole('admin');
        $rows = $this->database->query('SELECT id, user_id, username, role, active FROM accounts ORDER BY username')->fetch_all(MYSQLI_ASSOC);
        return array_map(fn(array $row): array => $this->safeAccount($row), $rows);
    }

    public static function validatedUsername(string $username): string
    {
        $normalized = self::normalizeUsername($username);
        if ($normalized === null) {
            throw new ValidationException('Invalid username.');
        }
        return $normalized;
    }

    public static function assertPassword(string $password): void
    {
        $bytes = strlen($password);
        if ($bytes < 12 || $bytes > self::MAX_PASSWORD_BYTES || str_contains($password, "\0")) {
            throw new ValidationException('Password must be 12 to 72 bytes and contain no NUL byte.');
        }
    }

    private static function normalizeUsername(string $username): ?string
    {
        $normalized = strtolower(trim($username));
        return preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/D', $normalized) === 1 ? $normalized : null;
    }

    private static function assertRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new ValidationException('Invalid role.');
        }
    }

    private function passwordHasValidLength(string $password): bool
    {
        return strlen($password) >= 12 && strlen($password) <= self::MAX_PASSWORD_BYTES && !str_contains($password, "\0");
    }

    private function assertIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ValidationException('Invalid client address.');
        }
    }

    private function isThrottled(string $username, string $ip): bool
    {
        [$identifierHash, $ipHash, $window] = $this->rateLimitKey($username, $ip);
        $statement = $this->database->prepare('SELECT attempt_count FROM auth_attempts WHERE identifier_hash = ? AND ip_hash = ? AND window_started_at = ? LIMIT 1');
        $statement->bind_param('sss', $identifierHash, $ipHash, $window);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) && (int) $row['attempt_count'] >= $this->maxAttempts;
    }

    private function failedLogin(string $username, string $ip): array
    {
        $identifier = self::normalizeUsername($username) ?? strtolower(trim($username));
        [$identifierHash, $ipHash, $window] = $this->rateLimitKey($identifier, $ip);
        $maximum = $this->maxAttempts;
        $statement = $this->database->prepare('INSERT INTO auth_attempts (identifier_hash, ip_hash, window_started_at, attempt_count) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE attempt_count = LEAST(attempt_count + 1, ?)');
        $statement->bind_param('sssi', $identifierHash, $ipHash, $window, $maximum);
        $statement->execute();
        return $this->failure();
    }

    /** @return array{0:string,1:string,2:string} */
    private function rateLimitKey(string $username, string $ip): array
    {
        $timestamp = ($this->clock)()->getTimestamp();
        $windowStart = intdiv($timestamp, $this->windowSeconds) * $this->windowSeconds;
        return [hash('sha256', $username), hash('sha256', $ip), gmdate('Y-m-d H:i:s', $windowStart)];
    }

    /** @return array{ok:false,account:null} */
    private function failure(): array
    {
        return ['ok' => false, 'account' => null];
    }

    /** @param array<string,mixed> $row @return array{id:int,user_id:int,username:string,role:string,active:bool} */
    private function safeAccount(array $row): array
    {
        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'username' => (string) $row['username'], 'role' => (string) $row['role'], 'active' => (int) $row['active'] === 1];
    }
}
