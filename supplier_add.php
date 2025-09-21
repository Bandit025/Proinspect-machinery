<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  $name  = trim($_POST['supplier_name'] ?? '');
  $tax   = trim($_POST['tax_id'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $addr  = trim($_POST['address'] ?? '');

  if ($name === '') $errors[] = 'กรอกชื่อผู้ขาย';

  // ---- PHP validation เบอร์โทร: เก็บเฉพาะเลขและต้องยาว 10 หลัก ----
  $phone_digits = preg_replace('/\D+/', '', $phone); // ตัดทุกอย่างที่ไม่ใช่เลข
  if ($phone_digits === '' || !preg_match('/^\d{10}$/', $phone_digits)) {
    $errors[] = 'เบอร์โทรต้องเป็น "ตัวเลข 10 หลัก" เท่านั้น (เช่น 0812345678)';
  }

  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'อีเมลไม่ถูกต้อง';
  }

  if (!$errors) {
    $ins = $pdo->prepare(
      'INSERT INTO suppliers (supplier_name,tax_id,phone,email,address) VALUES (?,?,?,?,?)'
    );
    $ins->execute([$name, $tax, $phone_digits, $email, $addr]); // เซฟเฉพาะตัวเลข 10 หลัก
    header('Location: suppliers.php?ok=' . urlencode('เพิ่มผู้ขายเรียบร้อย'));
    exit;
  }
}
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เพิ่มผู้ขาย — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=10">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">เพิ่มผู้ขาย</h2>
        <div class="page-sub">บันทึกข้อมูลซัพพลายเออร์ใหม่</div>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <form method="post" id="supForm" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <label>ชื่อผู้ขาย</label>
          <input class="input" type="text" name="supplier_name" required>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
            <div>
              <label>เลขผู้เสียภาษี</label>
              <input class="input" type="text" name="tax_id">
            </div>
            <div>
              <label>เบอร์โทร</label>
              <input
                class="input"
                type="tel"
                name="phone"
                inputmode="numeric"
                pattern="[0-9]{10}"
                maxlength="10"
                placeholder="เช่น 0812345678"
                required>
            </div>
          </div>

          <label style="margin-top:10px;">อีเมล</label>
          <input class="input" type="email" name="email">

          <label style="margin-top:10px;">ที่อยู่</label>
          <input class="input" type="text" name="address">

          <div style="margin-top:14px;display:flex;gap:8px;">
            <button class="btn btn-brand" type="submit">บันทึก</button>
            <a class="btn btn-outline" href="suppliers.php">ยกเลิก</a>
          </div>
        </form>
      </section>
    </main>
  </div>
    <script src="assets/script.js"></script>
  <script>
    // ====== จำกัดให้กรอกเฉพาะตัวเลขทันทีที่พิมพ์/วาง ======
    const phoneInput = document.querySelector('[name="phone"]');

    // กันคีย์ที่ไม่ใช่ตัวเลข
    phoneInput.addEventListener('keypress', (evt) => {
      const ch = String.fromCharCode(evt.which || evt.keyCode);
      if (!/[0-9]/.test(ch)) {
        evt.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'กรอกเฉพาะตัวเลข (0–9)',
          timer: 1200,
          showConfirmButton: false
        });
      }
    });

    // กันการวางที่มีตัวอักษร
    phoneInput.addEventListener('paste', (e) => {
      const txt = (e.clipboardData || window.clipboardData).getData('text');
      if (/\D/.test(txt)) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'วางได้เฉพาะตัวเลขเท่านั้น',
          timer: 1200,
          showConfirmButton: false
        });
      }
    });

    // ทำความสะอาดค่าทุกครั้งที่เปลี่ยน (เหลือเฉพาะเลข และตัดให้เหลือ 10)
    phoneInput.addEventListener('input', (e) => {
      e.target.value = e.target.value.replace(/\D/g, '').slice(0, 10);
    });

    // ====== ตรวจสอบก่อนส่งฟอร์ม ======
    document.getElementById('supForm').addEventListener('submit', (e) => {
      e.preventDefault();

      const name = (document.querySelector('[name="supplier_name"]').value || '').trim();
      const phone = (phoneInput.value || '').trim();

      if (!name) {
        Swal.fire({
          icon: 'warning',
          title: 'กรอกชื่อผู้ขาย',
          confirmButtonColor: '#fec201'
        });
        return;
      }
      if (!/^\d{10}$/.test(phone)) {
        Swal.fire({
          icon: 'warning',
          title: 'เบอร์โทรต้องเป็นตัวเลข 10 หลัก',
          text: 'เช่น 0812345678',
          confirmButtonColor: '#fec201'
        });
        return;
      }

      Swal.fire({
        icon: 'question',
        title: 'ยืนยันการบันทึก?',
        text: `ผู้ขาย: "${name}"`,
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        confirmButtonColor: '#fec201'
      }).then(res => {
        if (res.isConfirmed) e.target.submit();
      });
    });
  </script>
</body>

</html>