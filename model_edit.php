<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id<=0) { header('Location: models.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$stm = $pdo->prepare("SELECT * FROM models WHERE model_id=? LIMIT 1");
$stm->execute([$id]);
$row = $stm->fetch(PDO::FETCH_ASSOC);
if (!$row) { header('Location: models.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$brands = $pdo->query("SELECT brand_id, brand_name FROM brands ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';

  $brand_id = (int)($_POST['brand_id'] ?? 0);
  $model_name = trim($_POST['model_name'] ?? '');

  if ($brand_id<=0) $errors[]='เลือกยี่ห้อ';
  if ($model_name==='') $errors[]='กรอกชื่อรุ่น';

  // กันซ้ำภายในยี่ห้อเดียวกัน (ยกเว้นตัวเอง)
  if (!$errors) {
    $chk = $pdo->prepare("SELECT 1 FROM models WHERE brand_id=? AND model_name=? AND model_id<>? LIMIT 1");
    $chk->execute([$brand_id, $model_name, $id]);
    if ($chk->fetch()) $errors[]='รุ่นนี้มีอยู่แล้วในยี่ห้อนี้';
  }

  if (!$errors) {
    $upd = $pdo->prepare("UPDATE models SET brand_id=?, model_name=? WHERE model_id=?");
    $upd->execute([$brand_id, $model_name, $id]);
    header('Location: models.php?ok=' . urlencode('บันทึกการแก้ไขเรียบร้อย')); exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>แก้ไขรุ่น — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=23">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">แก้ไขรุ่น #<?= (int)$row['model_id'] ?></h2>
      <div class="page-sub"><?= htmlspecialchars($row['model_name']) ?></div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul style="margin:0 0 0 18px;">
        <?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?>
      </ul></div>
    <?php endif; ?>

    <section class="card">
      <form method="post" id="editForm" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="id" value="<?=$row['model_id']?>">

        <label>ยี่ห้อ</label>
        <select class="select" name="brand_id" required>
          <?php foreach($brands as $b): ?>
            <option value="<?=$b['brand_id']?>" <?= (($_POST['brand_id'] ?? $row['brand_id'])==$b['brand_id'])?'selected':''; ?>>
              <?=htmlspecialchars($b['brand_name'])?>
            </option>
          <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">ชื่อรุ่น</label>
        <input class="input" type="text" name="model_name" required
               value="<?=htmlspecialchars($_POST['model_name'] ?? $row['model_name'])?>">

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="models.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script src="assets/script.js?v=3"></script>
<script>
document.getElementById('editForm').addEventListener('submit',(e)=>{
  e.preventDefault();
  Swal.fire({icon:'question',title:'ยืนยันการบันทึกการแก้ไข?',showCancelButton:true,confirmButtonText:'บันทึก',cancelButtonText:'ยกเลิก',reverseButtons:true,confirmButtonColor:'#fec201'})
    .then(res=>{ if(res.isConfirmed) e.target.submit(); });
});
</script>
</body>
</html>
