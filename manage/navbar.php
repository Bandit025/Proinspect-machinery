<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$user = $_SESSION['user'] ?? null;
$roleName = ($user && ($user['role'] === 1 || $user['role'] === '1' || $user['role'] === 'admin')) ? 'admin' : 'user';
?>
<nav class="navbar">
  <button class="icon-btn" id="btnSidebar" aria-label="Toggle sidebar">☰</button>

  <div class="brand">
    <span class="brand-badge sm">PI</span>
    <span class="brand-title">ProInspect Machinery</span>
  </div>

  <div class="nav-right">
    <?php if ($user): ?>
      <span class="user-chip"><?=htmlspecialchars($user['name'])?></span>
      <a class="btn btn-outline sm" href="logout.php">ออกจากระบบ</a>
    <?php else: ?>
      <a class="btn btn-brand sm" href="index.php">เข้าสู่ระบบ</a>
    <?php endif; ?>
  </div>
</nav>


<!-- navbar.php (เฉพาะปุ่มด้านล่างนี้ ถ้ามีปุ่มอยู่แล้ว เปลี่ยน id ให้เป็น btnSidebar) -->
<button class="nav-icon" id="btnSidebar" aria-label="เปิดเมนู" aria-expanded="false">
  <span class="nav-icon-bar"></span>
  <span class="nav-icon-bar"></span>
  <span class="nav-icon-bar"></span>
</button>
