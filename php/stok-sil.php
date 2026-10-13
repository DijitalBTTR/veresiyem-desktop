<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /stok');
    exit;
}

csrfKontrol();

$id  = (int)($_POST['id'] ?? 0);
$uid = userId();

if ($id) {
    require_once __DIR__ . '/includes/sync.php';
    $row = $pdo->prepare("SELECT api_id FROM stoklar WHERE id=? AND user_id=?");
    $row->execute([$id, $uid]);
    $row = $row->fetch();
    $stmt = $pdo->prepare("DELETE FROM stoklar WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $uid]);
    if ($stmt->rowCount() && !empty($row['api_id'])) vpsSil('/stok/' . $row['api_id']);
}

header('Location: /stok');
exit;
