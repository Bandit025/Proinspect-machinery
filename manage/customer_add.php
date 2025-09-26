<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }
  $name = trim($_POST['customer_name'] ?? '');
  $tax  = trim($_POST['tax_id'] ?? '');
  $phone= trim($_POST['phone'] ?? '');
  $email= trim($_POST['email'] ?? '');
  $addr = trim($_POST['address'] ?? '');

  if ($name === '') $errors[] = 'กรอกชื่อลูกค้า';
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';

  if (!$errors) {
    $ins = $pdo->prepare('INSERT INTO customers (customer_name,tax_id,phone,email,address) VALUES (?,?,?,?,?)');
    $ins->execute([$name,$tax,$phone,$email,$addr]);
    header('Location: customers.php?ok=' . urlencode('เพิ่มลูกค้าเรียบร้อย')); exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เพิ่มลูกค้า — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=9">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">เพิ่มลูกค้า</h2>
      <div class="page-sub">บันทึกข้อมูลลูกค้าใหม่</div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <section class="card">
      <form method="post" id="custForm" novalidate>
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>ชื่อลูกค้า</label>
        <input class="input" type="text" name="customer_name" required value="<?=htmlspecialchars($_POST['customer_name'] ?? '')?>">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
          <div>
            <label>เลขผู้เสียภาษี</label>
            <input class="input" type="text" name="tax_id" value="<?=htmlspecialchars($_POST['tax_id'] ?? '')?>">
          </div>
          <div>
            <label>เบอร์โทร</label>
            <input class="input" type="text" name="phone" value="<?=htmlspecialchars($_POST['phone'] ?? '')?>">
          </div>
        </div>

        <label style="margin-top:10px;">อีเมล</label>
        <input class="input" type="email" name="email" value="<?=htmlspecialchars($_POST['email'] ?? '')?>">

        <label style="margin-top:10px;">ที่อยู่</label>
        <input class="input" type="text" name="address" value="<?=htmlspecialchars($_POST['address'] ?? '')?>">

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit" id="btnSubmit">บันทึก</button>
          <a class="btn btn-outline" href="customers.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.getElementById('custForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const name = (document.querySelector('[name="customer_name"]').value || '').trim();
  if(!name){
    Swal.fire({icon:'warning', title:'กรอกชื่อลูกค้า', confirmButtonColor:'#fec201'});
    return;
  }
  Swal.fire({
    icon:'question', title:'ยืนยันการบันทึก?', text:`ชื่อลูกค้า: "${name}"`,
    showCancelButton:true, confirmButtonText:'บันทึก', cancelButtonText:'ยกเลิก', reverseButtons:true,
    confirmButtonColor:'#fec201'
  }).then(res => { if(res.isConfirmed) e.target.submit(); });
});
</script>
</body>
</html>
