<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

if (!girisYapildi()) { echo json_encode(['ok'=>false,'data'=>[]]); exit; }

$uid = userId();
$simdi = date('Y-m-d H:i');
$tarih = date('Y-m-d');
$saat  = date('H:i');

// Bugün, saati gelmiş veya geçmiş, henüz bildirilmemiş aktif hatırlatıcılar
$stmt = $pdo->prepare("
    SELECT id, baslik, aciklama, tarih, saat, hatirlatma_dk
    FROM hatirlaticilar
    WHERE user_id = ?
      AND durum = 'aktif'
      AND tarih = ?
      AND saat <= ?
      AND (bildirim_gonderildi IS NULL OR bildirim_gonderildi = 0)
");
$stmt->execute([$uid, $tarih, $saat]);
$rows = $stmt->fetchAll();

if ($rows) {
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $pdo->prepare("UPDATE hatirlaticilar SET bildirim_gonderildi = 1 WHERE id IN ($placeholders)")
            ->execute($ids);
    } catch (Exception $e) {
        // bildirim_gonderildi kolonu yoksa ekle
        $pdo->exec("ALTER TABLE hatirlaticilar ADD COLUMN bildirim_gonderildi INTEGER DEFAULT 0");
        $pdo->prepare("UPDATE hatirlaticilar SET bildirim_gonderildi = 1 WHERE id IN ($placeholders)")
            ->execute($ids);
    }
}

echo json_encode(['ok'=>true,'data'=>$rows]);
