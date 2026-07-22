<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','accountant'], true)) {
    header('Location: ../login.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Order Installments</title>
    <style>
        <?php include 'shared_style.php'; ?>
        .advance-frame-wrap{width:100%;max-width:100%;height:calc(100vh - 102px);min-height:650px;background:#f6f7fb;overflow:hidden}
        .advance-frame{display:block;width:100%;max-width:100%;height:100%;border:0;background:#f6f7fb;overflow-x:hidden}
        @media(max-width:760px){.advance-frame-wrap{height:calc(100vh - 86px);min-height:560px}}
    </style>
</head>
<body>
<?php include 'shared_nav.php'; ?>
<main class="main">
    <?php include 'shared_topbar.php'; ?>
    <div class="advance-frame-wrap">
        <iframe class="advance-frame" src="../installment_payments.php?embedded=1" title="Order installment payments"></iframe>
    </div>
</main>
</body>
</html>
