<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: suppliers.php?error=' . urlencode('ไม่พบรหัสผู้ขาย'));
    exit;
}

/* ---------- ดึงข้อมูลเดิม ---------- */
$stm = $pdo->prepare('SELECT supplier_id, supplier_name, tax_id, phone, email, address FROM suppliers WHERE supplier_id=? LIMIT 1');
$stm->execute([$id]);
$old = $stm->fetch(PDO::FETCH_ASSOC);
if (!$old) {
    header('Location: suppliers.php?error=' . urlencode('ไม่พบข้อมูลผู้ขาย'));
    exit;
}

/* ใช้ $form สำหรับเติมค่าในฟอร์ม (POST จะทับค่าเดิม) */
$form = $old;

/* ---------- POST: อัปเดตข้อมูล ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        $errors[] = 'CSRF token ไม่ถูกต้อง';
    }

    $form['supplier_name'] = trim($_POST['supplier_name'] ?? '');
    $form['tax_id']        = trim($_POST['tax_id'] ?? '');
    $form['phone']         = trim($_POST['phone'] ?? '');
    $form['email']         = trim($_POST['email'] ?? '');
    $form['address']       = trim($_POST['address'] ?? '');

    if ($form['supplier_name'] === '') $errors[] = 'กรอกชื่อผู้ขาย';

    // เก็บเฉพาะตัวเลข และต้อง 10 หลัก
    $phone_digits = preg_replace('/\D+/', '', $form['phone']);
    if ($phone_digits === '' || !preg_match('/^\d{10}$/', $phone_digits)) {
        $errors[] = 'เบอร์โทรต้องเป็น "ตัวเลข 10 หลัก" เท่านั้น (เช่น 0812345678)';
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'อีเมลไม่ถูกต้อง';
    }

    if (!$errors) {
        $upd = $pdo->prepare('UPDATE suppliers
      SET supplier_name=?, tax_id=?, phone=?, email=?, address=?
      WHERE supplier_id=? LIMIT 1');
        $upd->execute([
            $form['supplier_name'],
            $form['tax_id'],
            $phone_digits,       // เซฟเฉพาะเลข 10 หลัก
            $form['email'],
            $form['address'],
            $id
        ]);
        header('Location: suppliers.php?ok=' . urlencode('อัปเดตผู้ขายเรียบร้อย'));
        exit;
    }
}

// เตรียมค่า phone สำหรับแสดงผลในฟอร์ม (เป็นเลขล้วนและยาวไม่เกิน 10)
$phone_show = substr(preg_replace('/\D+/', '', (string)$form['phone']), 0, 10);
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แก้ไขผู้ขาย — ProInspect Machinery</title>
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
                <h2 class="page-title">แก้ไขผู้ขาย</h2>
                <div class="page-sub">รหัส: #<?= (int)$form['supplier_id'] ?></div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <section class="card">
                <form method="post" id="supForm" novalidate>
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="id" value="<?= (int)$form['supplier_id'] ?>">

                    <label>ชื่อผู้ขาย</label>
                    <input class="input" type="text" name="supplier_name" required value="<?= h($form['supplier_name']) ?>">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
                        <div>
                            <label>เลขผู้เสียภาษี</label>
                            <input
                                class="input"
                                type="text"
                                name="tax_id"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                oninput="this.value=this.value.replace(/\D/g,'');"
                                value="<?= h($form['tax_id']) ?>">
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
                                value="<?= h($phone_show) ?>"
                                required>
                        </div>
                    </div>

                    <label style="margin-top:10px;">อีเมล</label>
                    <input class="input" type="email" name="email" value="<?= h($form['email']) ?>">

                    <label style="margin-top:10px;">ที่อยู่</label>
                    <input class="input" type="text" name="address" value="<?= h($form['address']) ?>">

                    <div style="margin-top:14px;display:flex;gap:8px;">
                        <button class="btn btn-brand" type="submit">บันทึกการแก้ไข</button>
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
                title: 'ยืนยันการแก้ไข?',
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