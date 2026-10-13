<?php
/**
 * Cari bakiye ve ekstre hesapları — tüm ekranlar aynı kuralı kullansın diye tek yerde.
 *
 * Kurallar:
 *   borc hareketi (vadeli / taksitli / iş takip)       → Borç
 *   alacak hareketi, grup istakip_ / taksitli_         → Alacak (ödeme: iş tahsilatı, peşinat, taksit)
 *   alacak hareketi, diğer (nakit satış)               → Borç + Alacak (peşin ödendi, bakiyeyi etkilemez)
 *   tahsilatlar tablosu                                 → Alacak
 *   Bakiye = Borç − Alacak  (pozitif: müşteri borçlu)
 */

// COALESCE: grup_id NULL iken NOT(...) da NULL dönüp nakit satışlar kaybolmasın
const BAKIYE_ODEME_KOSULU = "(COALESCE(h.grup_id,'') LIKE 'istakip_%' OR COALESCE(h.grup_id,'') LIKE 'taksitli_%')";

/** Kullanıcının tüm carileri için [cari_id => ['borc','alacak','bakiye','son_islem']] */
function cariBakiyeleri(PDO $pdo, int $uid, string $tarihBit = ''): array {
    $hTarih = $tarihBit ? "AND substr(h.tarih,1,10) <= :bit" : '';
    $tTarih = $tarihBit ? "AND substr(t.tarih,1,10) <= :bit" : '';
    $odeme  = BAKIYE_ODEME_KOSULU;

    $stmt = $pdo->prepare("
        SELECT c.id,
          COALESCE(SUM(CASE WHEN h.tip='borc' THEN h.tutar END),0)                    AS borc,
          COALESCE(SUM(CASE WHEN h.tip='alacak' AND $odeme THEN h.tutar END),0)       AS odeme,
          COALESCE(SUM(CASE WHEN h.tip='alacak' AND NOT $odeme THEN h.tutar END),0)   AS nakit,
          MAX(h.tarih) AS son_hareket
        FROM cariler c
        LEFT JOIN hareketler h ON h.cari_id = c.id $hTarih
        WHERE c.user_id = :uid
        GROUP BY c.id");
    $p = [':uid' => $uid];
    if ($tarihBit) $p[':bit'] = $tarihBit;
    $stmt->execute($p);

    $tStmt = $pdo->prepare("
        SELECT t.cari_id, COALESCE(SUM(t.tutar),0) AS tahsilat, MAX(t.tarih) AS son_tahsilat
        FROM tahsilatlar t JOIN cariler c ON c.id = t.cari_id AND c.user_id = :uid
        WHERE 1=1 $tTarih
        GROUP BY t.cari_id");
    $tStmt->execute($p);
    $tah = [];
    foreach ($tStmt->fetchAll() as $t) $tah[$t['cari_id']] = $t;

    $sonuc = [];
    foreach ($stmt->fetchAll() as $r) {
        $t       = $tah[$r['id']] ?? ['tahsilat' => 0, 'son_tahsilat' => null];
        $borc    = (float)$r['borc'] + (float)$r['nakit'];
        $alacak  = (float)$r['nakit'] + (float)$r['odeme'] + (float)$t['tahsilat'];
        $son     = max((string)$r['son_hareket'], (string)$t['son_tahsilat']);
        $sonuc[(int)$r['id']] = [
            'borc'      => round($borc, 2),
            'alacak'    => round($alacak, 2),
            'bakiye'    => round($borc - $alacak, 2),
            'son_islem' => $son !== '' ? substr($son, 0, 10) : null,
        ];
    }
    return $sonuc;
}

/** Tek cari için bakiye */
function cariBakiye(PDO $pdo, int $uid, int $cariId): array {
    return cariBakiyeleri($pdo, $uid)[$cariId] ?? ['borc' => 0, 'alacak' => 0, 'bakiye' => 0, 'son_islem' => null];
}

/**
 * Bir carinin ekstre satırları (tarih sırasıyla, yürüyen bakiye hariç).
 * Her satır: tarih, tur, aciklama, detay[], borc, alacak
 */
function cariEkstreSatirlari(PDO $pdo, int $cariId): array {
    $fmt = fn($v) => number_format((float)$v, 2, ',', '.');
    $mik = fn($v) => rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',');

    $hStmt = $pdo->prepare("
        SELECT h.*, s.ad AS stok_ad, s.birim AS stok_birim
        FROM hareketler h LEFT JOIN stoklar s ON s.id = h.stok_id
        WHERE h.cari_id = ? ORDER BY h.tarih ASC, h.id ASC");
    $hStmt->execute([$cariId]);
    $hareketler = $hStmt->fetchAll();

    // İş takip detayları
    $isMap = [];
    try {
        $iStmt = $pdo->prepare("SELECT * FROM is_takip WHERE cari_id = ?");
        $iStmt->execute([$cariId]);
        foreach ($iStmt->fetchAll() as $i) $isMap['istakip_' . $i['id']] = $i;
    } catch (Exception $e) {}

    // Taksitli satış kalemleri
    $taksitKalem = [];
    try {
        $kStmt = $pdo->prepare("SELECT k.* FROM taksitli_satis_kalemleri k JOIN taksitli_satislar s ON s.id = k.satis_id WHERE s.cari_id = ? ORDER BY k.id");
        $kStmt->execute([$cariId]);
        foreach ($kStmt->fetchAll() as $k) $taksitKalem['taksitli_' . $k['satis_id']][] = $k;
    } catch (Exception $e) {}

    $satirlar = [];
    $gruplar  = [];

    foreach ($hareketler as $h) {
        $g      = (string)($h['grup_id'] ?? '');
        $isIs   = strpos($g, 'istakip_') === 0;
        $isTak  = strpos($g, 'taksitli_') === 0;
        $tutar  = (float)$h['tutar'];
        $kalem  = ($h['stok_ad'] ?: ($h['aciklama'] ?: '—'))
                . ($h['stok_miktar'] ? ' — ' . $mik($h['stok_miktar']) . ' ' . ($h['stok_birim'] ?? '') : '');

        // Sepet / hızlı satış grupları tek satırda toplanır
        if ($g !== '' && !$isIs && !$isTak) {
            $key = $g . '|' . $h['tip'];
            if (!isset($gruplar[$key])) {
                $gruplar[$key] = count($satirlar);
                $satirlar[] = [
                    'tarih' => substr($h['tarih'], 0, 10), 'sira' => 0, 'id' => (int)$h['id'],
                    'tur' => $h['tip'] === 'borc' ? 'Vadeli Satış' : 'Nakit Satış',
                    'aciklama' => '', 'detay' => [], 'borc' => 0, 'alacak' => 0,
                ];
            }
            $i = $gruplar[$key];
            $satirlar[$i]['detay'][] = $kalem . ' : ₺' . $fmt($tutar);
            $satirlar[$i]['borc']   += $tutar;
            if ($h['tip'] === 'alacak') $satirlar[$i]['alacak'] += $tutar;
            continue;
        }

        $satir = [
            'tarih' => substr($h['tarih'], 0, 10), 'sira' => 0, 'id' => (int)$h['id'],
            'tur' => '', 'aciklama' => '', 'detay' => [], 'borc' => 0, 'alacak' => 0,
        ];

        if ($isIs) {
            $is = $isMap[$g] ?? null;
            if ($h['tip'] === 'borc') {
                $satir['tur']  = 'İş';
                $satir['borc'] = $tutar;
                if ($is) {
                    if ($is['tarih']) $satir['tarih'] = substr($is['tarih'], 0, 10);
                    $satir['aciklama'] = trim(($is['urun_cinsi'] ?? '') . ($is['takip_no'] ? '  (Takip No: ' . $is['takip_no'] . ')' : ''));
                    foreach (['aciklama', 'aciklama2'] as $f) if (!empty($is[$f])) $satir['detay'][] = $is[$f];
                    $olcu = [];
                    if (!empty($is['olcu'])) $olcu[] = 'Ölçü: ' . $is['olcu'];
                    if ($is['miktar'] !== null && $is['miktar'] !== '') {
                        $m = 'Miktar: ' . $mik($is['miktar']) . ' ' . ($is['birim'] ?? '');
                        if (!empty($is['birim_fiyat'])) $m .= ' × ₺' . $fmt($is['birim_fiyat']);
                        $olcu[] = trim($m);
                    }
                    if ($olcu) $satir['detay'][] = implode('   ', $olcu);
                } else {
                    $satir['aciklama'] = $h['aciklama'] ?? '';
                }
            } else {
                $satir['tur']      = 'Ödeme';
                $satir['alacak']   = $tutar;
                $satir['aciklama'] = $h['aciklama'] ?? 'İş tahsilatı';
            }
        } elseif ($isTak) {
            if ($h['tip'] === 'borc') {
                $satir['tur']      = 'Taksitli Satış';
                $satir['borc']     = $tutar;
                $satir['aciklama'] = $h['aciklama'] ?? '';
                foreach ($taksitKalem[$g] ?? [] as $k) {
                    $satir['detay'][] = $k['urun_ad'] . ' — ' . $mik($k['miktar']) . ' ' . ($k['birim'] ?? '')
                        . ' × ₺' . $fmt($k['birim_fiyat']) . ' : ₺' . $fmt($k['toplam']);
                }
            } else {
                $satir['tur']      = 'Ödeme';
                $satir['alacak']   = $tutar;
                $satir['aciklama'] = $h['aciklama'] ?? 'Taksit ödemesi';
            }
        } elseif ($h['tip'] === 'borc') {
            $satir['tur']      = 'Vadeli Satış';
            $satir['borc']     = $tutar;
            $satir['aciklama'] = $kalem;
            if ($h['stok_ad'] && $h['aciklama']) $satir['detay'][] = $h['aciklama'];
            if ($h['vade_tarihi']) $satir['detay'][] = 'Vade: ' . date('d.m.Y', strtotime($h['vade_tarihi']));
        } else {
            $satir['tur']      = 'Nakit Satış';
            $satir['borc']     = $tutar;
            $satir['alacak']   = $tutar;
            $satir['aciklama'] = $kalem;
            if ($h['stok_ad'] && $h['aciklama']) $satir['detay'][] = $h['aciklama'];
        }
        $satirlar[] = $satir;
    }

    // Tahsilatlar
    $tStmt = $pdo->prepare("
        SELECT t.*, h.aciklama AS h_aciklama, h.tarih AS h_tarih
        FROM tahsilatlar t LEFT JOIN hareketler h ON h.id = t.hareket_id
        WHERE t.cari_id = ? ORDER BY t.tarih ASC, t.id ASC");
    $tStmt->execute([$cariId]);
    foreach ($tStmt->fetchAll() as $t) {
        $satirlar[] = [
            'tarih' => substr($t['tarih'], 0, 10), 'sira' => 1, 'id' => (int)$t['id'],
            'tur' => 'Tahsilat',
            'aciklama' => $t['aciklama'] ?: 'Tahsilat',
            'detay' => $t['h_tarih'] ? [date('d.m.Y', strtotime($t['h_tarih'])) . ' tarihli işlem' . ($t['h_aciklama'] ? ': ' . $t['h_aciklama'] : '')] : [],
            'borc' => 0, 'alacak' => (float)$t['tutar'],
        ];
    }

    usort($satirlar, fn($a, $b) => [$a['tarih'], $a['sira'], $a['id']] <=> [$b['tarih'], $b['sira'], $b['id']]);

    foreach ($satirlar as &$s) {
        if ($s['aciklama'] === '' && $s['detay']) $s['aciklama'] = count($s['detay']) . ' kalem';
        $s['borc']   = round($s['borc'], 2);
        $s['alacak'] = round($s['alacak'], 2);
    }
    unset($s);
    return $satirlar;
}
