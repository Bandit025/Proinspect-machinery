<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1)!==2) { header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id<=0) { header('Location: sales.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$stm = $pdo->prepare("SELECT * FROM sales WHERE sale_id=? LIMIT 1");
$stm->execute([$id]); $sale = $stm->fetch(PDO::FETCH_ASSOC);
if (!$sale) { header('Location: sales.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$machines = $pdo->query("SELECT m.machine_id, m.code, b.brand_name, mo.model_name
                         FROM machines m
                         JOIN brands b ON b.brand_id=m.brand_id
                         JOIN models mo ON mo.model_id=m.model_id
                         ORDER BY m.machine_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query("SELECT customer_id, customer_name FROM customers ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

function calc_total($base,$disc,$rate){
  $taxable = max(0, (float)$base - (float)$disc);
  $vat = round($taxable * ((float)$rate/100), 2);
  return round($taxable + $vat, 2);
}

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';

  $machine_id    = (int)($_POST['machine_id'] ?? 0);
  $customer_id   = (int)($_POST['customer_id'] ?? 0);
  $doc_no        = trim($_POST['doc_no'] ?? '');
  $sold_at       = trim($_POST['sold_at'] ?? '');
  $sale_price    = ($_POST['sale_price']   !== '') ? (float)$_POST['sale_price']   : null;
  $discount_amt  = ($_POST['discount_amt'] !== '') ? (float)$_POST['discount_amt'] : 0.00;
  $vat_rate_pct  = ($_POST['vat_rate_pct'] !== '') ? (float)$_POST['vat_rate_pct'] : 7.00;
  $commission    = ($_POST['commission_amt'] !== '') ? (float)$_POST['commission_amt'] : 0.00;
  $status        = (int)($_POST['status'] ?? 1);
  $remark        = trim($_POST['remark'] ?? '');

  if ($machine_id<=0) $errors[]='เลือกรถ';
  if ($customer_id<=0) $errors[]='เลือกลูกค้า';
  if ($sold_at==='') $errors[]='เลือกวันที่ขาย';
  if ($sale_price===null || $sale_price<0) $errors[]='กรอกราคาก่อน VAT ให้ถูกต้อง';
  if ($discount_amt<0) $discount_amt = 0;
  if ($vat_rate_pct<0) $vat_rate_pct = 0;

  if (!$errors) {
    $total = calc_total($sale_price, $discount_amt, $vat_rate_pct);

    $upd = $pdo->prepare("UPDATE sales SET
      machine_id=?, customer_id=?, doc_no=?, sold_at=?, sale_price=?, discount_amt=?, vat_rate_pct=?, total_amount=?, commission_amt=?, status=?, remark=?
      WHERE sale_id=?");
    $upd->execute([$machine_id,$customer_id,$doc_no,$sold_at,$sale_price,$discount_amt,$vat_rate_pct,$total,$commission,$status,$remark,$id]);

    header('Location: sales.php?ok=' . urlencode('แก้ไขเอกสารขายเรียบร้อย')); exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>แก้ไขเอกสารขาย — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=17">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">แก้ไขเอกสารขาย #<?=$sale['sale_id']?></h2>
      <div class="page-sub"><?=htmlspecialchars($sale['doc_no'] ?: '-')?></div>
    </div>

    <?php if ($errors): ?><div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
      <form method="post" id="editForm" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="id"   value="<?=$sale['sale_id']?>">

        <label>รถ</label>
        <select class="select" name="machine_id" required>
          <?php foreach($machines as $m): ?>
            <option value="<?=$m['machine_id']?>" <?= (($_POST['machine_id'] ?? $sale['machine_id'])==$m['machine_id'])?'selected':''; ?>>
              <?=htmlspecialchars($m['code'].' — '.$m['brand_name'].' '.$m['model_name'])?>
            </option>
          <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">ลูกค้า</label>
        <select class="select" name="customer_id" required>
          <?php foreach($customers as $c): ?>
            <option value="<?=$c['customer_id']?>" <?= (($_POST['customer_id'] ?? $sale['customer_id'])==$c['customer_id'])?'selected':''; ?>>
              <?=htmlspecialchars($c['customer_name'])?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:10px;">
          <div><label>เลขเอกสาร</label><input class="input" type="text" name="doc_no" value="<?=htmlspecialchars($_POST['doc_no'] ?? $sale['doc_no'])?>"></div>
          <div><label>วันที่ขาย</label><input class="input" type="date" name="sold_at" required value="<?=htmlspecialchars($_POST['sold_at'] ?? $sale['sold_at'])?>"></div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div><label>ราคาก่อน VAT</label><input class="input" type="number" step="0.01" name="sale_price" required value="<?=htmlspecialchars($_POST['sale_price'] ?? $sale['sale_price'])?>"></div>
          <div><label>ส่วนลด</label><input class="input" type="number" step="0.01" name="discount_amt" value="<?=htmlspecialchars($_POST['discount_amt'] ?? $sale['discount_amt'])?>"></div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div><label>VAT (%)</label><input class="input" type="number" step="0.01" name="vat_rate_pct" value="<?=htmlspecialchars($_POST['vat_rate_pct'] ?? $sale['vat_rate_pct'])?>"></div>
          <div><label>ค่านายหน้า</label><input class="input" type="number" step="0.01" name="commission_amt" value="<?=htmlspecialchars($_POST['commission_amt'] ?? $sale['commission_amt'])?>"></div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>สถานะคำสั่งซื้อ</label>
            <select class="select" name="status">
              <?php foreach([1,2,3,4,9] as $opt): ?>
                <option value="<?=$opt?>" <?= (int)($_POST['status'] ?? $sale['status'])===$opt?'selected':''; ?>>
                  <?= ['','จอง','ออกใบกำกับ','รับชำระ','ส่งมอบ','','','','','ยกเลิก'][$opt] ?? 'จอง' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>หมายเหตุ</label>
            <input class="input" type="text" name="remark" value="<?=htmlspecialchars($_POST['remark'] ?? $sale['remark'])?>">
          </div>
        </div>

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="sales.php">ยกเลิก</a>
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
