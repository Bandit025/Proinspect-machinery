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
    header('Location: models.php?error=' . urlencode('ไม่พบรุ่น'));
    exit;
}

$stm = $pdo->prepare('SELECT model_id, model_name FROM models WHERE model_id=? LIMIT 1');
$stm->execute([$id]);
$row = $stm->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    header('Location: models.php?error=' . urlencode('ไม่พบรุ่น'));
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[] = 'CSRF token ไม่ถูกต้อง';
    $name = trim($_POST['model_name'] ?? '');
    if ($name === '') $errors[] = 'กรอกชื่อรุ่น';

    // ตรวจชื่อซ้ำ (ยกเว้น record นี้)
    if (!$errors) {
        $dup = $pdo->prepare('SELECT 1 FROM models WHERE model_name = ? AND model_id <> ? LIMIT 1');
        $dup->execute([$name, $id]);
        if ($dup->fetch()) $errors[] = 'มีชื่อรุ่นนี้อยู่แล้ว';
    }

    if (!$errors) {
        $upd = $pdo->prepare('UPDATE models SET model_name=? WHERE model_id=?');
        $upd->execute([$name, $id]);
        header('Location: models.php?ok=' . urlencode('แก้ไขรุ่นเรียบร้อย'));
        exit;
    }

    try {
        // $m['status_val'] คือสถานะเดิม (ตัวเลข 1..5) / $status คือค่าที่ผู้ใช้ส่งมา
        if ((int)$m['status_val'] !== (int)$status) {
            $user_changed = current_fullname();
            $note_auto    = 'เปลี่ยนสถานะจากหน้ารถ (เดิม: ' . status_label_th((int)$m['status_val']) . ', ใหม่: ' . status_label_th((int)$status) . ')';
            $insH = $pdo->prepare("INSERT INTO machine_status_history (machine_id,status,changed_at,user_changed,note)
                           VALUES (?,?,?,?,?)");
            $insH->execute([
                (int)$m['machine_id'],
                (int)$status,
                date('Y-m-d H:i:s'),
                $user_changed,
                $note_auto
            ]);
        }
    } catch (Throwable $e) {
        // ไม่ให้ล้ม flow หลัก แต่คุณอาจ log ไฟล์ก็ได้
    }
}
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แก้ไขรุ่น — ProInspect Machinery</title>
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
                <h2 class="page-title">แก้ไขรุ่น #<?= (int)$row['model_id'] ?></h2>
                <div class="page-sub"><?= htmlspecialchars($row['model_name']) ?></div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <section class="card">
                <form method="post" id="editForm" novalidate>
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="id" value="<?= $row['model_id'] ?>">
                    <label>ชื่อรุ่น</label>
                    <input class="input" type="text" name="model_name" required value="<?= htmlspecialchars($_POST['model_name'] ?? $row['model_name']) ?>">
                    <div style="margin-top:14px;display:flex;gap:8px;">
                        <button class="btn btn-brand" type="submit">บันทึก</button>
                        <a class="btn btn-outline" href="models.php">ยกเลิก</a>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <script>
        document.getElementById('editForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const name = (document.querySelector('[name="model_name"]').value || '').trim();
            if (!name) {
                Swal.fire({
                    icon: 'warning',
                    title: 'กรอกชื่อรุ่น',
                    confirmButtonColor: '#fec201'
                });
                return;
            }
            Swal.fire({
                icon: 'question',
                title: 'ยืนยันการบันทึกการแก้ไข?',
                text: `รุ่น: "${name}"`,
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