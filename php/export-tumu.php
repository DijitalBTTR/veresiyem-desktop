<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();

if (demoMod()) {
    header('Location: /lisans');
    exit;
}

$uid = userId();

// Tüm cariler
$cariStmt = $pdo->prepare("SELECT id, ad, telefon FROM cariler WHERE user_id = ? ORDER BY ad ASC");
$cariStmt->execute([$uid]);
$cariler = $cariStmt->fetchAll();

$cariIds = array_column($cariler, 'id');

// Hareketler
$hareketler = [];
if (!empty($cariIds)) {
    $placeholders = implode(',', array_fill(0, count($cariIds), '?'));
    $hStmt = $pdo->prepare(
        "SELECT h.id, h.cari_id, h.grup_id, h.tarih, h.tip, h.tutar, h.aciklama,
                s.ad AS stok_ad, h.stok_miktar, s.birim AS stok_birim
         FROM hareketler h
         LEFT JOIN stoklar s ON s.id = h.stok_id
         WHERE h.cari_id IN ($placeholders)
         ORDER BY h.cari_id ASC, h.tarih ASC, h.id ASC"
    );
    $hStmt->execute($cariIds);
    foreach ($hStmt->fetchAll() as $h) {
        $hareketler[$h['cari_id']][] = $h;
    }
}

// Tahsilatlar (hareket_id → toplam tahsilat)
$tahsilatlar = [];
if (!empty($cariIds)) {
    $placeholders = implode(',', array_fill(0, count($cariIds), '?'));
    $tStmt = $pdo->prepare(
        "SELECT t.hareket_id, SUM(t.tutar) AS toplam_tahsilat
         FROM tahsilatlar t
         WHERE t.cari_id IN ($placeholders)
         GROUP BY t.hareket_id"
    );
    $tStmt->execute($cariIds);
    foreach ($tStmt->fetchAll() as $t) {
        $tahsilatlar[$t['hareket_id']] = (float)$t['toplam_tahsilat'];
    }
}

$dosyaAd = 'veresiyem_tumu_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $dosyaAd . '"');

// UTF-8 BOM (Excel için)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// ── BÖLÜM 1: Cari Özet ──────────────────────────────────────────
fputcsv($out, ['=== CARİ ÖZET ==='], ';');
fputcsv($out, ['Cari Adı', 'Telefon', 'Toplam Borç (TL)', 'Toplam Tahsilat (TL)', 'Kalan Bakiye (TL)'], ';');

foreach ($cariler as $c) {
    $cariHareketler = $hareketler[$c['id']] ?? [];
    $toplamBorc = 0;
    $toplamTahsilat = 0;
    foreach ($cariHareketler as $h) {
        if ($h['tip'] === 'borc') {
            $toplamBorc += (float)$h['tutar'];
        } else {
            $toplamBorc -= (float)$h['tutar']; // nakit satış borcu azaltır
        }
        $toplamTahsilat += $tahsilatlar[$h['id']] ?? 0;
    }
    $kalanBakiye = $toplamBorc - $toplamTahsilat;
    fputcsv($out, [
        $c['ad'],
        $c['telefon'] ?? '',
        number_format($toplamBorc, 2, ',', '.'),
        number_format($toplamTahsilat, 2, ',', '.'),
        number_format($kalanBakiye, 2, ',', '.'),
    ], ';');
}

// Boş satır
fputcsv($out, [], ';');
fputcsv($out, [], ';');

// ── BÖLÜM 2: Detaylı Hareketler ─────────────────────────────────
fputcsv($out, ['=== DETAYLI HAREKETLer ==='], ';');
fputcsv($out, [
    'Cari Adı', 'Telefon', 'Tarih', 'Tür', 'Tutar (TL)',
    'Tahsilat (TL)', 'Kalan (TL)', 'Açıklama', 'Stok', 'Miktar', 'Birim'
], ';');

foreach ($cariler as $c) {
    $cariHareketler = $hareketler[$c['id']] ?? [];
    if (empty($cariHareketler)) {
        fputcsv($out, [$c['ad'], $c['telefon'] ?? '', '', '', '', '', '', 'Hareket yok', '', '', ''], ';');
    } else {
        foreach ($cariHareketler as $h) {
            $tahsilat = $tahsilatlar[$h['id']] ?? 0;
            $tutar    = (float)$h['tutar'];
            $kalan    = ($h['tip'] === 'borc') ? ($tutar - $tahsilat) : 0;

            if ($h['tip'] === 'borc') {
                $tur = 'Vadeli Satış';
            } elseif ((float)$h['tutar'] < 0) {
                $tur = 'İndirim';
            } else {
                $tur = 'Nakit Satış';
            }

            fputcsv($out, [
                $c['ad'],
                $c['telefon'] ?? '',
                $h['tarih'],
                $tur,
                number_format($tutar, 2, ',', '.'),
                number_format($tahsilat, 2, ',', '.'),
                number_format($kalan, 2, ',', '.'),
                $h['aciklama'] ?? '',
                $h['stok_ad'] ?? '',
                $h['stok_miktar'] !== null ? number_format((float)$h['stok_miktar'], 2, ',', '.') : '',
                $h['stok_birim'] ?? '',
            ], ';');
        }
    }
    fputcsv($out, [], ';');
}

// ── BÖLÜM 3: Stok Listesi ───────────────────────────────────────
fputcsv($out, [], ';');
fputcsv($out, ['=== STOK LİSTESİ ==='], ';');
fputcsv($out, ['Stok Adı', 'Birim', 'Mevcut Miktar', 'Alış Fiyatı (TL)', 'Nakit Fiyatı (TL)', 'Vadeli Fiyatı (TL)', 'Açıklama'], ';');

$stokStmt = $pdo->prepare("SELECT ad, birim, miktar, alis_fiyat, nakit_fiyat, vadeli_fiyat, aciklama FROM stoklar WHERE user_id=? ORDER BY ad ASC");
$stokStmt->execute([$uid]);
foreach ($stokStmt->fetchAll() as $s) {
    fputcsv($out, [
        $s['ad'],
        $s['birim'],
        number_format((float)$s['miktar'], 2, ',', '.'),
        $s['alis_fiyat']  !== null ? number_format((float)$s['alis_fiyat'],  2, ',', '.') : '',
        $s['nakit_fiyat'] !== null ? number_format((float)$s['nakit_fiyat'], 2, ',', '.') : '',
        $s['vadeli_fiyat']!== null ? number_format((float)$s['vadeli_fiyat'],2, ',', '.') : '',
        $s['aciklama'] ?? '',
    ], ';');
}

fclose($out);
