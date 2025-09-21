<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
    header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ'));
    exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: locations.php?error=' . urlencode('ไม่พบรายการ'));
    exit;
}

$stm = $pdo->prepare("SELECT * FROM locations WHERE location_id=? LIMIT 1");
$stm->execute([$id]);
$row = $stm->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    header('Location: locations.php?error=' . urlencode('ไม่พบรายการ'));
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[] = 'CSRF token ไม่ถูกต้อง';
    $name = trim($_POST['location_name'] ?? '');
    if ($name === '') $errors[] = 'กรอกชื่อสถานที่';

    // กันชื่อซ้ำ (ยกเว้นตัวเอง)
    if (!$errors) {
        $chk = $pdo->prepare("SELECT 1 FROM locations WHERE location_name=? AND location_id<>? LIMIT 1");
        $chk->execute([$name, $id]);
        if ($chk->fetch()) $errors[] = 'มีชื่อสถานที่นี้แล้ว';
    }

    if (!$errors) {
        $upd = $pdo->prepare("UPDATE locations SET location_name=? WHERE location_id=?");
        $upd->execute([$name, $id]);
        header('Location: locations.php?ok=' . urlencode('บันทึกการแก้ไขเรียบร้อย'));
        exit;
    }
}
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>แก้ไขสถานที่ — ProInspect Machinery</title>
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
                <h2 class="page-title">แก้ไขสถานที่ #<?= (int)$row['location_id'] ?></h2>
                <div class="page-sub"><?= htmlspecialchars($row['location_name']) ?></div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <section class="card">
                <form method="post" id="editForm" novalidate>
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="id" value="<?= $row['location_id'] ?>">
                    <label>ชื่อสถานที่</label>
                    <input class="input" type="text" name="location_name" required value="<?= htmlspecialchars($_POST['location_name'] ?? $row['location_name']) ?>">
                    <div style="margin-top:14px;display:flex;gap:8px;">
                        <button class="btn btn-brand" type="submit">บันทึก</button>
                        <a class="btn btn-outline" href="locations.php">ยกเลิก</a>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <script src="assets/script.js?v=3"></script>
    <script>
        document.getElementById('editForm').addEventListener('submit', (e) => {
            e.preventDefault();
            Swal.fire({
                    icon: 'question',
                    title: 'ยืนยันการบันทึกการแก้ไข?',
                    showCancelButton: true,
                    confirmButtonText: 'บันทึก',
                    cancelButtonText: 'ยกเลิก',
                    reverseButtons: true,
                    confirmButtonColor: '#fec201'
                })
                .then(res => {
                    if (res.isConfirmed) e.target.submit();
                });
        });
    </script>
</body>

</html>