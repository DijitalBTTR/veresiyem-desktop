<?php
require_once 'includes/auth.php';

// Demo temp DB'yi temizle
if (!empty($_SESSION['demo_db_path']) && file_exists($_SESSION['demo_db_path'])) {
    @unlink($_SESSION['demo_db_path']);
    // WAL dosyaları da sil
    @unlink($_SESSION['demo_db_path'] . '-wal');
    @unlink($_SESSION['demo_db_path'] . '-shm');
}

session_destroy();
header('Location: /giris');
exit;
