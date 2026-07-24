<?php
function handleQuickSupplier(mysqli $conn): array
{
    if (!isset($_POST["quick_add_supplier"])) {
        return ["handled" => false, "id" => 0, "message" => "", "type" => ""];
    }
    $name = trim($_POST["quick_supplier_name"] ?? "");
    $code = trim($_POST["quick_supplier_code"] ?? "");
    $phone = trim($_POST["quick_supplier_phone"] ?? "");
    $contact = trim($_POST["quick_contact_person"] ?? "");
    if ($name === "") {
        return [
            "handled" => true,
            "id" => 0,
            "message" => "Supplier name is required.",
            "type" => "error",
        ];
    }
    try {
        $s = $conn->prepare(
            "INSERT INTO suppliers (supplier_code,supplier_name,contact_person,phone) VALUES (NULLIF(?,''),?,?,?)",
        );
        $s->bind_param("ssss", $code, $name, $contact, $phone);
        $s->execute();
        $id = $conn->insert_id;
        $s->close();
        return [
            "handled" => true,
            "id" => $id,
            "message" => "Supplier added and selected for this purchase.",
            "type" => "success",
        ];
    } catch (Throwable $e) {
        return [
            "handled" => true,
            "id" => 0,
            "message" =>
                $e->getCode() === 1062
                    ? "That supplier code already exists."
                    : $e->getMessage(),
            "type" => "error",
        ];
    }
}
function renderQuickSupplierForm(): void
{
    ?>
<details class="card quick-supplier no-print"><summary><i class="fa-solid fa-truck-field"></i> Add a New Supplier Here</summary><form method="post" class="quick-supplier-grid"><div class="field"><label>Supplier Name *</label><input class="inp" name="quick_supplier_name" required placeholder="Supplier or company name"></div><div class="field"><label>Supplier Code</label><input class="inp" name="quick_supplier_code" placeholder="SUP-001"></div><div class="field"><label>Contact Person</label><input class="inp" name="quick_contact_person"></div><div class="field"><label>Phone</label><input class="inp" name="quick_supplier_phone"></div><button class="btn-primary" name="quick_add_supplier"><i class="fa-solid fa-plus"></i> Add Supplier</button></form></details>
<?php
}
