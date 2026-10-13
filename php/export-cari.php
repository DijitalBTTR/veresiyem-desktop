<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();

if (demoMod()) {
    header('Location: /lisans');
    exit;
}

$id  = (int)($_GET['id'] ?? 0);
$uid = userId();

if (!$id) { header('Location: /cariler'); exit; }

$cariStmt = $pdo->prepare("SELECT * FROM cariler WHERE id = ? AND user_id = ?");
$cariStmt->execute([$id, $uid]);
$cari = $cariStmt->fetch();
if (!$cari) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$hStmt = $pdo->prepare(
    "SELECT h.tarih, h.tip, h.tutar, h.aciklama, s.ad AS stok_ad, h.stok_miktar, s.birim AS stok_birim
     FROM hareketler h
     LEFT JOIN stoklar s ON s.id = h.stok_id
     WHERE h.cari_id = ?
     ORDER BY h.tarih ASC, h.id ASC"
);
$hStmt->execute([$id]);
$hareketler = $hStmt->fetchAll();

$dosyaAd = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cari['ad']) . '_' . date('Ymd') . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $dosyaAd . '"');

// Başlık bilgisi
echo "CARİ HESAP RAPORU\r\n";
echo "Cari: " . $cari['ad'] . "\r\n";
if ($cari['telefon']) echo "Telefon: " . $cari['telefon'] . "\r\n";
echo "Tarih: " . date('d.m.Y H:i') . "\r\n";
echo str_repeat("-", 70) . "\r\n\r\n";

// Sütun başlıkları (tab-separated)
echo "Tarih\tTip\tTutar (TL)\tAçıklama\tStok\tStok Miktar\tBirim\r\n";

$toplamBorc   = 0;
$toplamAlacak = 0;

foreach ($hareketler as $h) {
    $tip = $h['tip'] === 'borc' ? 'Vadeli Satış' : 'Nakit Satış';
    if ($h['tip'] === 'borc') $toplamBorc += $h['tutar'];
    else $toplamAlacak += $h['tutar'];

    echo implode("\t", [
        $h['tarih'],
        $tip,
        number_format($h['tutar'], 2, ',', '.'),
        $h['aciklama'] ?? '',
        $h['stok_ad'] ?? '',
        $h['stok_miktar'] !== null ? number_format($h['stok_miktar'], 2, ',', '.') : '',
        $h['stok_birim'] ?? '',
    ]) . "\r\n";
}

echo "\r\n";
echo str_repeat("-", 70) . "\r\n";
echo "Toplam Vadeli Satış\t" . number_format($toplamBorc, 2, ',', '.') . " TL\r\n";
echo "Toplam Nakit Satış\t" . number_format($toplamAlacak, 2, ',', '.') . " TL\r\n";
$bakiye = $toplamBorc - $toplamAlacak;
echo "Net Bakiye\t" . number_format(abs($bakiye), 2, ',', '.') . " TL " . ($bakiye > 0 ? "(Vadeli)" : ($bakiye < 0 ? "(Nakit)" : "(Sıfır)")) . "\r\n";
