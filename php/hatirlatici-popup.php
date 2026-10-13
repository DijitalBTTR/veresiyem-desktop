<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

if (!girisYapildi()) { echo json_encode(['ok'=>false,'data'=>[]]); exit; }

$uid      = userId();
$saat     = date('H:i');
// Son 2 dakika içinde saati gelen hatırlatıcılar (her dakika kontrol edildiği için 2dk yeterli)
$saatFrom = date('H:i', strtotime('-2 minutes'));

$stmt = $pdo->prepare("
    SELECT id, baslik, aciklama, tarih, saat
    FROM hatirlaticilar
    WHERE user_id = ?
      AND durum = 'aktif'
      AND tarih = date('now','localtime')
      AND saat >= ?
      AND saat <= ?
    ORDER BY saat ASC
");
$stmt->execute([$uid, $saatFrom, $saat]);
$rows = $stmt->fetchAll();

echo json_encode(['ok' => true, 'data' => $rows]);
