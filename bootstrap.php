<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
session_name('SUPUN_ERP');
session_start();

try {
    $db = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(503);
    exit('Database unavailable. Import database/schema.sql and configure .env.');
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money(float|int|string|null $value): string { return 'Rs. ' . number_format((float)$value, 2); }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) { http_response_code(419); exit('Session expired. Please try again.'); } }
function flash(string $type, string $message): void { $_SESSION['flash'] = compact('type', 'message'); }
function take_flash(): ?array { $value = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $value; }
function user(): ?array { return $_SESSION['user'] ?? null; }
function can(string $permission): bool {
    $u = user();
    if (!$u) return false;
    if ($u['role_code'] === 'main_admin') return true;
    return in_array($permission, $u['permissions'] ?? [], true);
}
function require_auth(): void {
    global $config;
    if (!user()) redirect('login.php');
    if (time() - ($_SESSION['last_activity'] ?? time()) > $config['session_timeout']) { session_unset(); session_destroy(); redirect('login.php?expired=1'); }
    $_SESSION['last_activity'] = time();
}
function audit(PDO $db, string $action, string $entity, ?int $entityId = null, mixed $old = null, mixed $new = null): void {
    $u = user();
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, actor_name, action_type, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$u['id'] ?? null, $u['display_name'] ?? 'System', $action, $entity, $entityId, $old === null ? null : json_encode($old), $new === null ? null : json_encode($new), $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
}

