<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();

header('Content-Type: application/json; charset=utf-8');

$uid = userId();
$q   = trim($_GET['q'] ?? '');

if ($q !== '') {
    $stmt = $pdo->prepare("SELECT id, ad, birim, miktar, fiyat FROM stoklar WHERE user_id = ? AND ad LIKE ? ORDER BY ad ASC LIMIT 20");
    $stmt->execute([$uid, '%' . $q . '%']);
} else {
    $stmt = $pdo->prepare("SELECT id, ad, birim, miktar, fiyat FROM stoklar WHERE user_id = ? ORDER BY ad ASC");
    $stmt->execute([$uid]);
}
$stoklar = $stmt->fetchAll();

echo json_encode($stoklar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
