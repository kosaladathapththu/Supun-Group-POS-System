<?php
/* shared_nav.php — sidebar navigation for all admin pages */
$current = basename($_SERVER['PHP_SELF']);
?>
<nav class="sidebar">
    <div class="sb-brand">
        <div class="sb-logo"><img src="../supun-logo.png" alt="Supun Group" style="width:100%;height:100%;object-fit:contain;border-radius:8px;background:#fff;padding:2px"></div>
        <div class="sb-brand-text">
            <h2>Supun Group</h2>
            <small>Retail &amp; Wholesale</small>
        </div>
    </div>
    <div class="sb-nav">
        <div class="nav-group-label">Overview</div>
        <a class="nav-item <?php echo in_array($current,['dashboard.php']) ? 'active':''; ?>" href="../dashboard.php">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>

        <div class="nav-group-label">Reports</div>
        <a class="nav-item <?php echo in_array($current,['sales.php','daily_product_sales.php','view_sale.php'])?'active':''; ?>" href="sales.php">
            <i class="fa-solid fa-file-invoice-dollar"></i> Sales Report
        </a>
        <a class="nav-item <?php echo $current=='orders.php'?'active':''; ?>" href="orders.php">
            <i class="fa-solid fa-receipt"></i> All Orders
        </a>

        <a class="nav-item <?php echo $current=='expense_report.php'?'active':''; ?>" href="expense_report.php">
            <i class="fa-solid fa-chart-pie"></i> Expense Report
        </a>

        <div class="nav-group-label">Finance</div>
        <a class="nav-item <?php echo $current=='expenses.php'?'active':''; ?>" href="expenses.php">
            <i class="fa-solid fa-money-bill-trend-up"></i> Expenses
        </a>
        <a class="nav-item <?php echo $current=='advances.php'?'active':''; ?>" href="advances.php">
            <i class="fa-solid fa-wallet"></i> Customer Advances
        </a>

        <div class="nav-group-label">Inventory &amp; Management</div>
        <a class="nav-item <?php echo in_array($current,['products.php','product_import.php'])?'active':''; ?>" href="products.php">
            <i class="fa-solid fa-boxes-stacked"></i> Inventory
        </a>
        <a class="nav-item <?php echo $current=='product_import.php'?'active':''; ?>" href="product_import.php">
            <i class="fa-solid fa-file-arrow-up"></i> Import Inventory
        </a>
        <a class="nav-item <?php echo $current=='categories.php'?'active':''; ?>" href="categories.php">
            <i class="fa-solid fa-tags"></i> Categories
        </a>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?><a class="nav-item <?php echo $current=='users.php'?'active':''; ?>" href="users.php">
            <i class="fa-solid fa-users"></i> Staff / Users
        </a><?php endif; ?>
    </div>
    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-avatar"><?php echo strtoupper(substr($_SESSION["full_name"] ?? "A", 0, 1)); ?></div>
            <div class="sb-user-info">
                <div class="name"><?php echo htmlspecialchars($_SESSION["full_name"] ?? "Admin"); ?></div>
                <div class="role"><?php echo ($_SESSION['role'] ?? '')==='accountant' ? 'Accountant' : 'Owner / Admin'; ?></div>
            </div>
        </div>
        <a href="../pos.php" style="display:flex;align-items:center;justify-content:center;gap:7px;width:100%;padding:8px;background:var(--primary-lt);border:1.5px solid #f9c4a6;border-radius:var(--radius-sm);color:var(--primary);font-size:12px;font-weight:800;font-family:'Nunito',sans-serif;cursor:pointer;text-decoration:none;transition:all .15s;margin-bottom:6px;">
            <i class="fa-solid fa-cash-register"></i> Go to POS
        </a>
        <a href="../logout.php" class="btn-logout-sb">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</nav>
<div class="sidebar-overlay" onclick="toggleAdminMenu(false)"></div>
<script>
function toggleAdminMenu(forceOpen) {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (!sidebar || !overlay) return;
    const open = typeof forceOpen === 'boolean' ? forceOpen : !sidebar.classList.contains('open');
    sidebar.classList.toggle('open', open);
    overlay.classList.toggle('show', open);
    document.body.classList.toggle('menu-open', open);
}
</script>
