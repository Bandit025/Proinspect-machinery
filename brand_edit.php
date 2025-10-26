<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$brand_id = $_GET['id'];

$stm = $pdo->prepare("SELECT * FROM brands WHERE brand_id=? LIMIT 1");
$stm->execute([$brand_id]);
$row = $stm->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    header('Location: manage.php?error=' . urlencode('ไม่พบรายการ'));
    exit;
}


$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[] = 'CSRF token ไม่ถูกต้อง';

    $brand_id = $_POST['brand_id'];
    $brand_name = $_POST['brand_name'];

    if ($brand_name === '') {
        $errors[] = 'กรอกชื่อแบรนด์';
    }



    if (!$errors) {
        $upd = $pdo->prepare("UPDATE brands SET brand_name=? WHERE brand_id=?");
        $upd->execute([$brand_name, $brand_id]);
        header('Location: manage.php?ok=' . urlencode('บันทึกการแก้ไขเรียบร้อย'));
        exit;
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
                <h2 class="page-title">แก้ไขแบรนด์</h2>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <?php if ($errors) { ?>
                        <div class="alert alert-danger">
                            <ul style="margin:0 0 0 18px;">
                                <?php foreach ($errors as $e) { ?>
                                    <li><?= $e ?></li>
                                <?php } ?>
                            </ul>
                        </div>
                    <?php } ?>
                    <form method="post" id="editForm" novalidate>
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="brand_id" value="<?= $row['brand_id'] ?>">

                        <label>แบรนด์</label>
                        <input class="input" type="text" name="brand_name" required
                            value="<?= $row['brand_name'] ?>">
                        <div style="margin-top:14px;display:flex;gap:8px;">
                            <button class="btn btn-brand" type="submit">บันทึก</button>
                            <a class="btn btn-outline" href="manage.php">ยกเลิก</a>
                        </div>
                    </form>
                </div>
            </div>
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