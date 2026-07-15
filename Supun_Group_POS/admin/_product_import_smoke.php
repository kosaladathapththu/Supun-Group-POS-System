<?php
chdir(__DIR__);
include '../db.php';
include 'product_import_helpers.php';
$rows=readProductXlsx('C:/Users/ASUS/Downloads/import_new_products (2).xlsx');
if(($rows[0][2]??'')!=='description') throw new RuntimeException('Provided XLSX could not be read correctly.');
session_start();$_SESSION['user_id']=1;$_SESSION['role']='admin';$_SESSION['full_name']='Smoke Test';$_SERVER['PHP_SELF']='/admin/product_import.php';
ob_start();include 'product_import.php';ob_end_clean();
echo "XLSX parser and product import page passed\n";
