<?php
declare(strict_types=1);

/**
 * Shared application bootstrap for HTTP and CLI entry points.
 *
 * Authentication deliberately does not start a session here; that policy belongs
 * to the forthcoming Auth unit so commands and anonymous HTTP requests stay
 * side-effect free.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Library\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    if ($relativeClass === '' || str_contains($relativeClass, '..')) {
        return;
    }

    $path = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
