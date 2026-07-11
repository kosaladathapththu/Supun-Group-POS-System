<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php include 'shared_style.php'; ?>
    <style>
        .btn-danger{
            display:inline-flex;
            align-items:center;
            gap:7px;
            padding:9px 14px;
            border-radius:8px;
            font-size:13px;
            font-weight:800;
            text-decoration:none;
            border:1.5px solid #fca5a5;
            background:#fef2f2;
            color:#dc2626;
            cursor:pointer;
        }
        .btn-danger:hover{
            background:#dc2626;
            color:#fff;
            border-color:#dc2626;
        }
        .sw{
            display:flex;
            align-items:center;
            gap:8px;
        }
    </style>
</head>
<body>

<?php include 'shared_nav.php'; ?>

<div class="main">
    <?php include 'shared_topbar.php'; ?>

    <div class="content">