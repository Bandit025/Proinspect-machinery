<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';
  $name = trim($_POST['model_name'] ?? '');
  if ($name==='') $errors[]='กรอกชื่อรุ่น';

  // ตรวจชื่อซ้ำ (ฐานข้อมูลไม่มี unique constraint)
  if (!$errors) {
    $dup = $pdo->prepare('SELECT 1 FROM models WHERE model_name = ? LIMIT 1');
    $dup->execute([$name]);
    if ($dup->fetch()) $errors[] = 'มีชื่อรุ่นนี้อยู่แล้ว';
  }

  if (!$errors) {
    $ins = $pdo->prepare('INSERT INTO models (model_name) VALUES (?)');
    $ins->execute([$name]);
    header('Location: models.php?ok=' . urlencode('เพิ่มรุ่นเรียบร้อย')); exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เพิ่มรุ่น — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=1">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">เพิ่มรุ่น</h2>
      <div class="page-sub">ฐานข้อมูล: model_id, model_name</div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card">
      <form method="post" id="addForm" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <label>ชื่อรุ่น</label>
        <input class="input" type="text" name="model_name" required value="<?=htmlspecialchars($_POST['model_name'] ?? '')?>">
        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="models.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.getElementById('addForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const name = (document.querySelector('[name="model_name"]').value || '').trim();
  if(!name){ Swal.fire({icon:'warning', title:'กรอกชื่อรุ่น', confirmButtonColor:'#fec201'}); return; }
  Swal.fire({
    icon:'question', title:'ยืนยันการบันทึก?', text:`รุ่น: "${name}"`,
    showCancelButton:true, confirmButtonText:'บันทึก', cancelButtonText:'ยกเลิก',
    reverseButtons:true, confirmButtonColor:'#fec201'
  }).then(res => { if(res.isConfirmed) e.target.submit(); });
});
</script>
</body>
</html>
