<?php
session_start();
include '../db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "manager"], true)) {
    header("Location: ../login.php"); exit;
}

$msg = ""; $msg_type = "";

/* ── ADD ── */
if (isset($_POST["add_category"])) {
    $name   = trim($_POST["category_name"]);
    $status = (int)($_POST["status"] ?? 1);
    if ($name !== "") {
        $find=$conn->prepare("SELECT category_id FROM categories WHERE LOWER(category_name)=LOWER(?) LIMIT 1");$find->bind_param('s',$name);$find->execute();$existing=$find->get_result()->fetch_assoc();$find->close();
        if($existing){$msg="That category already exists.";$msg_type="warning";}
        else{$add=$conn->prepare("INSERT INTO categories (category_name,status) VALUES (?,?)");$add->bind_param('si',$name,$status);if($add->execute()){$msg="Category added successfully.";$msg_type="success";}else{$msg="Category could not be added: ".$add->error;$msg_type="error";}$add->close();}
    } else { $msg = "Category name cannot be empty."; $msg_type = "error"; }
}

/* ── EDIT ── */
if (isset($_POST["edit_category"])) {
    $id     = (int)$_POST["edit_id"];
    $name   = trim($_POST["category_name"]);
    $status = (int)($_POST["status"] ?? 1);
    if ($name !== "") {
        $conn->begin_transaction();
        try{
            $find=$conn->prepare("SELECT category_id FROM categories WHERE LOWER(category_name)=LOWER(?) AND category_id<>? LIMIT 1");$find->bind_param('si',$name,$id);$find->execute();$duplicate=$find->get_result()->fetch_assoc();$find->close();
            if($duplicate){$target=(int)$duplicate['category_id'];$move=$conn->prepare("UPDATE products SET category_id=? WHERE category_id=?");$move->bind_param('ii',$target,$id);$move->execute();$moved=$move->affected_rows;$move->close();$delete=$conn->prepare("DELETE FROM categories WHERE category_id=?");$delete->bind_param('i',$id);if(!$delete->execute())throw new RuntimeException($delete->error);$delete->close();$activate=$conn->prepare("UPDATE categories SET status=? WHERE category_id=?");$activate->bind_param('ii',$status,$target);$activate->execute();$activate->close();$conn->commit();$msg="Categories merged successfully. $moved product(s) moved to '$name'.";$msg_type="success";}
            else{$update=$conn->prepare("UPDATE categories SET category_name=?,status=? WHERE category_id=?");$update->bind_param('sii',$name,$status,$id);if(!$update->execute())throw new RuntimeException($update->error);if($update->affected_rows===0){$check=$conn->prepare("SELECT category_id FROM categories WHERE category_id=?");$check->bind_param('i',$id);$check->execute();if(!$check->get_result()->fetch_assoc())throw new RuntimeException('Category no longer exists.');$check->close();}$update->close();$conn->commit();$msg="Category updated successfully.";$msg_type="success";}
        }catch(Throwable $e){$conn->rollback();$msg="Category was not updated: ".$e->getMessage();$msg_type="error";}
    }else{$msg="Category name cannot be empty.";$msg_type="error";}
}

/* ── DELETE ── */
if (isset($_GET["delete"])) {
    $id = (int)$_GET["delete"];
    $conn->begin_transaction();
    try{
        $categoryCheck=$conn->prepare("SELECT category_name FROM categories WHERE category_id=? FOR UPDATE");$categoryCheck->bind_param('i',$id);$categoryCheck->execute();$category=$categoryCheck->get_result()->fetch_assoc();$categoryCheck->close();if(!$category)throw new RuntimeException('Category no longer exists.');
        if(strcasecmp($category['category_name'],'Uncategorized')===0)throw new RuntimeException('The Uncategorized category is required as a safe destination and cannot be deleted.');
        $count=$conn->prepare("SELECT COUNT(*) total FROM products WHERE category_id=?");$count->bind_param('i',$id);$count->execute();$used=(int)$count->get_result()->fetch_assoc()['total'];$count->close();
        if($used>0){$findFallback=$conn->query("SELECT category_id FROM categories WHERE LOWER(category_name)='uncategorized' LIMIT 1")->fetch_assoc();if($findFallback){$fallbackId=(int)$findFallback['category_id'];}else{$conn->query("INSERT INTO categories(category_name,status) VALUES('Uncategorized',1)");$fallbackId=$conn->insert_id;}$move=$conn->prepare("UPDATE products SET category_id=? WHERE category_id=?");$move->bind_param('ii',$fallbackId,$id);if(!$move->execute())throw new RuntimeException($move->error);$move->close();}
        $delete=$conn->prepare("DELETE FROM categories WHERE category_id=?");$delete->bind_param('i',$id);if(!$delete->execute()||$delete->affected_rows===0)throw new RuntimeException($delete->error?:'Category was not deleted.');$delete->close();$conn->commit();
        $msg=$used>0?"Category deleted successfully. $used product(s) were moved to Uncategorized.":"Category deleted successfully.";$msg_type="success";
    }catch(Throwable $e){$conn->rollback();$msg="Category was not deleted: ".$e->getMessage();$msg_type="error";}
}

/* ── TOGGLE STATUS ── */
if (isset($_GET["toggle"])) {
    $id = (int)$_GET["toggle"];
    $toggle=$conn->prepare("UPDATE categories SET status=IF(status=1,0,1) WHERE category_id=?");$toggle->bind_param('i',$id);$toggle->execute();$toggle->close();
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
