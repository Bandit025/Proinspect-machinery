<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1)!==2) { header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$sales = $pdo->query("SELECT s.sale_id, s.doc_no, s.sold_at,
                             m.code, b.brand_name, mo.model_name, c.customer_name
                      FROM sales s
                      JOIN machines m ON m.machine_id=s.machine_id
                      JOIN brands   b ON b.brand_id=m.brand_id
                      JOIN models   mo ON mo.model_id=m.model_id
                      JOIN customers c ON c.customer_id=s.customer_id
                      ORDER BY s.sale_id DESC")->fetchAll(PDO::FETCH_ASSOC);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';

  $sale_id     = (int)($_POST['sale_id'] ?? 0);
  $received_at = trim($_POST['received_at'] ?? '');
  $amount      = ($_POST['amount'] !== '') ? (float)$_POST['amount'] : null;
  $method      = trim($_POST['method'] ?? '');
  $notes       = trim($_POST['notes'] ?? '');

  if ($sale_id<=0) $errors[]='เลือกรายการขาย';
  if ($received_at==='') $errors[]='เลือกวันเวลารับเงิน';
  if ($amount===null || $amount<=0) $errors[]='จำนวนเงินต้องมากกว่า 0';
  if ($method==='') $errors[]='กรอก/เลือกวิธีรับเงิน';

  // อัปโหลดสลิป (ถ้ามี)
  $refPath = null;
  if (isset($_FILES['ref_file']) && $_FILES['ref_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['ref_file']['error'] !== UPLOAD_ERR_OK) $errors[]='อัปโหลดสลิปไม่สำเร็จ';
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES['ref_file']['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) $errors[]='สลิปต้องเป็น JPG/PNG/WebP';
    if ($_FILES['ref_file']['size'] > 5*1024*1024) $errors[]='สลิปต้องไม่เกิน 5MB';
    if (!$errors) {
      $dir = __DIR__ . '/uploads/receipts';
      if (!is_dir($dir)) mkdir($dir, 0775, true);
      $ext = strtolower(pathinfo($_FILES['ref_file']['name'], PATHINFO_EXTENSION) ?: 'jpg');
      $fname = 'r_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $dir . '/' . $fname;
      if (!move_uploaded_file($_FILES['ref_file']['tmp_name'], $dest)) $errors[]='บันทึกไฟล์สลิปไม่สำเร็จ';
      else $refPath = 'uploads/receipts/'.$fname;
    }
  }

  if (!$errors) {
    $ins = $pdo->prepare("INSERT INTO receipts_in (sale_id,received_at,amount,method,ref_no,notes)
                          VALUES (?,?,?,?,?,?)");
    $ins->execute([$sale_id,$received_at,$amount,$method,$refPath,$notes]);
    header('Location: receipts.php?ok=' . urlencode('เพิ่มใบรับเงินเรียบร้อย')); exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>เพิ่มใบรับเงิน — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=18">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">เพิ่มใบรับเงิน</h2>
      <div class="page-sub">แนบสลิปการโอนได้</div>
    </div>

    <?php if ($errors): ?><div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
      <form method="post" id="addForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">

        <label>เลือกเอกสารขาย</label>
        <select class="select" name="sale_id" required>
          <option value="">— เลือก —</option>
          <?php foreach($sales as $s): ?>
            <option value="<?=$s['sale_id']?>" <?= (($_POST['sale_id'] ?? '')==$s['sale_id'])?'selected':''; ?>>
              <?=htmlspecialchars(($s['doc_no']?:'-')." / {$s['code']} — {$s['brand_name']} {$s['model_name']} / {$s['customer_name']}")?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>วัน–เวลารับเงิน</label>
            <input class="input" type="datetime-local" name="received_at" required
                   value="<?=htmlspecialchars($_POST['received_at'] ?? date('Y-m-d\TH:i'))?>">
          </div>
          <div>
            <label>จำนวนเงินที่รับ</label>
            <input class="input" type="number" step="0.01" name="amount" required value="<?=htmlspecialchars($_POST['amount'] ?? '')?>">
          </div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>วิธีรับเงิน</label>
            <select class="select" name="method" required>
              <?php
                $methods = ['transfer'=>'โอน','cash'=>'เงินสด','cheque'=>'เช็ค','card'=>'บัตร','other'=>'อื่น ๆ'];
                $mSel = $_POST['method'] ?? 'transfer';
                foreach($methods as $k=>$v): ?>
                  <option value="<?=$k?>" <?=$mSel===$k?'selected':'';?>><?=$v?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>แนบสลิป (JPG/PNG/WebP ≤ 5MB)</label>
            <input class="input" type="file" name="ref_file" accept="image/jpeg,image/png,image/webp">
          </div>
        </div>

        <label style="margin-top:10px;">หมายเหตุ</label>
        <input class="input" type="text" name="notes" value="<?=htmlspecialchars($_POST['notes'] ?? '')?>">

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="receipts.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.getElementById('addForm').addEventListener('submit',(e)=>{
  e.preventDefault();
  const sid = (document.querySelector('[name="sale_id"]').value||'');
  const amt = parseFloat(document.querySelector('[name="amount"]').value||'NaN');
  if(!sid){ Swal.fire({icon:'warning',title:'เลือกเอกสารขาย',confirmButtonColor:'#fec201'}); return; }
  if(isNaN(amt) || amt<=0){ Swal.fire({icon:'warning',title:'จำนวนเงินไม่ถูกต้อง',confirmButtonColor:'#fec201'}); return; }
  Swal.fire({icon:'question',title:'ยืนยันการบันทึก?',showCancelButton:true,confirmButtonText:'บันทึก',cancelButtonText:'ยกเลิก',reverseButtons:true,confirmButtonColor:'#fec201'}).then(res=>{ if(res.isConfirmed) e.target.submit(); });
});
</script>
</body>
</html>
