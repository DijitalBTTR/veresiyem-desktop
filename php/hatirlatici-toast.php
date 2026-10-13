<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

if (!girisYapildi()) { echo json_encode(['ok'=>false,'data'=>[]]); exit; }

$uid  = userId();
$saat = date('H:i');

// Bugün aktif hatırlatıcıları çek
$stmt = $pdo->prepare("
    SELECT id, baslik, aciklama, tarih, saat, hatirlatma_dk
    FROM hatirlaticilar
    WHERE user_id = ?
      AND durum = 'aktif'
      AND tarih = date('now','localtime')
    ORDER BY saat ASC
");
$stmt->execute([$uid]);
$tumHatirlaticilar = $stmt->fetchAll();

$simdiTs = strtotime(date('Y-m-d') . ' ' . $saat);
$sonuclar = [];

foreach ($tumHatirlaticilar as $h) {
    $hatirlatma_dk = (int)($h['hatirlatma_dk'] ?? 0);
    $hatirlaticiTs = strtotime(date('Y-m-d') . ' ' . $h['saat']);

    // Hatırlatma zamanı = hatırlatıcı saati - hatirlatma_dk
    $bildirimTs = $hatirlaticiTs - ($hatirlatma_dk * 60);

    // Şu an bildirim zamanı ile hatırlatıcı saati arasındaysa göster
    // (2 dakikalık pencere — her dakika kontrol edildiği için yeterli)
    if ($simdiTs >= $bildirimTs && $simdiTs < ($bildirimTs + 120)) {
        $kalanSaniye = $hatirlaticiTs - $simdiTs;
        $kalanDk = max(0, (int)ceil($kalanSaniye / 60));
        $sonuclar[] = [
            'id'          => $h['id'],
            'baslik'      => $h['baslik'],
            'aciklama'    => $h['aciklama'],
            'saat'        => $h['saat'],
            'kalan_dk'    => $kalanDk,
            'hatirlatma_dk' => $hatirlatma_dk,
        ];
    }
}

echo json_encode(['ok' => true, 'data' => $sonuclar]);
