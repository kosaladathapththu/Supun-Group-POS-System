<?php
session_start();
include "../db.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "admin") {
    header("Location: ../login.php");
    exit();
}

$msg = "";
$msg_type = "";

/* ── ADD ── */
if (isset($_POST["add_user"])) {
    $full = trim($_POST["full_name"]);
    $uname = trim($_POST["username"]);
    $pass = $_POST["password"];
    $role = in_array($_POST["role"], ["admin", "accountant", "cashier"])
        ? $_POST["role"]
        : "cashier";
    $stat = (int) ($_POST["status"] ?? 1);

    if ($full && $uname && $pass) {
        $us = $conn->real_escape_string($uname);
        if (
            $conn->query("SELECT user_id FROM users WHERE username='$us'")
                ->num_rows > 0
        ) {
            $msg = "Username '$uname' already exists.";
            $msg_type = "error";
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $fs = $conn->real_escape_string($full);
            $hs = $conn->real_escape_string($hash);
            $rs = $conn->real_escape_string($role);
            $conn->query(
                "INSERT INTO users (full_name, username, password, role, status) VALUES ('$fs','$us','$hs','$rs',$stat)",
            );
            $msg = "User '$uname' created successfully.";
            $msg_type = "success";
        }
    } else {
        $msg = "All fields are required.";
        $msg_type = "error";
    }
}

/* ── EDIT ── */
if (isset($_POST["edit_user"])) {
    $id = (int) $_POST["user_id"];
    $full = trim($_POST["full_name"]);
    $uname = trim($_POST["username"]);
    $role = in_array($_POST["role"], ["admin", "accountant", "cashier"])
        ? $_POST["role"]
        : "cashier";
    $stat = (int) ($_POST["status"] ?? 1);
    $npass = trim($_POST["new_password"] ?? "");

    if ($full && $uname) {
        $fs = $conn->real_escape_string($full);
        $us = $conn->real_escape_string($uname);
        $rs = $conn->real_escape_string($role);

        if (
            $conn->query(
                "SELECT user_id FROM users WHERE username='$us' AND user_id != $id",
            )->num_rows > 0
        ) {
            $msg = "Username already taken.";
            $msg_type = "error";
        } else {
            if ($npass !== "") {
                $hash = $conn->real_escape_string(
                    password_hash($npass, PASSWORD_DEFAULT),
                );
                $conn->query(
                    "UPDATE users SET full_name='$fs', username='$us', password='$hash', role='$rs', status=$stat WHERE user_id=$id",
                );
            } else {
                $conn->query(
                    "UPDATE users SET full_name='$fs', username='$us', role='$rs', status=$stat WHERE user_id=$id",
                );
            }
            $msg = "User updated successfully.";
            $msg_type = "success";
        }
    } else {
        $msg = "Name and username are required.";
        $msg_type = "error";
    }
}

/* ── DELETE ── */
if (isset($_GET["delete"])) {
    $id = (int) $_GET["delete"];
    if ($id == $_SESSION["user_id"]) {
        $msg = "You cannot delete your own account.";
        $msg_type = "error";
    } else {
        $conn->query("DELETE FROM users WHERE user_id=$id");
        $msg = "User deleted.";
        $msg_type = "warning";
    }
}

/* ── TOGGLE ── */
if (isset($_GET["toggle"])) {
    $id = (int) $_GET["toggle"];
    if ($id != $_SESSION["user_id"]) {
        $conn->query(
            "UPDATE users SET status=IF(status=1,0,1) WHERE user_id=$id",
        );
    }
    header("Location: users.php");
    exit();
}

/* ── FETCH ── */
$search = trim($_GET["search"] ?? "");
$sql = "SELECT * FROM users";
if ($search !== "") {
    $ss = $conn->real_escape_string($search);
    $sql .= " WHERE full_name LIKE '%$ss%' OR username LIKE '%$ss%' OR role LIKE '%$ss%'";
}
$sql .= " ORDER BY role ASC, user_id DESC";
$users = $conn->query($sql);

$edit_user = null;
if (isset($_GET["edit"])) {
    $eid = (int) $_GET["edit"];
    $edit_user = $conn
        ->query("SELECT * FROM users WHERE user_id=$eid")
        ->fetch_assoc();
}

$total_users = $conn
    ->query("SELECT COUNT(*) AS v FROM users WHERE status=1")
    ->fetch_assoc()["v"];
$total_admin = $conn
    ->query("SELECT COUNT(*) AS v FROM users WHERE role='admin'")
    ->fetch_assoc()["v"];
$total_accountant = $conn
    ->query("SELECT COUNT(*) AS v FROM users WHERE role='accountant'")
    ->fetch_assoc()["v"];
$total_cashier = $conn
    ->query("SELECT COUNT(*) AS v FROM users WHERE role='cashier'")
    ->fetch_assoc()["v"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff & Users — The La-zogan</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
<?php include "shared_style.php"; ?>
.form-sticky { position: sticky; top: calc(var(--topbar-h) + 16px); }
</style>
</head>
<body>
<?php include "shared_nav.php"; ?>
<div class="main">
<?php include "shared_topbar.php"; ?>
<div class="content">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2 class="page-title-h"><i class="fa-solid fa-users"></i> Staff &amp; Users</h2>
            <p class="page-sub">Admin-only control for accountants, cashiers and administrator accounts</p>
        </div>
    </div>

    <!-- Alert -->
    <?php if ($msg): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo $msg_type == "success"
            ? "fa-circle-check"
            : ($msg_type == "warning"
                ? "fa-triangle-exclamation"
                : "fa-circle-exclamation"); ?>"></i>
        <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <!-- Stat Strip -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;">
        <div class="stat-tile">
            <div class="st-icon" style="background:var(--primary-lt);color:var(--primary);"><i class="fa-solid fa-users"></i></div>
            <div><div class="st-val"><?php echo $total_users; ?></div><div class="st-lbl">Active Users</div></div>
        </div>
        <div class="stat-tile">
            <div class="st-icon" style="background:var(--primary-lt);color:var(--primary);"><i class="fa-solid fa-shield-halved"></i></div>
            <div><div class="st-val"><?php echo $total_admin; ?></div><div class="st-lbl">Owners / Admins</div></div>
        </div>
        <div class="stat-tile">
            <div class="st-icon" style="background:var(--green-lt);color:var(--green);"><i class="fa-solid fa-calculator"></i></div>
            <div><div class="st-val"><?php echo $total_accountant; ?></div><div class="st-lbl">Accountants</div></div>
        </div>
        <div class="stat-tile">
            <div class="st-icon" style="background:var(--indigo-lt);color:var(--indigo);"><i class="fa-solid fa-cash-register"></i></div>
            <div><div class="st-val"><?php echo $total_cashier; ?></div><div class="st-lbl">Cashiers</div></div>
        </div>
    </div>

    <!-- Grid -->
    <div class="two-col" style="align-items:start;">

        <!-- ═══ FORM ═══ -->
        <div class="card form-sticky">
            <div class="card-header">
                <h3>
                    <i class="fa-solid fa-<?php echo $edit_user
                        ? "user-pen"
                        : "user-plus"; ?>"></i>
                    <?php echo $edit_user ? "Edit User" : "Add New User"; ?>
                </h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php if ($edit_user): ?>
                        <input type="hidden" name="user_id" value="<?php echo $edit_user[
                            "user_id"
                        ]; ?>">
                    <?php endif; ?>

                    <div class="field">
                        <label>Full Name</label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-id-card"></i>
                            <input type="text" name="full_name" class="inp" style="padding-left:34px;"
                                   placeholder="e.g. John Silva"
                                   value="<?php echo htmlspecialchars(
                                       $edit_user["full_name"] ?? "",
                                   ); ?>" required>
                        </div>
                    </div>

                    <div class="field">
                        <label>Username</label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-user"></i>
                            <input type="text" name="username" class="inp" style="padding-left:34px;"
                                   placeholder="Login username"
                                   value="<?php echo htmlspecialchars(
                                       $edit_user["username"] ?? "",
                                   ); ?>" required>
                        </div>
                    </div>

                    <?php if (!$edit_user): ?>
                    <div class="field">
                        <label>Password</label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="password" class="inp" style="padding-left:34px;"
                                   placeholder="Set a password" required>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="field">
                        <label>New Password
                            <span style="font-size:10px;color:var(--text-muted);text-transform:none;letter-spacing:0;font-weight:600;">&nbsp;(leave blank to keep current)</span>
                        </label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="new_password" class="inp" style="padding-left:34px;"
                                   placeholder="Leave blank to keep current">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="two-field">
                        <div class="field">
                            <label>Role</label>
                            <select name="role" class="inp">
                                <option value="cashier" <?php echo !$edit_user ||
                                $edit_user["role"] == "cashier"
                                    ? "selected"
                                    : ""; ?>>
                                    Cashier
                                </option>
                                <option value="admin" <?php echo $edit_user &&
                                $edit_user["role"] == "admin"
                                    ? "selected"
                                    : ""; ?>>
                                    Admin (Owner)
                                </option>
                                <option value="accountant" <?php echo $edit_user &&
                                $edit_user["role"] == "accountant"
                                    ? "selected"
                                    : ""; ?>>
                                    Accountant
                                </option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select name="status" class="inp">
                                <option value="1" <?php echo !$edit_user ||
                                $edit_user["status"] == 1
                                    ? "selected"
                                    : ""; ?>>Active</option>
                                <option value="0" <?php echo $edit_user &&
                                $edit_user["status"] == 0
                                    ? "selected"
                                    : ""; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:flex;gap:8px;margin-top:4px;">
                        <button type="submit"
                                name="<?php echo $edit_user
                                    ? "edit_user"
                                    : "add_user"; ?>"
                                class="btn-primary" style="flex:1;justify-content:center;">
                            <i class="fa-solid fa-<?php echo $edit_user
                                ? "floppy-disk"
                                : "user-plus"; ?>"></i>
                            <?php echo $edit_user
                                ? "Update User"
                                : "Add User"; ?>
                        </button>
                        <?php if ($edit_user): ?>
                        <a href="users.php" class="btn-secondary" style="padding:9px 14px;">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══ TABLE ═══ -->
        <div class="card table-card-full">
            <div class="card-header">
                <h3><i class="fa-solid fa-list"></i> All Staff Members</h3>

                <!-- Search -->
                <form method="GET" style="display:flex;gap:6px;align-items:center;">
                    <div style="position:relative;">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;pointer-events:none;"></i>
                        <input type="text" name="search" class="inp"
                               style="padding-left:30px;width:190px;padding-top:7px;padding-bottom:7px;"
                               placeholder="Search name / username…"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <button type="submit" class="btn-primary" style="padding:8px 14px;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>
                    <?php if ($search): ?>
                    <a href="users.php" class="btn-secondary" style="padding:8px 12px;">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users && $users->num_rows > 0):
                            while ($u = $users->fetch_assoc()): ?>
                        <tr>
                            <td style="color:var(--text-muted);font-size:12px;"><?php echo $u[
                                "user_id"
                            ]; ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:9px;">
                                    <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;color:#fff;flex-shrink:0;">
                                        <?php echo strtoupper(
                                            substr($u["full_name"], 0, 1),
                                        ); ?>
                                    </div>
                                    <strong style="color:var(--text);"><?php echo htmlspecialchars(
                                        $u["full_name"],
                                    ); ?></strong>
                                    <?php if (
                                        $u["user_id"] == $_SESSION["user_id"]
                                    ): ?>
                                        <span class="badge b-green" style="font-size:9px;">You</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <code style="background:var(--bg);padding:3px 8px;border-radius:5px;font-size:12px;border:1px solid var(--border);">
                                    <?php echo htmlspecialchars(
                                        $u["username"],
                                    ); ?>
                                </code>
                            </td>
                            <td>
                                <?php if ($u["role"] === "admin"): ?>
                                    <span class="badge b-orange"><i class="fa-solid fa-shield-halved"></i> Owner</span>
                                <?php elseif ($u["role"] === "accountant"): ?>
                                    <span class="badge b-green"><i class="fa-solid fa-calculator"></i> Accountant</span>
                                <?php else: ?>
                                    <span class="badge b-indigo"><i class="fa-solid fa-cash-register"></i> Cashier</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (
                                    $u["user_id"] != $_SESSION["user_id"]
                                ): ?>
                                    <a href="users.php?toggle=<?php echo $u[
                                        "user_id"
                                    ]; ?>" title="Click to toggle" style="text-decoration:none;">
                                <?php endif; ?>
                                    <?php if ($u["status"] == 1): ?>
                                        <span class="badge b-green"><i class="fa-solid fa-circle"></i> Active</span>
                                    <?php else: ?>
                                        <span class="badge b-red"><i class="fa-regular fa-circle"></i> Inactive</span>
                                    <?php endif; ?>
                                <?php if (
                                    $u["user_id"] != $_SESSION["user_id"]
                                ): ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a href="users.php?edit=<?php echo $u[
                                        "user_id"
                                    ]; ?>" class="btn-edit">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <?php if (
                                        $u["user_id"] != $_SESSION["user_id"]
                                    ): ?>
                                    <a href="users.php?delete=<?php echo $u[
                                        "user_id"
                                    ]; ?>"
                                       class="btn-del"
                                       onclick="return confirm('Delete user \'<?php echo addslashes(
                                           htmlspecialchars($u["username"]),
                                       ); ?>\'?')">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile;
                        else:
                             ?>
                        <tr><td colspan="6" class="empty-row">
                            <i class="fa-solid fa-users" style="font-size:22px;color:var(--border-dk);display:block;margin-bottom:8px;"></i>
                            No users found.
                        </td></tr>
                        <?php
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /two-col -->
</div><!-- /content -->
</div><!-- /main -->
</body>
</html>
