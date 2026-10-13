<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT f.id, f.tedarikci_id FROM faturalar f
    JOIN tedarikciler t ON t.id = f.tedarikci_id
    WHERE f.id = ? AND t.user_id = ?
");
$stmt->execute([$id, $uid]);
$f = $stmt->fetch();
if ($f) {
    $pdo->prepare("DELETE FROM faturalar WHERE id = ?")->execute([$id]);
    header('Location: /tedarikci?id=' . $f['tedarikci_id']);
    exit;
}
header('Location: /faturalar');
exit;
