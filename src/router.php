<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$decoded = rawurldecode($path);
if (str_contains($decoded, '..') || preg_match('#/(?:app|views|bin|tests)(?:/|$)#', $decoded) === 1) {
    http_response_code(403); echo 'Forbidden'; return true;
}
if (preg_match('#^/assets/[a-zA-Z0-9._-]+$#', $decoded) === 1 && is_file(__DIR__ . $decoded)) {
    return false;
}
if ($decoded === '/' || $decoded === '/index.php') { require __DIR__ . '/index.php'; return true; }
http_response_code(404); echo 'Not found'; return true;
