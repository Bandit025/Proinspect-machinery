<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function status_options() {
  return [1=>'รับเข้า', 2=>'พร้อมขาย', 3=>'ติดจอง', 4=>'ขายแล้ว', 5=>'ตัดจำหน่าย'];
}

/* รุ่น + ยี่ห้อ เพื่อเลือกเป็นรุ่น */
$models = $pdo->query("
  SELECT mo.model_id, mo.model_name, mo.brand_id, b.brand_name
  FROM models mo
  JOIN brands b ON b.brand_id = mo.brand_id
  ORDER BY b.brand_name, mo.model_name
")->fetchAll(PDO::FETCH_ASSOC);

/* สถานที่ */
$locs = $pdo->query("SELECT location_id, location_name FROM locations ORDER BY location_name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  $code       = trim($_POST['code'] ?? '');
  if ($code === '') $code = '-';

  $model_id   = (int)($_POST['model_id'] ?? 0);
  $model_year = ($_POST['model_year'] !== '') ? (int)$_POST['model_year'] : null;
  $serial_no  = trim($_POST['serial_no'] ?? '');
  $engine_no  = trim($_POST['engine_no'] ?? '');
  $hour_meter = $_POST['hour_meter'];
  if ($hour_meter === '') { 
    $hour_meter = 0; 
  }
  

  if($hour_meter !== '') {
    $hour_meter = (int)$hour_meter;
    if ($hour_meter < 0) $errors[] = 'ชั่วโมงใช้งานต้องเป็นจำนวนเต็มบวก';
  } else {
    $hour_meter = $_POST['hour_meter'];
  }
  $color      = trim($_POST['color'] ?? '');
  $weight     = ($_POST['weight_class_ton'] !== '') ? (float)$_POST['weight_class_ton'] : null;
  $status     = (int)($_POST['status'] ?? 1);
  $location   = ($_POST['location'] !== '') ? (int)$_POST['location'] : null; // FK -> locations.location_id
  $purchase   = ($_POST['purchase_price'] !== '') ? (float)$_POST['purchase_price'] : null;
  $asking     = ($_POST['asking_price']   !== '') ? (float)$_POST['asking_price']   : null;
  $notes      = trim($_POST['notes'] ?? '');
  $image_path = null;

  if ($model_id <= 0) $errors[] = 'เลือกรุ่น';

  /* แปลง model_id → brand_id */
  $brand_id = null;
  if (!$errors) {
    $stm = $pdo->prepare("
      SELECT mo.brand_id, mo.model_name, b.brand_name
      FROM models mo
      JOIN brands b ON b.brand_id = mo.brand_id
      WHERE mo.model_id = ?
      LIMIT 1
    ");
    $stm->execute([$model_id]);
    $mdl = $stm->fetch(PDO::FETCH_ASSOC);
    if (!$mdl) $errors[] = 'ไม่พบรุ่นที่เลือก';
    else $brand_id = (int)$mdl['brand_id'];
  }

  /* ไม่อนุญาต code ซ้ำ เฉพาะเมื่อ code <> '-' */
  if (!$errors && $code !== '-') {
    $chk = $pdo->prepare("SELECT 1 FROM machines WHERE code = ? LIMIT 1");
    $chk->execute([$code]);
    if ($chk->fetch()) $errors[] = 'เลขทะเบียน/รหัสรถนี้มีอยู่แล้วในระบบ';
  }

  /* อัปโหลดรูป (ออปชัน) */
  if (!$errors && isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = 'อัปโหลดรูปไม่สำเร็จ';
    } else {
      $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
        $errors[]='ชนิดไฟล์รูปไม่รองรับ';
      } else {
        $dir = __DIR__ . '/uploads/machines';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $fname = 'm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $fname)) {
          $errors[] = 'บันทึกรูปไม่สำเร็จ';
        } else {
          $image_path = 'uploads/machines/' . $fname;
        }
      }
    }
  }

  /* INSERT */
  if (!$errors) {
    
      $ins = $pdo->prepare("
        INSERT INTO machines
          (code, model_id, model_year, serial_no, engine_no, hour_meter, color, weight_class_ton, status, location, purchase_price, asking_price, notes, image_path)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      $ins->execute([
        $code, $model_id, $model_year, $serial_no, $engine_no, $hour_meter, $color,
        $weight, $status, $location, $purchase, $asking, $notes, $image_path
      ]);
      header('Location: machines.php?ok=' . urlencode('เพิ่มรถเรียบร้อย')); exit;
    
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>เพิ่มรถ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=24">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">เพิ่มรถ</h2>
      <div class="page-sub">เลือกรุ่น (ยี่ห้อ–รุ่น) และกรอกรายละเอียด</div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <section class="card">
      <form method="post" enctype="multipart/form-data" id="addForm" novalidate>
        <input type="hidden" name="csrf" value="<?= $csrf ?>">

        <label>เลขทะเบียน/รหัสรถ</label>
        <input class="input" type="text" name="code" placeholder="เว้นว่างได้ ระบบจะตั้งเป็น - ให้อัตโนมัติ">

        <label style="margin-top:10px;">รุ่น (ยี่ห้อ — รุ่น)</label>
        <select class="select" name="model_id" required>
          <option value="">— เลือก —</option>
          <?php foreach ($models as $mo): ?>
            <option value="<?= $mo['model_id'] ?>" <?= (($_POST['model_id'] ?? '') == $mo['model_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($mo['brand_name'] . ' — ' . $mo['model_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>ปีรุ่น</label>
            <select class="select" name="model_year" required>
              <option value="">— เลือกปี —</option>
              <?php $yNow=(int)date('Y'); for ($y=$yNow; $y>=1990; $y--): ?>
                <option value="<?= $y ?>" <?= ((int)($_POST['model_year'] ?? 0) === $y) ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label>ชั่วโมงใช้งาน</label>
            <input class="input" type="number" name="hour_meter" value="<?= htmlspecialchars($_POST['hour_meter'] ?? '') ?>">
          </div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div><label>เลขตัวถัง</label><input class="input" type="text" name="serial_no" value="<?= htmlspecialchars($_POST['serial_no'] ?? '') ?>"></div>
          <div><label>เลขเครื่อง</label><input class="input" type="text" name="engine_no" value="<?= htmlspecialchars($_POST['engine_no'] ?? '') ?>"></div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div><label>สี</label><input class="input" type="text" name="color" value="<?= htmlspecialchars($_POST['color'] ?? '') ?>"></div>
          <div><label>น้ำหนัก (ตัน)</label><input class="input" type="number" step="0.01" name="weight_class_ton" value="<?= htmlspecialchars($_POST['weight_class_ton'] ?? '') ?>"></div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>สถานะ</label>
            <select class="select" name="status">
              <?php foreach (status_options() as $k=>$v): ?>
                <option value="<?= $k ?>" <?= (int)($_POST['status'] ?? 1) === $k ? 'selected' : '' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>คลัง</label>
            <select class="select" name="location" required>
              <option value="">— เลือก —</option>
              <?php foreach ($locs as $loc): ?>
                <option value="<?= $loc['location_id'] ?>" <?= (($_POST['location'] ?? '') == $loc['location_id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($loc['location_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>ราคาซื้อ</label>
            <input class="input money-mask" type="text" inputmode="decimal" id="purchase_price_view" placeholder="0.00"
              value="<?= htmlspecialchars(isset($_POST['purchase_price']) && $_POST['purchase_price']!=='' ? number_format((float)str_replace(',', '', $_POST['purchase_price']), 2) : '') ?>">
            <input type="hidden" name="purchase_price" id="purchase_price" value="<?= htmlspecialchars($_POST['purchase_price'] ?? '') ?>">
          </div>
          <div>
            <label>ราคาตั้งขาย</label>
            <input class="input money-mask" type="text" inputmode="decimal" id="asking_price_view" placeholder="0.00"
              value="<?= htmlspecialchars(isset($_POST['asking_price']) && $_POST['asking_price']!=='' ? number_format((float)str_replace(',', '', $_POST['asking_price']), 2) : '') ?>">
            <input type="hidden" name="asking_price" id="asking_price" value="<?= htmlspecialchars($_POST['asking_price'] ?? '') ?>">
          </div>
        </div>

        <label style="margin-top:10px;">หมายเหตุ</label>
        <input class="input" type="text" name="notes" value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">

        <label style="margin-top:10px;">รูปหลัก (ออปชัน)</label>
        <input class="input" type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif">

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="machines.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script src="assets/script.js?v=3"></script>
<script>
document.getElementById('addForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const mid = document.querySelector('[name="model_id"]').value;
  if (!mid) { Swal.fire({icon:'warning',title:'เลือกรุ่น',confirmButtonColor:'#fec201'}); return; }
  Swal.fire({
    icon:'question', title:'ยืนยันการบันทึก?', showCancelButton:true,
    confirmButtonText:'บันทึก', cancelButtonText:'ยกเลิก', reverseButtons:true, confirmButtonColor:'#fec201'
  }).then(res=>{ if(res.isConfirmed) e.target.submit(); });
});
</script>
<script>
(function(){
  const fmt = new Intl.NumberFormat('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});
  function onlyNumberDot(s){ s=(s||'').toString().replace(/[^0-9.]/g,''); const p=s.split('.'); if(p.length>2) s=p[0]+'.'+p.slice(1).join(''); return s; }
  function toNumber(s){ const n=parseFloat(onlyNumberDot((s||'').toString().replace(/,/g,''))); return isNaN(n)?'':n; }
  function addMask(viewId, hiddenId){
    const v=document.getElementById(viewId), h=document.getElementById(hiddenId); if(!v||!h) return;
    if(h.value!==''){ const n=toNumber(h.value); if(n!=='') v.value=fmt.format(n); }
    v.addEventListener('input', ()=>{
      const start=v.selectionStart, before=v.value, raw=onlyNumberDot(before.replace(/,/g,''));
      let [i='',d='']=raw.split('.'); d=d.slice(0,2); i=i.replace(/^0+(?=\d)/,'');
      const withCommas=i.replace(/\B(?=(\d{3})+(?!\d))/g,',')+(d?'.'+d:'');
      v.value=withCommas; const n=toNumber(v.value); h.value=(n==='')?'':n.toFixed(2);
      const diff=withCommas.length-before.length, pos=Math.max(0,(start||0)+diff); requestAnimationFrame(()=>v.setSelectionRange(pos,pos));
    });
    v.addEventListener('blur', ()=>{ const n=toNumber(v.value); if(n===''){ v.value=''; h.value=''; return; } v.value=fmt.format(n); h.value=n.toFixed(2); });
  }
  addMask('purchase_price_view','purchase_price');
  addMask('asking_price_view','asking_price');
})();
</script>
</body>
</html>
