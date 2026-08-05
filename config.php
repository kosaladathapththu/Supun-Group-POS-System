<?php
declare(strict_types=1);

$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $_ENV[$key] = trim($value, "\"'");
    }
}

function env(string $key, string $default = ''): string { return $_ENV[$key] ?? $default; }

return [
    'name' => env('APP_NAME', 'Supun Group ERP'),
    'url' => rtrim(env('APP_URL', ''), '/'),
    'session_timeout' => (int) env('SESSION_TIMEOUT', '1800'),
    'db' => [
        'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_NAME', 'supun_group_erp')),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
    ],
];

