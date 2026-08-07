<?php
require __DIR__.'/bootstrap.php';
require_auth();
if(!can('customers.view')){http_response_code(403);exit('Forbidden');}
require __DIR__.'/partials.php';

if($_SERVER['REQUEST_METHOD']==='POST'&&can('customers.manage')){
    verify_csrf();
    $code=trim($_POST['customer_code']);
    $data=[$code,trim($_POST['name']),trim($_POST['business_name']),trim($_POST['phone']),trim($_POST['email']),trim($_POST['address']),$_POST['customer_type'],isset($_POST['credit_enabled'])?1:0,$_POST['default_payment_period']];
    $stmt=$db->prepare('INSERT INTO customers(customer_code,name,business_name,phone,email,address,customer_type,credit_enabled,default_payment_period) VALUES(?,?,?,?,?,?,?,?,?)');
    $stmt->execute($data);
    $id=(int)$db->lastInsertId();
    audit($db,'create','customer',$id,null,$data);
    flash('success','Customer account created.');
    redirect('customers.php');
}

$q=trim($_GET['q']??'');
$like="%$q%";
$stmt=$db->prepare('SELECT * FROM customers WHERE status="active" AND (name LIKE ? OR customer_code LIKE ? OR phone LIKE ?) ORDER BY id DESC');
$stmt->execute([$like,$like,$like]);
$rows=$stmt->fetchAll();
page_start('Customers','customers.php');
?>
<div class="toolbar">
    <form><input name="q" value="<?=e($q)?>" placeholder="Search customer, code or phone"><button class="btn secondary">Search</button></form>
    <?php if(can('customers.manage')):?><button type="button" class="btn primary" id="open-customer-form">+ Add Customer</button><?php endif;?>
</div>
<div class="customers-register">
<section class="panel table-panel">
    <div class="panel-head"><div><span class="eyebrow">Accounts receivable</span><h3><?=count($rows)?> customer accounts</h3></div></div>
    <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Contact</th><th>Type</th><th>Credit</th><th class="right">Outstanding</th></tr></thead><tbody>
    <?php foreach($rows as $r):?><tr>
        <td><a href="customer_view.php?id=<?=$r['id']?>"><b><?=e($r['name'])?></b></a><?php if(can('customers.manage')):?> <a class="row-edit" href="edit_record.php?type=customer&id=<?=$r['id']?>">Edit</a><?php endif;?><br><span class="muted"><?=e($r['customer_code'])?><?=!empty($r['business_name'])?' · '.e($r['business_name']):''?></span></td>
        <td><?=e($r['phone']?:'—')?></td><td><span class="tag"><?=e($r['customer_type'])?></span></td><td><?=!empty($r['credit_enabled'])?'<span class="status active">Enabled</span>':'Disabled'?></td><td class="right customer-credit-due <?=$r['outstanding']>0?'has-credit-due':'no-credit-due'?>"><b><?=money($r['outstanding'])?></b><?php if($r['outstanding']>0):?><small>Credit Due</small><?php endif;?></td>
    </tr><?php endforeach;?>
    </tbody></table></div>
</section>
</div>
<?php if(can('customers.manage')):?>
<div class="customer-form-modal" id="customer-form-modal" hidden>
<section class="customer-form-dialog" role="dialog" aria-modal="true" aria-labelledby="customer-form-title">
    <button type="button" class="icon-button customer-form-close" aria-label="Close">×</button>
    <div class="panel-head"><div><span class="eyebrow">New account</span><h3 id="customer-form-title">Add customer</h3><p class="muted">Enter the customer details, then create the account.</p></div></div>
    <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?=csrf()?>">
        <label>Customer code<input name="customer_code" required></label><label>Phone<input name="phone"></label>
        <label class="full">Customer name<input name="name" required></label><label class="full">Business / shop<input name="business_name"></label>
        <label>Email<input type="email" name="email"></label><label>Customer type<select name="customer_type"><option value="retail">Retail</option><option value="wholesale">Wholesale</option><option value="both">Retail & wholesale</option></select></label>
        <label class="full">Address<textarea name="address" rows="2"></textarea></label>
        <label>Default period<select name="default_payment_period"><option value="7_days">7 days</option><option value="14_days">14 days</option><option value="30_days" selected>30 days</option><option value="end_of_month">End of month</option><option value="custom">Custom</option></select></label>
        <label class="customer-credit-choice"><input type="checkbox" name="credit_enabled"><span><b>Enable credit sales</b><small>Allow this customer to buy now and pay later.</small></span></label>
        <button class="btn primary full">Create Customer</button>
    </form>
</section>
</div>
<?php endif;?>
<?php page_end();
