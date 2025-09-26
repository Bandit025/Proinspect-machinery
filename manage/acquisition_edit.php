<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1)!==2) { header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id<=0) { header('Location: acquisitions.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$stm = $pdo->prepare("SELECT * FROM acquisitions WHERE acquisition_id=? LIMIT 1");
$stm->execute([$id]); $acq = $stm->fetch(PDO::FETCH_ASSOC);
if (!$acq) { header('Location: acquisitions.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$machines = $pdo->query("SELECT m.machine_id, m.code, b.brand_name, mo.model_name
                         FROM machines m
                         JOIN brands b ON b.brand_id=m.brand_id
                         JOIN models mo ON mo.model_id=m.model_id
                         ORDER BY m.machine_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';

  $machine_id  = (int)($_POST['machine_id'] ?? 0);
  $supplier_id = (int)($_POST['supplier_id'] ?? 0);
  $doc_no      = trim($_POST['doc_no'] ?? '');
  $acquired_at = trim($_POST['acquired_at'] ?? '');
  $base_price  = ($_POST['base_price']  !== '') ? (float)$_POST['base_price']  : null;
  $vat_rate    = ($_POST['vat_rate_pct']!== '') ? (float)$_POST['vat_rate_pct']: 7.00;
  $remark      = trim($_POST['remark'] ?? '');

  if ($machine_id<=0) $errors[]='เลือกรถ';
  if ($supplier_id<=0) $errors[]='เลือกผู้ขาย';
  if ($acquired_at==='') $errors[]='เลือกวันที่ซื้อ';
  if ($base_price===null || $base_price<0) $errors[]='กรอกราคาก่อน VAT ให้ถูกต้อง';
  if ($vat_rate<0) $vat_rate = 0;

  if (!$errors) {
    $vat_amount  = round($base_price * ($vat_rate/100), 2);
    $total       = round($base_price + $vat_amount, 2);

    $upd = $pdo->prepare("UPDATE acquisitions SET
      machine_id=?, supplier_id=?, doc_no=?, acquired_at=?, base_price=?, vat_rate_pct=?, vat_amount=?, total_amount=?, remark=?
      WHERE acquisition_id=?");
    $upd->execute([$machine_id,$supplier_id,$doc_no,$acquired_at,$base_price,$vat_rate,$vat_amount,$total,$remark,$id]);

    header('Location: acquisitions.php?ok=' . urlencode('แก้ไขเอกสารซื้อเรียบร้อย')); exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>แก้ไขเอกสารซื้อ — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=16">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include 'sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>


  <main class="content">
    <div class="page-head">
      <h2 class="page-title">แก้ไขเอกสารซื้อ #<?=$acq['acquisition_id']?></h2>
      <div class="page-sub"><?=htmlspecialchars($acq['doc_no'] ?: '-')?></div>
    </div>

    <?php if ($errors): ?><div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
      <form method="post" id="editForm" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="id"   value="<?=$acq['acquisition_id']?>">

        <label>รถ</label>
        <select class="select" name="machine_id" required>
          <?php foreach($machines as $m): ?>
            <option value="<?=$m['machine_id']?>" <?= (($_POST['machine_id'] ?? $acq['machine_id'])==$m['machine_id'])?'selected':''; ?>>
              <?=htmlspecialchars($m['code'].' — '.$m['brand_name'].' '.$m['model_name'])?>
            </option>
          <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">ผู้ขาย</label>
        <select class="select" name="supplier_id" required>
          <?php foreach($suppliers as $s): ?>
            <option value="<?=$s['supplier_id']?>" <?= (($_POST['supplier_id'] ?? $acq['supplier_id'])==$s['supplier_id'])?'selected':''; ?>>
              <?=htmlspecialchars($s['supplier_name'])?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:10px;">
          <div><label>เลขเอกสาร</label><input class="input" type="text" name="doc_no" value="<?=htmlspecialchars($_POST['doc_no'] ?? $acq['doc_no'])?>"></div>
          <div><label>วันที่ซื้อ</label><input class="input" type="date" name="acquired_at" required value="<?=htmlspecialchars($_POST['acquired_at'] ?? $acq['acquired_at'])?>"></div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div><label>ราคาก่อน VAT</label><input class="input" type="number" step="0.01" name="base_price" required value="<?=htmlspecialchars($_POST['base_price'] ?? $acq['base_price'])?>"></div>
          <div><label>VAT (%)</label><input class="input" type="number" step="0.01" name="vat_rate_pct" value="<?=htmlspecialchars($_POST['vat_rate_pct'] ?? $acq['vat_rate_pct'])?>"></div>
        </div>

        <label style="margin-top:10px;">หมายเหตุ</label>
        <input class="input" type="text" name="remark" value="<?=htmlspecialchars($_POST['remark'] ?? $acq['remark'])?>">

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="acquisitions.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.getElementById('editForm').addEventListener('submit',(e)=>{
  e.preventDefault();
  Swal.fire({icon:'question',title:'ยืนยันการบันทึกการแก้ไข?',showCancelButton:true,confirmButtonText:'บันทึก',cancelButtonText:'ยกเลิก',reverseButtons:true,confirmButtonColor:'#fec201'})
    .then(res=>{ if(res.isConfirmed) e.target.submit(); });
});
</script>
</body>
</html>
