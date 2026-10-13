<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT id FROM tedarikciler WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $uid]);
if ($stmt->fetch()) {
    $pdo->prepare("DELETE FROM tedarikciler WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
}
header('Location: /tedarikciler');
exit;
