<?php
require __DIR__ . '/bootstrap.php';
if (user()) { $db->prepare('UPDATE login_history SET logout_at=NOW() WHERE user_id=? AND logout_at IS NULL ORDER BY id DESC LIMIT 1')->execute([user()['id']]); audit($db, 'logout', 'user', user()['id']); }
session_unset(); session_destroy(); redirect('login.php');

