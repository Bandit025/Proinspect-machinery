<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
    header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
    exit;
}
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

$ok  = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$q = trim($_GET['q'] ?? '');

$params = [];
$sql = "SELECT location_id, location_name FROM locations WHERE 1=1";
if ($q !== '') {
    $sql .= " AND location_name LIKE :q";
    $params[':q'] = "%{$q}%";
}
$sql .= " ORDER BY location_name";

$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>สถานที่เก็บรถ — ProInspect Machinery</title>
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
                <h2 class="page-title">สถานที่เก็บรถ (Locations)</h2>
                <div class="page-sub">จัดการชื่อสถานที่เพื่อใช้เลือก/อ้างอิง</div>
            </div>

            <?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <section class="card">
                <div class="card-head">
                    <h3 class="h5">รายการ</h3>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <form method="get" action="location.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <input class="input" type="text" name="q" placeholder="ค้นหาชื่อสถานที่" value="<?= htmlspecialchars($q) ?>">
                            <button class="btn btn-outline sm" type="submit">ค้นหา</button>
                        </form>
                        <?php if ($isAdmin): ?>
                            <a class="btn btn-brand sm" href="location_add.php">เพิ่มสถานที่</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:80px;">#</th>
                                <th>ชื่อสถานที่</th>
                                <th class="tr" style="width:180px;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1;
                            foreach ($rows as $r): $id = (int)$r['location_id']; ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars($r['location_name']) ?></td>
                                    <td class="tr">
                                        <a class="link" href="location_edit.php?id=<?= $id ?>">แก้ไข</a>
                                        <?php if ($isAdmin): ?> ·
                                            <form action="location_delete.php" method="post" class="js-del" data-info="<?= htmlspecialchars($r['location_name']) ?>" style="display:inline;">
                                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                                <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="3" class="muted">ไม่พบข้อมูล</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="assets/script.js?v=3"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.js-del').forEach(form => {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const info = form.dataset.info || 'รายการนี้';
                    Swal.fire({
                        icon: 'warning',
                        title: 'ยืนยันการลบ?',
                        text: info,
                        showCancelButton: true,
                        confirmButtonText: 'ลบ',
                        cancelButtonText: 'ยกเลิก',
                        reverseButtons: true,
                        confirmButtonColor: '#fec201'
                    }).then(res => {
                        if (res.isConfirmed) form.submit();
                    });
                });
            });
        });
    </script>
</body>

</html>