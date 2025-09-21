<?php
require __DIR__ . '/config.php';
$_SESSION = [];
session_destroy();
header('Location: index.php?ok=' . urlencode('ออกจากระบบเรียบร้อย')); exit;
