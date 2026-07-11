<?php
session_start();
include '../db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "manager"], true)) {
    header("Location: ../login.php"); exit;
}

$msg = ""; $msg_type = "";

/* ── ADD ── */
if (isset($_POST["add_category"])) {
    $name   = trim($conn->real_escape_string($_POST["category_name"]));
    $status = (int)($_POST["status"] ?? 1);
    if ($name !== "") {
        $conn->query("INSERT INTO categories (category_name, status) VALUES ('$name', $status)");
        $msg = "Category added successfully."; $msg_type = "success";
    } else { $msg = "Category name cannot be empty."; $msg_type = "error"; }
}

/* ── EDIT ── */
if (isset($_POST["edit_category"])) {
    $id     = (int)$_POST["edit_id"];
    $name   = trim($conn->real_escape_string($_POST["category_name"]));
    $status = (int)($_POST["status"] ?? 1);
    if ($name !== "") {
        $conn->query("UPDATE categories SET category_name='$name', status=$status WHERE category_id=$id");
        $msg = "Category updated."; $msg_type = "success";
    }
}

/* ── DELETE ── */
if (isset($_GET["delete"])) {
    $id = (int)$_GET["delete"];
    $conn->query("DELETE FROM categories WHERE category_id=$id");
    $msg = "Category deleted."; $msg_type = "warning";
}

/* ── TOGGLE STATUS ── */
if (isset($_GET["toggle"])) {
    $id = (int)$_GET["toggle"];
    $conn->query("UPDATE categories SET status = IF(status=1,0,1) WHERE category_id=$id");
    header("Location: categories.php"); exit;
}

/* ── FETCH ── */
$edit_row = null;
if (isset($_GET["edit"])) {
    $eid = (int)$_GET["edit"];
    $edit_row = $conn->query("SELECT * FROM categories WHERE category_id=$eid")->fetch_assoc();
}
$categories = $conn->query("SELECT * FROM categories ORDER BY category_id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Categories — The La-zogan</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Lora:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
<?php include 'shared_style.php'; ?>
</style>
</head>
<body>
<?php include 'shared_nav.php'; ?>
<div class="main">
<?php include 'shared_topbar.php'; ?>
<div class="content">

  <div class="page-header">
    <div>
      <h2 class="page-title-h"><i class="fa-solid fa-tags"></i> Categories</h2>
      <p class="page-sub">Manage your menu categories</p>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="alert alert-<?php echo $msg_type; ?>">
    <i class="fa-solid <?php echo $msg_type=='success'?'fa-circle-check':($msg_type=='warning'?'fa-triangle-exclamation':'fa-circle-exclamation'); ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
  </div>
  <?php endif; ?>

  <div class="two-col">
    <!-- FORM -->
    <div class="card form-card">
      <div class="card-head">
        <h4><i class="fa-solid <?php echo $edit_row ? 'fa-pen' : 'fa-plus'; ?>"></i>
          <?php echo $edit_row ? 'Edit Category' : 'Add New Category'; ?>
        </h4>
      </div>
      <div class="card-body">
        <form method="POST">
          <?php if ($edit_row): ?>
            <input type="hidden" name="edit_id" value="<?php echo $edit_row['category_id']; ?>">
          <?php endif; ?>
          <div class="field">
            <label>Category Name</label>
            <div class="inp-wrap"><i class="fa-solid fa-tag"></i>
              <input type="text" name="category_name" class="inp"
                     value="<?php echo htmlspecialchars($edit_row['category_name'] ?? ''); ?>"
                     placeholder="e.g. Rice Dishes" required>
            </div>
          </div>
          <div class="field">
            <label>Status</label>
            <select name="status" class="inp" style="padding-left:14px;">
              <option value="1" <?php echo (!$edit_row || $edit_row['status']==1)?'selected':''; ?>>Active</option>
              <option value="0" <?php echo ($edit_row && $edit_row['status']==0)?'selected':''; ?>>Inactive</option>
            </select>
          </div>
          <?php if ($edit_row): ?>
            <button type="submit" name="edit_category" class="btn-primary"><i class="fa-solid fa-save"></i> Update Category</button>
            <a href="categories.php" class="btn-secondary" style="margin-top:8px;"><i class="fa-solid fa-xmark"></i> Cancel</a>
          <?php else: ?>
            <button type="submit" name="add_category" class="btn-primary"><i class="fa-solid fa-plus"></i> Add Category</button>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- TABLE -->
    <div class="card table-card-full">
      <div class="card-head">
        <h4><i class="fa-solid fa-list"></i> All Categories</h4>
        <span class="count-badge"><?php echo $categories->num_rows; ?> total</span>
      </div>
      <table>
        <thead><tr><th>#</th><th>Category Name</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if ($categories->num_rows > 0):
            while ($row = $categories->fetch_assoc()): ?>
          <tr>
            <td><?php echo $row['category_id']; ?></td>
            <td><strong><?php echo htmlspecialchars($row['category_name']); ?></strong></td>
            <td>
              <a href="categories.php?toggle=<?php echo $row['category_id']; ?>" class="status-badge <?php echo $row['status']?'st-active':'st-inactive'; ?>">
                <i class="fa-solid <?php echo $row['status']?'fa-circle-check':'fa-circle-xmark'; ?>"></i>
                <?php echo $row['status']?'Active':'Inactive'; ?>
              </a>
            </td>
            <td>
              <div class="action-btns">
                <a href="categories.php?edit=<?php echo $row['category_id']; ?>" class="btn-edit"><i class="fa-solid fa-pen"></i> Edit</a>
                <a href="categories.php?delete=<?php echo $row['category_id']; ?>" class="btn-del"
                   onclick="return confirm('Delete this category?')"><i class="fa-solid fa-trash"></i> Delete</a>
              </div>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="4" class="empty-row">No categories found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div></div>
</body></html>
