<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    // Kategoriyi sil (kayıtlarda kategori_id NULL kalır — ON DELETE SET NULL)
    $pdo->prepare("DELETE FROM gelir_gider_kategoriler WHERE id=? AND user_id=?")->execute([$id, $uid]);
}

header('Location: /gelir-gider');
exit;
