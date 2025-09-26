<?php
// add_brand.php
require __DIR__ . '/config.php';

// อนุญาตเฉพาะแอดมิน
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}

// เตรียม CSRF
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$errors = [];
$okMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ตรวจ CSRF
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  $brand_name = trim($_POST['brand_name'] ?? '');

  if ($brand_name === '') {
    $errors[] = 'กรอกชื่อยี่ห้อ';
  }

  if (!$errors) {
    try {
      $stmt = $pdo->prepare('INSERT INTO brands (brand_name) VALUES (?)');
      $stmt->execute([$brand_name]);
      $okMsg = 'บันทึกยี่ห้อสำเร็จ';
      // เคลียร์ค่าในฟอร์มหลังบันทึก (ให้ผู้ใช้ “เพิ่มต่อ” ได้ง่าย)
      $_POST['brand_name'] = '';
    } catch (PDOException $e) {
      // 1062 = duplicate key
      if (($e->errorInfo[1] ?? null) === 1062) {
        $errors[] = 'มีชื่อยี่ห้อนี้อยู่แล้ว';
      } else {
        $errors[] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เพิ่มยี่ห้อ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=7">
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="layout">
  <?php include 'sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>



  <main class="content">
    <div class="page-head">
      <h2 class="page-title">เพิ่มยี่ห้อ (Brand)</h2>
      <div class="page-sub">สร้างยี่ห้อใหม่เพื่อใช้กับรุ่นและรถ</div>
    </div>

    <section class="card">
      <form method="post" id="brandForm" novalidate>
        <input type="hidden" name="csrf" value="<?= $csrf ?>">

        <label>ชื่อยี่ห้อ</label>
        <input class="input" type="text" name="brand_name" id="brand_name"
               placeholder="เช่น Komatsu, Caterpillar, Hitachi"
               required value="<?= htmlspecialchars($_POST['brand_name'] ?? '') ?>">

        <div style="display:flex; gap:8px; margin-top:14px;">
          <button class="btn btn-brand" type="submit" id="btnSubmit">บันทึก</button>
          <a class="btn btn-outline" href="manage.php">ไปหน้ารายการยี่ห้อ</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
// ===== SweetAlert2 Confirm ก่อนบันทึก =====
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('brandForm');
  const btn  = document.getElementById('btnSubmit');

  form.addEventListener('submit', (e) => {
    // กันส่งทันที
    e.preventDefault();

    // ตรวจค่าขั้นต้น
    const name = (document.getElementById('brand_name').value || '').trim();
    if (!name) {
      Swal.fire({icon:'warning', title:'กรอกชื่อยี่ห้อ', confirmButtonText:'ตกลง'});
      return;
    }

    // แสดง confirm sweetalert2
    Swal.fire({
      icon: 'question',
      title: 'ยืนยันการบันทึก?',
      text: `จะเพิ่มยี่ห้อ: "${name}"`,
      showCancelButton: true,
      confirmButtonText: 'บันทึก',
      cancelButtonText: 'ยกเลิก',
      reverseButtons: true,
      confirmButtonColor: '#fec201'
    }).then((res) => {
      if (res.isConfirmed) {
        // ส่งฟอร์มจริง
        form.submit();
      }
    });
  });

  // ถ้ามี message จากฝั่ง PHP ให้เด้งแจ้งผลด้วย SweetAlert2
  <?php if ($okMsg): ?>
    Swal.fire({icon:'success', title:'สำเร็จ', text:'<?= htmlspecialchars($okMsg, ENT_QUOTES) ?>', confirmButtonColor:'#fec201'});
  <?php elseif ($errors): ?>
    Swal.fire({icon:'error', title:'ไม่สำเร็จ', html:`<?= htmlspecialchars(implode('<br>', $errors), ENT_QUOTES) ?>`, confirmButtonColor:'#fec201'});
  <?php endif; ?>
});
</script>
</body>
</html>
