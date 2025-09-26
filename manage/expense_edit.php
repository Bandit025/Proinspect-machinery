<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1)!==2) { header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id<=0) { header('Location: expenses.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$stm = $pdo->prepare("SELECT * FROM machine_expenses WHERE expense_id=? LIMIT 1");
$stm->execute([$id]); $exp = $stm->fetch(PDO::FETCH_ASSOC);
if (!$exp) { header('Location: expenses.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$catMap = [
  'repair'=>'ซ่อม','maintenance'=>'บำรุงรักษา','parts'=>'อะไหล่','transport'=>'ขนส่ง',
  'registration'=>'จดทะเบียน','inspection'=>'ตรวจสภาพ','brokerage'=>'ค่านายหน้า',
  'selling'=>'ค่าใช้จ่ายการขาย','other'=>'อื่น ๆ'
];
function exp_status_label($x){ return [1=>'รอดำเนินการ',2=>'ออก P/O',3=>'ชำระแล้ว',4=>'เสร็จสิ้น',9=>'ยกเลิก'][$x] ?? 'รอดำเนินการ'; }

$machines = $pdo->query("SELECT m.machine_id, m.code, b.brand_name, mo.model_name
                         FROM machines m
                         JOIN brands b ON b.brand_id=m.brand_id
                         JOIN models mo ON mo.model_id=m.model_id
                         ORDER BY m.machine_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';

  $machine_id    = (int)($_POST['machine_id'] ?? 0);
  $supplier_id   = ($_POST['supplier_id'] ?? '')!=='' ? (int)$_POST['supplier_id'] : null;
  $category      = trim($_POST['category'] ?? '');
  $capitalizable = (int)($_POST['capitalizable'] ?? 1);
  $description   = trim($_POST['description'] ?? '');
  $occurred_at   = trim($_POST['occurred_at'] ?? '');
  $qty           = ($_POST['qty'] !== '') ? (float)$_POST['qty'] : 1.00;
  $unit_cost     = ($_POST['unit_cost'] !== '') ? (float)$_POST['unit_cost'] : 0.00;
  $commission    = ($_POST['commission_amt'] !== '') ? (float)$_POST['commission_amt'] : 0.00;
  $status        = (int)($_POST['status'] ?? 1);
  $remark        = trim($_POST['remark'] ?? '');

  if ($machine_id<=0) $errors[]='เลือกรถ';
  if (!isset($catMap[$category])) $errors[]='เลือกประเภทให้ถูกต้อง';
  if ($occurred_at==='') $errors[]='เลือกวันที่เกิดรายการ';
  if ($qty<=0) $errors[]='จำนวนต้องมากกว่า 0';
  if ($unit_cost<0) $unit_cost = 0;
  if ($commission<0) $commission = 0;

  if (!$errors) {
    $upd = $pdo->prepare("UPDATE machine_expenses SET
      machine_id=?, supplier_id=?, category=?, capitalizable=?, description=?, occurred_at=?, qty=?, unit_cost=?, commission_amt=?, status=?, remark=?
      WHERE expense_id=?");
    $upd->execute([$machine_id,$supplier_id,$category,$capitalizable,$description,$occurred_at,$qty,$unit_cost,$commission,$status,$remark,$id]);

    header('Location: expenses.php?ok=' . urlencode('แก้ไขค่าใช้จ่ายเรียบร้อย')); exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>แก้ไขค่าใช้จ่าย — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=19">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function calcPreview(){
  const qty = parseFloat(document.querySelector('[name="qty"]').value||'0');
  const unit = parseFloat(document.querySelector('[name="unit_cost"]').value||'0');
  const comm = parseFloat(document.querySelector('[name="commission_amt"]').value||'0');
  const total = (qty*unit)+comm;
  document.getElementById('sum').textContent = isNaN(total)? '-' : total.toFixed(2);
}
</script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">แก้ไขค่าใช้จ่าย #<?=$exp['expense_id']?></h2>
      <div class="page-sub"><?=htmlspecialchars($exp['description'] ?: $exp['occurred_at'])?></div>
    </div>

    <?php if ($errors): ?><div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
      <form method="post" id="editForm" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="id"   value="<?=$exp['expense_id']?>">

        <label>รถ</label>
        <select class="select" name="machine_id" required>
          <?php foreach($machines as $m): ?>
            <option value="<?=$m['machine_id']?>" <?= (($_POST['machine_id'] ?? $exp['machine_id'])==$m['machine_id'])?'selected':''; ?>>
              <?=htmlspecialchars($m['code'].' — '.$m['brand_name'].' '.$m['model_name'])?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>ผู้ขาย/ผู้รับจ้าง (ไม่บังคับ)</label>
            <select class="select" name="supplier_id">
              <option value="">— ไม่ระบุ —</option>
              <?php foreach($suppliers as $s): ?>
                <option value="<?=$s['supplier_id']?>" <?= (($_POST['supplier_id'] ?? $exp['supplier_id'])==$s['supplier_id'])?'selected':''; ?>>
                  <?=htmlspecialchars($s['supplier_name'])?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>ประเภท</label>
            <select class="select" name="category" required>
              <?php foreach($catMap as $k=>$v): ?>
                <option value="<?=$k?>" <?= (($_POST['category'] ?? $exp['category'])===$k)?'selected':''; ?>><?=$v?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>เข้าทุนหรือไม่</label>
            <?php $capSel = $_POST['capitalizable'] ?? $exp['capitalizable']; ?>
            <select class="select" name="capitalizable">
              <option value="1" <?=$capSel==1?'selected':'';?>>เข้าทุน</option>
              <option value="0" <?=$capSel==0?'selected':'';?>>ค่าใช้จ่ายงวด</option>
            </select>
          </div>
          <div>
            <label>วันที่เกิดรายการ</label>
            <input class="input" type="date" name="occurred_at" required value="<?=htmlspecialchars($_POST['occurred_at'] ?? $exp['occurred_at'])?>">
          </div>
        </div>

        <label style="margin-top:10px;">คำอธิบาย</label>
        <input class="input" type="text" name="description" value="<?=htmlspecialchars($_POST['description'] ?? $exp['description'])?>">

        <div class="row" style="margin-top:10px;">
          <div><label>จำนวน (Qty)</label><input class="input" type="number" step="0.01" name="qty" value="<?=htmlspecialchars($_POST['qty'] ?? $exp['qty'])?>" oninput="calcPreview()"></div>
          <div><label>ราคา/หน่วย</label><input class="input" type="number" step="0.01" name="unit_cost" value="<?=htmlspecialchars($_POST['unit_cost'] ?? $exp['unit_cost'])?>" oninput="calcPreview()"></div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div><label>ค่านายหน้า (ถ้ามี)</label><input class="input" type="number" step="0.01" name="commission_amt" value="<?=htmlspecialchars($_POST['commission_amt'] ?? $exp['commission_amt'])?>" oninput="calcPreview()"></div>
          <div>
            <label>สถานะ</label>
            <?php $stSel = (int)($_POST['status'] ?? $exp['status']); ?>
            <select class="select" name="status">
              <?php foreach([1,2,3,4,9] as $opt): ?>
                <option value="<?=$opt?>" <?=$stSel===$opt?'selected':'';?>><?=exp_status_label($opt)?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <?php
          $pv = ((float)($exp['qty']))*((float)($exp['unit_cost']))+((float)($exp['commission_amt']));
        ?>
        <div style="margin-top:8px;" class="muted">ยอดรวม (คำนวณ): ฿<span id="sum"><?=number_format($pv,2)?></span></div>

        <label style="margin-top:10px;">หมายเหตุ</label>
        <input class="input" type="text" name="remark" value="<?=htmlspecialchars($_POST['remark'] ?? $exp['remark'])?>">

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="expenses.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', calcPreview);
document.getElementById('editForm').addEventListener('submit',(e)=>{
  e.preventDefault();
  Swal.fire({icon:'question',title:'ยืนยันการบันทึกการแก้ไข?',showCancelButton:true,confirmButtonText:'บันทึก',cancelButtonText:'ยกเลิก',reverseButtons:true,confirmButtonColor:'#fec201'})
    .then(res=>{ if(res.isConfirmed) e.target.submit(); });
});
</script>
</body>
</html>
