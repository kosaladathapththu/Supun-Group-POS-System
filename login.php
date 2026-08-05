<?php
require __DIR__ . '/bootstrap.php';
if (user()) redirect('index.php');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $stmt = $db->prepare('SELECT u.*, r.code role_code, r.name role_name, r.permissions FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.status="active" LIMIT 1');
    $stmt->execute([trim($_POST['email'] ?? '')]);
    $record = $stmt->fetch();
    if ($record && password_verify($_POST['password'] ?? '', $record['password_hash'])) {
        $_SESSION['user'] = ['id'=>(int)$record['id'], 'display_name'=>$record['display_name'], 'role_code'=>$record['role_code'], 'role_name'=>$record['role_name'], 'permissions'=>json_decode($record['permissions'] ?: '[]', true)];
        $_SESSION['last_activity'] = time();
        $db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$record['id']]);
        $db->prepare('INSERT INTO login_history (user_id, login_at, ip_address, user_agent) VALUES (?,NOW(),?,?)')->execute([$record['id'], $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,500)]);
        audit($db, 'login', 'user', (int)$record['id']);
        redirect('index.php');
    }
    $error = 'Invalid email or password.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · <?=e($config['name'])?></title><link rel="stylesheet" href="assets/app.css"></head>
<body class="login-page"><main class="login-shell"><section class="login-copy"><span class="eyebrow">Retail · Wholesale · Finance</span><h1>One clear view of the whole business.</h1><p>Sales, stock, credit, purchasing and profitability—connected in one operating system.</p></section><section class="login-card"><div class="brand-mark">SG</div><h2>Welcome back</h2><p class="muted">Sign in to Supun Group ERP</p><?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?><?php if(isset($_GET['expired'])):?><div class="alert">Your session expired. Please sign in again.</div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf()?>"><label>Email<input type="email" name="email" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="btn primary wide">Sign in</button></form></section></main></body></html>

