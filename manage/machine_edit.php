<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function column_exists(PDO $pdo, $table, $col): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (int)$q->fetchColumn()>0;
}
function resolve_status_col(PDO $pdo): string {
  foreach (['status','status_code'] as $c) if (column_exists($pdo,'machines',$c)) return $c;
  return 'status';
}
if (!function_exists('status_label_th')) {
  function status_label_th(int $c): string {
    return [1=>'รับเข้า',2=>'พร้อมขาย',3=>'จอง',4=>'ขายแล้ว',5=>'ตัดจำหน่าย'][$c] ?? 'รับเข้า';
  }
}

$statusCol = resolve_status_col($pdo);
$hasPhoto  = column_exists($pdo,'machines','photo_main');

/* รับ id */
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id<=0){ header('Location: machines.php?error=' . urlencode('ไม่พบข้อมูลรถ')); exit; }

/* โหลดข้อมูลรถ */
$selCols = "machine_id, code, brand_id, model_id, model_year, serial_no, engine_no, hour_meter, color, weight_class_ton, location, asking_price, notes, created_at, updated_at";
if ($hasPhoto) $selCols .= ", photo_main";
$selCols .= ", {$statusCol} AS status_val";

$stm = $pdo->prepare("SELECT $selCols FROM machines WHERE machine_id=? LIMIT 1");
$stm->execute([$id]); $m = $stm->fetch(PDO::FETCH_ASSOC);
if (!$m){ header('Location: machines.php?error=' . urlencode('ไม่พบข้อมูลรถ')); exit; }

/* โหลด brand/model สำหรับ select */
$brands = $pdo->query("SELECT brand_id, brand_name FROM brands ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);
$models = $pdo->query("SELECT model_id, model_name FROM models ORDER BY model_name")->fetchAll(PDO::FETCH_ASSOC);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';

  $code   = trim($_POST['code'] ?? '');
  $brand  = (int)($_POST['brand_id'] ?? 0);
  $model  = (int)($_POST['model_id'] ?? 0);
  $year   = ($_POST['model_year']!=='') ? (int)$_POST['model_year'] : null;
  $serial = trim($_POST['serial_no'] ?? '');
  $engine = trim($_POST['engine_no'] ?? '');
  $hours  = ($_POST['hour_meter']!=='') ? (int)$_POST['hour_meter'] : null;
  $color  = trim($_POST['color'] ?? '');
  $wton   = ($_POST['weight_class_ton']!=='') ? (float)$_POST['weight_class_ton'] : null;
  $status = (int)($_POST['status'] ?? 1);
  $loc    = trim($_POST['location'] ?? '');
  $price  = ($_POST['asking_price']!=='') ? (float)$_POST['asking_price'] : null;
  $notes  = trim($_POST['notes'] ?? '');

  if ($code==='') $errors[]='กรอกรหัส/เลขทะเบียน';
  if ($brand<=0) $errors[]='เลือกยี่ห้อ';
  if ($model<=0) $errors[]='เลือกรุ่น';

  // upload รูปใหม่ (ถ้ามีคอลัมน์)
  $photoPath = $m['photo_main'] ?? null;
  if ($hasPhoto && isset($_FILES['photo_main']) && $_FILES['photo_main']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['photo_main']['error'] !== UPLOAD_ERR_OK) $errors[]='อัปโหลดรูปไม่สำเร็จ';
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES['photo_main']['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) $errors[]='อนุญาตเฉพาะ JPG/PNG/WebP';
    if ($_FILES['photo_main']['size'] > 5*1024*1024) $errors[]='ไฟล์รูปต้องไม่เกิน 5MB';
    if (!$errors) {
      $dir = __DIR__ . '/uploads/machines';
      if (!is_dir($dir)) mkdir($dir, 0775, true);
      $ext = strtolower(pathinfo($_FILES['photo_main']['name'], PATHINFO_EXTENSION) ?: 'jpg');
      $fname = 'm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $dir . '/' . $fname;
      if (!move_uploaded_file($_FILES['photo_main']['tmp_name'], $dest)) $errors[]='ย้ายไฟล์รูปไม่สำเร็จ';
      else {
        if ($photoPath && file_exists(__DIR__ . '/' . $photoPath)) @unlink(__DIR__ . '/' . $photoPath);
        $photoPath = 'uploads/machines/'.$fname;
      }
    }
  }

  if (!$errors) {
    $sets = "code=?, brand_id=?, model_id=?, model_year=?, serial_no=?, engine_no=?, hour_meter=?, color=?, weight_class_ton=?, {$statusCol}=?, location=?, asking_price=?, notes=?";
    $vals = [$code,$brand,$model,$year,$serial,$engine,$hours,$color,$wton,$status,$loc,$price,$notes];
    if ($hasPhoto) { $sets .= ", photo_main=?"; $vals[] = $photoPath; }
    $vals[] = $id;

    $sql = "UPDATE machines SET $sets WHERE machine_id=?";
    $upd = $pdo->prepare($sql);
    $upd->execute($vals);

    header('Location: machines.php?ok=' . urlencode('แก้ไขข้อมูลรถเรียบร้อย')); exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แก้ไขรถ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=13">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>.thumb{width:120px;height:90px;object-fit:cover;border-radius:8px;border:1px solid #eee;}</style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <main class="content">
    <div class="page-head">
      <h2 class="page-title">แก้ไขรถ #<?= (int)$m['machine_id'] ?></h2>
      <div class="page-sub"><?= htmlspecialchars($m['code']) ?></div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card">
      <form method="post" id="editForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="id"   value="<?= (int)$m['machine_id'] ?>">

        <div class="row">
          <div><label>รหัส/เลขทะเบียน</label><input class="input" type="text" name="code" required value="<?=htmlspecialchars($_POST['code'] ?? $m['code'])?>"></div>
          <div><label>ปีรุ่น</label><input class="input" type="number" name="model_year" min="1980" max="<?=date('Y')+1?>" value="<?=htmlspecialchars($_POST['model_year'] ?? $m['model_year'])?>"></div>
        </div>

        <div class="row">
          <div>
            <label>ยี่ห้อ</label>
            <select class="select" name="brand_id" required>
              <?php foreach($brands as $b): ?>
                <option value="<?=$b['brand_id']?>" <?= (($_POST['brand_id'] ?? $m['brand_id'])==$b['brand_id'])?'selected':''; ?>><?=htmlspecialchars($b['brand_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>รุ่น</label>
            <select class="select" name="model_id" required>
              <?php foreach($models as $mm): ?>
                <option value="<?=$mm['model_id']?>" <?= (($_POST['model_id'] ?? $m['model_id'])==$mm['model_id'])?'selected':''; ?>><?=htmlspecialchars($mm['model_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="row">
          <div><label>เลขตัวถัง (Serial)</label><input class="input" type="text" name="serial_no" value="<?=htmlspecialchars($_POST['serial_no'] ?? $m['serial_no'])?>"></div>
          <div><label>เลขเครื่อง (Engine)</label><input class="input" type="text" name="engine_no" value="<?=htmlspecialchars($_POST['engine_no'] ?? $m['engine_no'])?>"></div>
        </div>

        <div class="row">
          <div><label>ชั่วโมงใช้งาน</label><input class="input" type="number" name="hour_meter" value="<?=htmlspecialchars($_POST['hour_meter'] ?? $m['hour_meter'])?>"></div>
          <div><label>สี</label><input class="input" type="text" name="color" value="<?=htmlspecialchars($_POST['color'] ?? $m['color'])?>"></div>
        </div>

        <div class="row">
          <div><label>น้ำหนัก (ตัน)</label><input class="input" type="number" step="0.01" name="weight_class_ton" value="<?=htmlspecialchars($_POST['weight_class_ton'] ?? $m['weight_class_ton'])?>"></div>
          <div>
            <label>สถานะ</label>
            <select class="select" name="status">
              <?php for($i=1;$i<=5;$i++): ?>
                <option value="<?=$i?>" <?= (int)($_POST['status'] ?? $m['status_val'])===$i?'selected':''; ?>><?=status_label_th($i)?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="row">
          <div><label>ที่อยู่/สถานที่รถอยู่ตอนนี้</label><input class="input" type="text" name="location" value="<?=htmlspecialchars($_POST['location'] ?? $m['location'])?>"></div>
          <div><label>ราคาเสนอขาย</label><input class="input" type="number" step="0.01" name="asking_price" value="<?=htmlspecialchars($_POST['asking_price'] ?? $m['asking_price'])?>"></div>
        </div>

        <label>หมายเหตุ</label>
        <input class="input" type="text" name="notes" value="<?=htmlspecialchars($_POST['notes'] ?? $m['notes'])?>">

        <?php if ($hasPhoto): ?>
          <label style="margin-top:10px;">รูปหลัก (อัปโหลดใหม่จะทับของเดิม)</label>
          <?php if (!empty($m['photo_main']) && file_exists(__DIR__ . '/' . $m['photo_main'])): ?>
            <div style="margin:6px 0;"><img class="thumb" src="<?=htmlspecialchars($m['photo_main'])?>"></div>
          <?php endif; ?>
          <input class="input" type="file" name="photo_main" accept="image/jpeg,image/png,image/webp">
        <?php endif; ?>

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="machines.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.getElementById('editForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const code = (document.querySelector('[name="code"]').value || '').trim();
  if(!code){ Swal.fire({icon:'warning', title:'กรอกรหัส/เลขทะเบียน', confirmButtonColor:'#fec201'}); return; }
  Swal.fire({
    icon:'question', title:'ยืนยันการบันทึกการแก้ไข?', text:`รหัสรถ: "${code}"`,
    showCancelButton:true, confirmButtonText:'บันทึก', cancelButtonText:'ยกเลิก',
    reverseButtons:true, confirmButtonColor:'#fec201'
  }).then(res => { if(res.isConfirmed) e.target.submit(); });
});
</script>
</body>
</html>
