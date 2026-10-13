<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$uid = userId();

$id    = (int)($_GET['id'] ?? 0);
$durum = $_GET['durum'] ?? '';
if (!in_array($durum, ['odendi','odenmedi'])) { header('Location: /faturalar'); exit; }

$stmt = $pdo->prepare("
    SELECT f.id FROM faturalar f
    JOIN tedarikciler t ON t.id = f.tedarikci_id
    WHERE f.id = ? AND t.user_id = ?
");
$stmt->execute([$id, $uid]);
if ($stmt->fetch()) {
    $pdo->prepare("UPDATE faturalar SET durum = ? WHERE id = ?")->execute([$durum, $id]);
}
header('Location: /fatura?id=' . $id);
exit;
