<?php
require __DIR__ . '/config.php'; // ต้องมี session_start() และ $pdo

/* ========= helpers ========= */
function build_redirect_url(string $fallbackRelative, array $params = []): string {
    // ถ้า form ส่ง return_to มา และเป็น path ภายในเว็บ -> ใช้เป็นเป้าหมาย
    $ret = $_POST['return_to'] ?? '';
    if ($ret) {
        $u = parse_url($ret);
        // อนุญาตเฉพาะ path ภายใน (ห้ามมี scheme/host และห้าม ..)
        if (empty($u['scheme']) && empty($u['host']) && isset($u['path']) && strpos($u['path'], '..') === false) {
            $path = $u['path'];
            // รักษา query เดิมและเติม params เพิ่ม (เช่น ok/error)
            $origQs = $u['query'] ?? '';
            parse_str($origQs, $orig);
            $qs = http_build_query(array_merge($orig, $params));
            return $path . ($qs ? ('?' . $qs) : '');
        }
    }
    // fallback: โฟลเดอร์เดียวกับสคริปต์นี้ (เช่น /admin/user.php)
    $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $path    = ($baseDir === '' || $baseDir === '/') ? "/{$fallbackRelative}" : "{$baseDir}/{$fallbackRelative}";
    $qs      = $params ? ('?' . http_build_query($params)) : '';
    return $path . $qs;
}
function redirect_now(string $url): void {
    if (!headers_sent()) {
        header("Location: {$url}");
        exit;
    }
    echo '<script>location.href='.json_encode($url).';</script>';
    exit;
}
/* ========================== */

// ต้องล็อกอินและเป็นแอดมินเท่านั้น
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
    redirect_now(build_redirect_url('user.php', ['error' => 'จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ']));
}

// ต้องเป็น POST เท่านั้น
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_now(build_redirect_url('user.php', ['error' => 'วิธีการเรียกไม่ถูกต้อง']));
}

// ตรวจ CSRF
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
    redirect_now(build_redirect_url('user.php', ['error' => 'CSRF token ไม่ถูกต้อง']));
}

// รับค่า id (PK ในตารางคือ user_id)
$targetId  = (int)($_POST['id'] ?? 0);
if ($targetId <= 0) {
    redirect_now(build_redirect_url('user.php', ['error' => 'ไม่พบผู้ใช้']));
}

// กันลบตัวเอง (พยายามอ่านทั้ง user_id และ id จาก session)
$currentId = (int)($_SESSION['user']['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
if ($targetId === $currentId) {
    redirect_now(build_redirect_url('user.php', ['error' => 'ไม่สามารถลบผู้ใช้ของตนเองได้']));
}

// ลบ
try {
    $stm = $pdo->prepare('DELETE FROM users WHERE user_id = :id LIMIT 1');
    $stm->execute([':id' => $targetId]);

    if ($stm->rowCount() > 0) {
        redirect_now(build_redirect_url('user.php', ['ok' => 'ลบผู้ใช้เรียบร้อย']));
    } else {
        redirect_now(build_redirect_url('user.php', ['error' => 'ไม่สามารถลบผู้ใช้ได้ หรือผู้ใช้ถูกลบไปแล้ว']));
    }
} catch (Throwable $e) {
    // เก็บ log ตามต้องการ: error_log($e->getMessage());
    redirect_now(build_redirect_url('user.php', ['error' => 'เกิดข้อผิดพลาดในการลบ']));
}
