<?php
require __DIR__ . '/config.php';

# อนุญาตเฉพาะแอดมิน
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
    header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ'));
    exit;
}

# CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        $errors[] = 'CSRF token ไม่ถูกต้อง';
    }

    # จัดรูปแบบชื่อ: trim + ยุบช่องว่างหลายตัวเป็น 1
    $raw  = $_POST['location_name'] ?? '';
    $name = preg_replace('/\s+/u', ' ', trim($raw));

    if ($name === '') {
        $errors[] = 'กรอกชื่อสถานที่';
    } elseif (mb_strlen($name, 'UTF-8') > 120) {
        $errors[] = 'ชื่อสถานที่ยาวเกินไป (ไม่เกิน 120 ตัวอักษร)';
    }

    # กันชื่อซ้ำแบบเบื้องต้น
    if (!$errors) {
        $chk = $pdo->prepare("SELECT 1 FROM locations WHERE location_name = ? LIMIT 1");
        $chk->execute([$name]);
        if ($chk->fetch()) {
            $errors[] = 'มีชื่อสถานที่นี้แล้ว';
        }
    }

    # บันทึก
    if (!$errors) {
        try {
            $ins = $pdo->prepare("INSERT INTO locations (location_name) VALUES (?)");
            $ins->execute([$name]);
            # กลับไปหน้าแสดงรายการ (ใช้ location.php ตามที่คุณใช้)
            header('Location: location.php?ok=' . urlencode('เพิ่มสถานที่เรียบร้อย'));
            exit;
        } catch (PDOException $e) {
            # กันกรณีชน UNIQUE key (SQLSTATE 23000)
            if ($e->getCode() === '23000') {
                $errors[] = 'มีชื่อสถานที่นี้แล้ว';
            } else {
                $errors[] = 'บันทึกไม่สำเร็จ: ' . $e->getCode();
            }
        }
    }
}
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>เพิ่มสถานที่ — ProInspect Machinery</title>
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
                <h2 class="page-title">เพิ่มสถานที่</h2>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <section class="card">
                <form method="post" id="addForm" novalidate>
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <label>ชื่อสถานที่</label>
                    <input class="input" type="text" name="location_name" required maxlength="120"
                        value="<?= htmlspecialchars($_POST['location_name'] ?? '') ?>" autofocus>
                    <div style="margin-top:14px;display:flex;gap:8px;">
                        <button class="btn btn-brand" type="submit">บันทึก</button>
                        <a class="btn btn-outline" href="location.php">ยกเลิก</a>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <script src="assets/script.js?v=3"></script>
    <script>
        document.getElementById('addForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const input = document.querySelector('[name="location_name"]');
            const name = (input.value || '').replace(/\s+/g, ' ').trim();
            if (!name) {
                Swal.fire({
                    icon: 'warning',
                    title: 'กรอกชื่อสถานที่',
                    confirmButtonColor: '#fec201'
                });
                return;
            }
            if (name.length > 120) {
                Swal.fire({
                    icon: 'warning',
                    title: 'ชื่อสถานที่ยาวเกินไป (ไม่เกิน 120)',
                    confirmButtonColor: '#fec201'
                });
                return;
            }
            // เขียนค่าที่จัดรูปแบบกลับคืนลง input เพื่อให้ส่งข้อมูลตรงกับฝั่งเซิร์ฟเวอร์
            input.value = name;

            Swal.fire({
                icon: 'question',
                title: 'ยืนยันการบันทึก?',
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