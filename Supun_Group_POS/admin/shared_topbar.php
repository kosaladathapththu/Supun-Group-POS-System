<?php
/* shared_topbar.php — top bar for all admin pages */
$current = basename($_SERVER['PHP_SELF']);
$titles  = [
    'categories.php'  => 'Categories',
    'users.php'       => 'Staff & Users',
    'orders.php'      => 'All Orders',
    'sales.php'       => 'Sales Report',
    'products.php'    => 'Products',
    'product_import.php' => 'Bulk Product Import',
    'daily_product_sales.php' => 'Product Sales Report',
    'view_sale.php'   => 'Sale Details',
    'expenses.php'    => 'Expenses',
    'expense_report.php' => 'Expense Report',
    'purchases.php' => 'Purchasing',
    'purchase_view.php' => 'Purchase Details',
    'purchase_import.php' => 'Purchase Import',
    'suppliers.php' => 'Suppliers',
];
$page_title = $titles[$current] ?? 'Admin';
?>
<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="mobile-menu-btn" onclick="toggleAdminMenu()" aria-label="Open navigation">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div>
            <div class="page-title-tb"><?php echo $page_title; ?></div>
            <div class="breadcrumb">
                <i class="fa-solid fa-house"></i>
                <a href="../dashboard.php" style="color:var(--primary);text-decoration:none;font-weight:800;">Dashboard</a>
                <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                <?php echo $page_title; ?>
            </div>
        </div>
    </div>
    <div class="topbar-right">
        <div class="date-badge"><i class="fa-regular fa-calendar"></i><?php echo date('d M Y'); ?></div>
        <a href="../pos.php" class="btn-primary"><i class="fa-solid fa-cash-register"></i> Go to POS</a>
    </div>
</div>
