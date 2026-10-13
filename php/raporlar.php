<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'raporlar';
$uid = userId();

$sekme = $_GET['sekme'] ?? 'ekstre';

// Tarih filtresi (erken tanımla — tüm sorgular kullanır)
$tarihBas = trim($_GET['tarih_bas'] ?? '');
$tarihBit = trim($_GET['tarih_bit'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarihBas)) $tarihBas = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarihBit)) $tarihBit = '';

// ── Satış Kâr Raporu ─────────────────────────────────────────
$satisRaporu = [];
$satisOzet   = ['nakit' => 0, 'vadeli' => 0, 'toplam' => 0, 'maliyet' => 0, 'kar' => 0];
if ($sekme === 'satis-kar') {
    $params = [];
    $hWhere = "WHERE s.user_id = ? AND h.stok_id IS NOT NULL";
    $params[] = $uid;
    if ($tarihBas) { $hWhere .= " AND h.tarih >= ?"; $params[] = $tarihBas; }
    if ($tarihBit) { $hWhere .= " AND h.tarih <= ?"; $params[] = $tarihBit; }

    $sStmt = $pdo->prepare("
        SELECT
            h.id, h.tip, h.tutar, h.tarih, h.stok_miktar, h.aciklama,
            s.ad AS stok_ad, s.birim AS stok_birim,
            s.alis_fiyat,
            c.ad AS cari_ad
        FROM hareketler h
        JOIN stoklar s ON s.id = h.stok_id
        JOIN cariler c ON c.id = h.cari_id
        $hWhere
        ORDER BY h.tarih DESC, h.id DESC
    ");
    $sStmt->execute($params);
    $satisRaporu = $sStmt->fetchAll();

    foreach ($satisRaporu as $r) {
        $adet    = (float)($r['stok_miktar'] ?? 0);
        $maliyet = $adet * (float)($r['alis_fiyat'] ?? 0);
        if ($r['tip'] === 'alacak') $satisOzet['nakit']  += $r['tutar'];
        else                        $satisOzet['vadeli'] += $r['tutar'];
        $satisOzet['maliyet'] += $maliyet;
    }
    $satisOzet['toplam'] = $satisOzet['nakit'] + $satisOzet['vadeli'];
    $satisOzet['kar']    = $satisOzet['toplam'] - $satisOzet['maliyet'];
}

// ── Cari Ekstre ──────────────────────────────────────────────
$cariler = $pdo->prepare("SELECT id, ad, telefon FROM cariler WHERE user_id=? ORDER BY ad ASC");
$cariler->execute([$uid]);
$cariler = $cariler->fetchAll();

$secilenCariId    = (int)($_GET['cari_id'] ?? 0);
$secilenCari      = null;
$ekstreHareketler = [];
$ekstreToplam     = ['nakit' => 0, 'vadeli' => 0, 'tahsilat' => 0];

if ($secilenCariId) {
    $cStmt = $pdo->prepare("SELECT * FROM cariler WHERE id=? AND user_id=?");
    $cStmt->execute([$secilenCariId, $uid]);
    $secilenCari = $cStmt->fetch();

    if ($secilenCari) {
        $params = [$secilenCariId];
        $where  = "WHERE h.cari_id = ?";
        if ($tarihBas) { $where .= " AND h.tarih >= ?"; $params[] = $tarihBas; }
        if ($tarihBit) { $where .= " AND h.tarih <= ?"; $params[] = $tarihBit; }

        $hStmt = $pdo->prepare("
            SELECT h.*, s.ad AS stok_ad, s.birim AS stok_birim
            FROM hareketler h
            LEFT JOIN stoklar s ON s.id = h.stok_id
            $where
            ORDER BY h.tarih ASC, h.id ASC
        ");
        $hStmt->execute($params);
        $ekstreHareketler = $hStmt->fetchAll();

        // Tahsilatları hareket bazında topla (tarih filtresi yok — tüm tahsilatları göster)
        $hIds = array_column($ekstreHareketler, 'id');
        $tahsilatMap = [];
        if ($hIds) {
            $inStr = implode(',', array_map('intval', $hIds));
            $tRows = $pdo->query("SELECT hareket_id, SUM(tutar) AS toplam FROM tahsilatlar WHERE hareket_id IN ($inStr) GROUP BY hareket_id")->fetchAll();
            foreach ($tRows as $t) $tahsilatMap[$t['hareket_id']] = (float)$t['toplam'];
        }

        $ekstreToplam['tahsilat'] = array_sum($tahsilatMap);
        foreach ($ekstreHareketler as $h) {
            if ($h['tip'] === 'alacak') $ekstreToplam['nakit']  += $h['tutar'];
            else                        $ekstreToplam['vadeli'] += $h['tutar'];
        }
    }
}

// ── Stok Raporu ──────────────────────────────────────────────
$stokRaporu = [];
if ($sekme === 'stok') {
    $params = [$uid];
    $hWhere = "";
    if ($tarihBas) { $hWhere .= " AND h.tarih >= ?"; $params[] = $tarihBas; }
    if ($tarihBit) { $hWhere .= " AND h.tarih <= ?"; $params[] = $tarihBit; }

    $sStmt = $pdo->prepare("
        SELECT
            s.id, s.ad, s.birim, s.miktar AS kalan_miktar,
            s.fiyat, s.alis_fiyat, s.nakit_fiyat, s.vadeli_fiyat,
            COALESCE(SUM(CASE WHEN h.tip='alacak' THEN h.stok_miktar ELSE 0 END), 0) AS nakit_adet,
            COALESCE(SUM(CASE WHEN h.tip='borc'   THEN h.stok_miktar ELSE 0 END), 0) AS vadeli_adet,
            COALESCE(SUM(CASE WHEN h.tip='alacak' THEN h.tutar ELSE 0 END), 0) AS nakit_ciro,
            COALESCE(SUM(CASE WHEN h.tip='borc'   THEN h.tutar ELSE 0 END), 0) AS vadeli_ciro
        FROM stoklar s
        LEFT JOIN hareketler h ON h.stok_id = s.id $hWhere
        WHERE s.user_id = ?
        GROUP BY s.id
        ORDER BY (nakit_adet + vadeli_adet) DESC, s.ad ASC
    ");
    // user_id WHERE sona geldiği için params sırası: tarih params + uid
    $stokParams = [];
    if ($tarihBas) $stokParams[] = $tarihBas;
    if ($tarihBit) $stokParams[] = $tarihBit;
    $stokParams[] = $uid;
    $sStmt->execute($stokParams);
    $stokRaporu = $sStmt->fetchAll();
}

// ── Kasa Raporu ──────────────────────────────────────────────
$kasaRaporu     = [];
$kasaHesaplari  = [];
$kasaOzet       = ['giris' => 0, 'cikis' => 0];
$kasaAylikOzet  = [];
$kasaSecHesap   = (int)($_GET['kasa_hesap'] ?? 0);

if ($sekme === 'kasa') {
    // Hesaplar
    $khQ = $pdo->prepare("SELECT * FROM kasa_hesaplari WHERE user_id=? ORDER BY id ASC");
    $khQ->execute([$uid]);
    $kasaHesaplari = $khQ->fetchAll();

    // Hareketler
    $kWhere  = "WHERE k.user_id=?";
    $kParams = [$uid];
    if ($kasaSecHesap) { $kWhere .= " AND k.hesap_id=?"; $kParams[] = $kasaSecHesap; }
    if ($tarihBas)     { $kWhere .= " AND k.tarih>=?";   $kParams[] = $tarihBas; }
    if ($tarihBit)     { $kWhere .= " AND k.tarih<=?";   $kParams[] = $tarihBit; }

    $kQ = $pdo->prepare("SELECT k.*, kh.ad AS hesap_ad, kh.renk AS hesap_renk, c.ad AS cari_ad
        FROM kasa k
        LEFT JOIN kasa_hesaplari kh ON kh.id=k.hesap_id
        LEFT JOIN cariler c ON c.id=k.kaynak_id AND k.kaynak IN ('tahsilat','hizli_satis')
        $kWhere ORDER BY k.tarih DESC, k.id DESC LIMIT 500");
    $kQ->execute($kParams);
    $kasaRaporu = $kQ->fetchAll();

    foreach ($kasaRaporu as $r) {
        if ($r['tip'] === 'giris') $kasaOzet['giris'] += $r['tutar'];
        else                       $kasaOzet['cikis'] += $r['tutar'];
    }

    // Aylık özet — $kWhere k. prefix'li, alias gerekli
    $ayWhere = str_replace('k.', '', $kWhere);
    $ayQ = $pdo->prepare("SELECT strftime('%Y-%m', tarih) AS ay,
        COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE 0 END),0) AS giris,
        COALESCE(SUM(CASE WHEN tip='cikis' THEN tutar ELSE 0 END),0) AS cikis
        FROM kasa $ayWhere GROUP BY ay ORDER BY ay DESC LIMIT 12");
    $ayQ->execute($kParams);
    $kasaAylikOzet = $ayQ->fetchAll();

    // Hesap bazlı bakiye
    $hbQ = $pdo->prepare("SELECT kh.id, kh.ad, kh.renk,
        COALESCE(SUM(CASE WHEN k.tip='giris' THEN k.tutar ELSE 0 END),0) AS giris,
        COALESCE(SUM(CASE WHEN k.tip='cikis' THEN k.tutar ELSE 0 END),0) AS cikis
        FROM kasa_hesaplari kh LEFT JOIN kasa k ON k.hesap_id=kh.id AND k.user_id=kh.user_id
        WHERE kh.user_id=? GROUP BY kh.id ORDER BY kh.id ASC");
    $hbQ->execute([$uid]);
    $kasaHesapOzet = $hbQ->fetchAll();
}

$bugun = date('Y-m-d');
$pageTitle = 'Raporlar';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Raporlar — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.rapor-sekme-bar { display:flex; gap:.5rem; margin-bottom:1.25rem; }
.rapor-sekme { padding:.45rem 1.1rem; border-radius:var(--r); font-size:.84rem; font-weight:600;
  border:1.5px solid var(--border); color:var(--ink2); text-decoration:none; transition:.12s; }
.rapor-sekme:hover { background:var(--surface2); border-color:var(--border2); }
.rapor-sekme.active { background:var(--green-lt); border-color:var(--green); color:var(--green); }

.ekstre-cari-sec { display:flex; gap:.75rem; align-items:flex-end; margin-bottom:1.25rem; flex-wrap:wrap; }
.ekstre-cari-sec select { min-width:220px; }

.ekstre-ozet { display:grid; grid-template-columns:repeat(5,1fr); gap:.75rem; margin-bottom:1.25rem; }
@media(max-width:900px){ .ekstre-ozet { grid-template-columns:1fr 1fr; } }
.ozet-kart { background:var(--surface); border:1px solid var(--border); border-radius:var(--r);
  padding:.85rem 1rem; }
.ozet-kart .ok-lbl { font-size:.72rem; color:var(--ink3); margin-bottom:.2rem; }
.ozet-kart .ok-val { font-size:1.05rem; font-weight:800; font-variant-numeric:tabular-nums; }

.ekstre-tablo th { white-space:nowrap; }
.durum-badge { display:inline-block; padding:.15rem .55rem; border-radius:4px; font-size:.72rem; font-weight:700; }
.db-nakit  { background:var(--green-lt); color:var(--green); }
.db-vadeli { background:var(--blue-lt);  color:var(--blue); }
.db-tahsil { background:#F3E8FF; color:#7C3AED; }

.stok-rapor-ozet { display:grid; grid-template-columns:repeat(4,1fr); gap:.75rem; margin-bottom:1.25rem; }
@media(max-width:900px){ .stok-rapor-ozet { grid-template-columns:1fr 1fr; } }

.kar-pozitif { color:var(--green); font-weight:700; }
.kar-negatif { color:var(--red);   font-weight:700; }
.text-right   { text-align:right; }
.text-center  { text-align:center; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Raporlar</div>
        <div class="page-sub">Cari ekstre, stok &amp; satış kâr analizi</div>
      </div>
    </div>

    <div class="page-body">

      <!-- Sekme bar -->
      <div class="rapor-sekme-bar">
        <a href="/raporlar?sekme=ekstre<?= $secilenCariId ? '&cari_id='.$secilenCariId : '' ?>"
           class="rapor-sekme <?= $sekme==='ekstre' ? 'active' : '' ?>">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:.3rem;vertical-align:middle"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          Cari Ekstre
        </a>
        <a href="/cari-ozet" class="rapor-sekme">Genel Borç-Alacak</a>
        <a href="/raporlar?sekme=stok"
           class="rapor-sekme <?= $sekme==='stok' ? 'active' : '' ?>">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:.3rem;vertical-align:middle"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
          Stok Raporu
        </a>
        <a href="/raporlar?sekme=satis-kar"
           class="rapor-sekme <?= $sekme==='satis-kar' ? 'active' : '' ?>">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:.3rem;vertical-align:middle"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
          Satış Kâr Raporu
        </a>
        <a href="/raporlar?sekme=kasa"
           class="rapor-sekme <?= $sekme==='kasa' ? 'active' : '' ?>">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:.3rem;vertical-align:middle"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
          Kasa Raporu
        </a>
      </div>

      <?php if ($sekme === 'ekstre'): ?>
      <!-- ── CARİ EKSTRE ── -->

      <form method="get" action="/raporlar" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem">
        <input type="hidden" name="sekme" value="ekstre">
        <div>
          <label class="form-label">Cari Seç</label>
          <select name="cari_id" class="form-input form-select" style="min-width:220px">
            <option value="">— Cari seçin —</option>
            <?php foreach ($cariler as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $c['id']==$secilenCariId ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['ad']) ?><?= $c['telefon'] ? ' · '.$c['telefon'] : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Başlangıç</label>
          <input type="date" name="tarih_bas" class="form-input" value="<?= htmlspecialchars($tarihBas) ?>">
        </div>
        <div>
          <label class="form-label">Bitiş</label>
          <input type="date" name="tarih_bit" class="form-input" value="<?= htmlspecialchars($tarihBit) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
        <?php if ($tarihBas || $tarihBit): ?>
          <a href="/raporlar?sekme=ekstre<?= $secilenCariId ? '&cari_id='.$secilenCariId : '' ?>" class="btn btn-ghost btn-sm">Temizle</a>
        <?php endif; ?>
        <?php if ($secilenCari): ?>
          <a href="/cari-ekstre?id=<?= $secilenCariId ?><?= $tarihBas ? '&bas='.$tarihBas : '' ?><?= $tarihBit ? '&bit='.$tarihBit : '' ?>" class="btn btn-primary btn-sm" style="margin-left:auto">Detaylı Ekstre (Borç/Alacak/Bakiye)</a>
          <button type="button" id="pdfBtn" onclick="ekstrePdfKaydet()" class="btn btn-secondary btn-sm">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
            PDF Kaydet
          </button>
        <?php endif; ?>
      </form>

      <?php if ($secilenCari): ?>
        <?php
          $kalanBorc = max(0, $ekstreToplam['vadeli'] - $ekstreToplam['tahsilat']);
          $toplamIslem = $ekstreToplam['nakit'] + $ekstreToplam['vadeli'];
        ?>

        <!-- Özet kartlar -->
        <div class="ekstre-ozet">
          <div class="ozet-kart">
            <div class="ok-lbl">Toplam Satış</div>
            <div class="ok-val" style="color:var(--accent)">₺<?= number_format($ekstreToplam['nakit']+$ekstreToplam['vadeli'],2,',','.') ?></div>
          </div>
          <div class="ozet-kart">
            <div class="ok-lbl">Nakit Satış</div>
            <div class="ok-val" style="color:var(--green)">₺<?= number_format($ekstreToplam['nakit'],2,',','.') ?></div>
          </div>
          <div class="ozet-kart">
            <div class="ok-lbl">Vadeli Satış</div>
            <div class="ok-val" style="color:var(--blue)">₺<?= number_format($ekstreToplam['vadeli'],2,',','.') ?></div>
          </div>
          <div class="ozet-kart">
            <div class="ok-lbl">Tahsil Edilen</div>
            <div class="ok-val" style="color:#7C3AED">₺<?= number_format($ekstreToplam['tahsilat'],2,',','.') ?></div>
          </div>
          <div class="ozet-kart">
            <div class="ok-lbl">Kalan Borç</div>
            <div class="ok-val" style="color:<?= $kalanBorc > 0 ? 'var(--red)' : 'var(--green)' ?>">
              <?= $kalanBorc > 0 ? '₺'.number_format($kalanBorc,2,',','.') : '✓ Borç yok' ?>
            </div>
          </div>
        </div>

        <!-- Ekstre tablosu -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">
              <?= htmlspecialchars($secilenCari['ad']) ?> — Ekstre
              <?php if ($secilenCari['telefon']): ?>
                <span style="font-weight:400;color:var(--ink3);margin-left:.5rem"><?= htmlspecialchars($secilenCari['telefon']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php if (empty($ekstreHareketler)): ?>
            <div style="padding:2rem;text-align:center;color:var(--ink3)">Bu cariye ait hareket bulunmuyor.</div>
          <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Tarih</th>
                <th>Tür</th>
                <th>Ürün</th>
                <th>Vade</th>
                <th class="text-right">Tutar</th>
                <th class="text-right">Tahsil</th>
                <th class="text-right">Kalan</th>
                <th>Durum</th>
              </tr>
            </thead>
            <tbody>
            <?php
              $satirKalanBorc = 0;
              foreach ($ekstreHareketler as $h):
                $hTahsilat = $tahsilatMap[$h['id']] ?? 0;
                $hKalan    = $h['tip'] === 'borc' ? max(0, $h['tutar'] - $hTahsilat) : 0;
                $tamTahsil = $h['tip'] === 'borc' && $hKalan <= 0;
                $vadeStr   = '';
                if ($h['vade_tarihi'] && $h['tip'] === 'borc') {
                    $vadeStr = date('d.m.Y', strtotime($h['vade_tarihi']));
                }
            ?>
              <tr style="<?= ($tamTahsil && $h['tip']==='borc') ? 'opacity:.6' : '' ?>">
                <td class="text-muted" style="font-size:.78rem;white-space:nowrap"><?= date('d.m.Y', strtotime($h['tarih'])) ?></td>
                <td>
                  <?php if ($h['tip'] === 'alacak'): ?>
                    <span class="durum-badge db-nakit">Nakit</span>
                  <?php else: ?>
                    <span class="durum-badge db-vadeli">Vadeli</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:.82rem">
                  <?php if ($h['stok_ad']): ?>
                    <strong><?= htmlspecialchars($h['stok_ad']) ?></strong>
                    <?php if ($h['stok_miktar']): ?>
                      <span class="text-muted"> <?= number_format($h['stok_miktar'],2,',','.') ?> <?= htmlspecialchars($h['stok_birim']??'') ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted"><?= htmlspecialchars($h['aciklama'] ?? '—') ?></span>
                  <?php endif; ?>
                </td>
                <td style="font-size:.78rem;white-space:nowrap">
                  <?php if ($vadeStr && !$tamTahsil): ?>
                    <?php
                      $vg = (int)((strtotime($h['vade_tarihi']) - strtotime($bugun)) / 86400);
                      $vc = $vg < 0 ? 'var(--red)' : ($vg <= 7 ? 'var(--gold)' : 'var(--ink3)');
                    ?>
                    <span style="color:<?= $vc ?>"><?= $vadeStr ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-right" style="font-weight:700;white-space:nowrap">
                  <?php if ($h['tip']==='alacak'): ?>
                    <span style="color:var(--green)">+₺<?= number_format($h['tutar'],2,',','.') ?></span>
                  <?php else: ?>
                    <span style="color:var(--blue)">₺<?= number_format($h['tutar'],2,',','.') ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-right" style="font-size:.82rem;white-space:nowrap">
                  <?= $hTahsilat > 0 ? '<span style="color:#7C3AED">₺'.number_format($hTahsilat,2,',','.').'</span>' : '<span class="text-muted">—</span>' ?>
                </td>
                <td class="text-right" style="font-size:.82rem;white-space:nowrap">
                  <?php if ($h['tip']==='borc'): ?>
                    <?= $hKalan > 0 ? '<span style="color:var(--red);font-weight:700">₺'.number_format($hKalan,2,',','.').'</span>' : '<span style="color:var(--green)">✓</span>' ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:.75rem">
                  <?php if ($h['tip']==='borc' && $tamTahsil): ?>
                    <span class="durum-badge" style="background:var(--green-lt);color:var(--green)">Tahsil</span>
                  <?php elseif ($h['tip']==='borc' && $hTahsilat > 0): ?>
                    <span class="durum-badge" style="background:var(--gold-lt);color:var(--gold)">Kısmi</span>
                  <?php elseif ($h['tip']==='borc'): ?>
                    <span class="durum-badge" style="background:var(--red-lt);color:var(--red)">Bekliyor</span>
                  <?php else: ?>
                    <span class="durum-badge db-nakit">Ödendi</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="font-weight:700;border-top:2px solid var(--border2)">
                <td colspan="4" style="padding:.6rem .75rem;font-size:.82rem;color:var(--ink2)">TOPLAM</td>
                <td class="text-right" style="padding:.6rem .75rem;white-space:nowrap">₺<?= number_format($toplamIslem,2,',','.') ?></td>
                <td class="text-right" style="padding:.6rem .75rem;color:#7C3AED;white-space:nowrap">₺<?= number_format($ekstreToplam['tahsilat'],2,',','.') ?></td>
                <td class="text-right" style="padding:.6rem .75rem;color:<?= $kalanBorc>0?'var(--red)':'var(--green)' ?>;white-space:nowrap">
                  <?= $kalanBorc > 0 ? '₺'.number_format($kalanBorc,2,',','.') : '✓' ?>
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <div class="panel" style="padding:2.5rem;text-align:center;color:var(--ink3)">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24" style="margin-bottom:.75rem;display:block;margin-inline:auto"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          Ekstre görmek için yukarıdan bir cari seçin.
        </div>
      <?php endif; ?>

      <?php elseif ($sekme === 'stok'): /* stok raporu */ ?>
      <!-- ── STOK RAPORU ── -->

      <?php
        $toplamNakitCiro  = array_sum(array_column($stokRaporu, 'nakit_ciro'));
        $toplamVadeliCiro = array_sum(array_column($stokRaporu, 'vadeli_ciro'));
        $toplamCiro = $toplamNakitCiro + $toplamVadeliCiro;
        $toplamMaliyet       = 0;
        $toplamKar           = 0;
        $toplamStokDegeri    = 0;
        $toplamStokDegeriVadeli = 0;
        foreach ($stokRaporu as $s) {
            $satilanAdet = $s['nakit_adet'] + $s['vadeli_adet'];
            $maliyet = $satilanAdet * (float)($s['alis_fiyat'] ?? 0);
            $ciro = $s['nakit_ciro'] + $s['vadeli_ciro'];
            $toplamMaliyet    += $maliyet;
            $toplamKar        += ($ciro - $maliyet);
                $toplamStokDegeri      += (float)($s['kalan_miktar'] ?? 0) * ((float)($s['nakit_fiyat']  ?? 0) ?: (float)($s['fiyat'] ?? 0));
            $toplamStokDegeriVadeli += (float)($s['kalan_miktar'] ?? 0) * ((float)($s['vadeli_fiyat'] ?? 0) ?: (float)($s['fiyat'] ?? 0));
        }
      ?>

      <form method="get" action="/raporlar" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem">
        <input type="hidden" name="sekme" value="stok">
        <div>
          <label class="form-label">Başlangıç</label>
          <input type="date" name="tarih_bas" class="form-input" value="<?= htmlspecialchars($tarihBas) ?>">
        </div>
        <div>
          <label class="form-label">Bitiş</label>
          <input type="date" name="tarih_bit" class="form-input" value="<?= htmlspecialchars($tarihBit) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
        <?php if ($tarihBas || $tarihBit): ?>
          <a href="/raporlar?sekme=stok" class="btn btn-ghost btn-sm">Temizle</a>
          <span style="font-size:.78rem;color:var(--ink3);align-self:center">
            <?= $tarihBas ? date('d.m.Y', strtotime($tarihBas)) : '—' ?>
            →
            <?= $tarihBit ? date('d.m.Y', strtotime($tarihBit)) : '—' ?>
          </span>
        <?php endif; ?>
        <button type="button" id="stokPdfBtn" onclick="stokPdfKaydet()" class="btn btn-secondary btn-sm" style="margin-left:auto">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
          PDF Kaydet
        </button>
      </form>
      <div class="stok-rapor-ozet" style="grid-template-columns:repeat(5,1fr)">
        <div class="ozet-kart">
          <div class="ok-lbl">Toplam Ciro</div>
          <div class="ok-val" style="color:var(--blue)">₺<?= number_format($toplamCiro,2,',','.') ?></div>
          <div style="font-size:.72rem;color:var(--ink3);margin-top:.2rem">Nakit ₺<?= number_format($toplamNakitCiro,2,',','.') ?> · Vadeli ₺<?= number_format($toplamVadeliCiro,2,',','.') ?></div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Toplam Maliyet</div>
          <div class="ok-val" style="color:var(--gold)">₺<?= number_format($toplamMaliyet,2,',','.') ?></div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Toplam Kâr</div>
          <div class="ok-val <?= $toplamKar >= 0 ? 'kar-pozitif' : 'kar-negatif' ?>">
            <?= $toplamKar >= 0 ? '+' : '' ?>₺<?= number_format($toplamKar,2,',','.') ?>
          </div>
          <?php if ($toplamCiro > 0): ?>
          <div style="font-size:.72rem;color:var(--ink3);margin-top:.2rem">Marj: %<?= number_format($toplamKar/$toplamCiro*100,1,',','.') ?></div>
          <?php endif; ?>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Stok Değeri (Nakit)</div>
          <div class="ok-val" style="color:var(--green)">₺<?= number_format($toplamStokDegeri,2,',','.') ?></div>
          <div style="font-size:.7rem;color:var(--ink3);margin-top:.2rem">Nakit fiyat üzerinden</div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Stok Değeri (Vadeli)</div>
          <div class="ok-val" style="color:var(--accent)">₺<?= number_format($toplamStokDegeriVadeli,2,',','.') ?></div>
          <div style="font-size:.7rem;color:var(--ink3);margin-top:.2rem">Vadeli fiyat üzerinden</div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <div class="panel-title">Ürün Bazlı Satış &amp; Kâr Analizi</div>
        </div>
        <?php if (empty($stokRaporu)): ?>
          <div style="padding:2rem;text-align:center;color:var(--ink3)">Henüz stok kaydı yok.</div>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Ürün</th>
              <th class="text-center">Nakit Satış</th>
              <th class="text-center">Vadeli Satış</th>
              <th class="text-right">Toplam Ciro</th>
              <th class="text-right">Alış Mal. (Top.)</th>
              <th class="text-right">Kâr</th>
              <th class="text-right">Kalan Stok</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($stokRaporu as $s):
            $satilanAdet = $s['nakit_adet'] + $s['vadeli_adet'];
            $ciro        = $s['nakit_ciro'] + $s['vadeli_ciro'];
            $maliyet     = $satilanAdet * (float)($s['alis_fiyat'] ?? 0);
            $kar         = $ciro - $maliyet;
            $marj        = $ciro > 0 ? $kar / $ciro * 100 : 0;
          ?>
          <tr>
            <td>
              <div style="font-weight:600;font-size:.87rem"><?= htmlspecialchars($s['ad']) ?></div>
              <div style="font-size:.72rem;color:var(--ink3)">
                Alış: <?= $s['alis_fiyat'] ? '₺'.number_format($s['alis_fiyat'],2,',','.') : '—' ?>
                · Nakit: <?= $s['nakit_fiyat'] ? '₺'.number_format($s['nakit_fiyat'],2,',','.') : '—' ?>
                · Vadeli: <?= $s['vadeli_fiyat'] ? '₺'.number_format($s['vadeli_fiyat'],2,',','.') : '—' ?>
              </div>
            </td>
            <td class="text-center" style="font-size:.82rem">
              <?php if ($s['nakit_adet'] > 0): ?>
                <div style="font-weight:700"><?= number_format($s['nakit_adet'],2,',','.') ?> <?= htmlspecialchars($s['birim']) ?></div>
                <div style="color:var(--green);font-size:.72rem">₺<?= number_format($s['nakit_ciro'],2,',','.') ?></div>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="text-center" style="font-size:.82rem">
              <?php if ($s['vadeli_adet'] > 0): ?>
                <div style="font-weight:700"><?= number_format($s['vadeli_adet'],2,',','.') ?> <?= htmlspecialchars($s['birim']) ?></div>
                <div style="color:var(--blue);font-size:.72rem">₺<?= number_format($s['vadeli_ciro'],2,',','.') ?></div>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="text-right" style="font-weight:700;white-space:nowrap">
              <?= $ciro > 0 ? '₺'.number_format($ciro,2,',','.') : '<span class="text-muted">—</span>' ?>
            </td>
            <td class="text-right" style="font-size:.82rem;white-space:nowrap">
              <?= $maliyet > 0 ? '₺'.number_format($maliyet,2,',','.') : '<span class="text-muted">—</span>' ?>
            </td>
            <td class="text-right" style="white-space:nowrap">
              <?php if ($ciro > 0): ?>
                <span class="<?= $kar >= 0 ? 'kar-pozitif' : 'kar-negatif' ?>">
                  <?= $kar >= 0 ? '+' : '' ?>₺<?= number_format($kar,2,',','.') ?>
                </span>
                <div style="font-size:.7rem;color:var(--ink3)">%<?= number_format($marj,1,',','.') ?></div>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="text-right" style="white-space:nowrap">
              <?php
                $kalanRenk = $s['kalan_miktar'] <= 0 ? 'var(--red)' : ($s['kalan_miktar'] <= 5 ? 'var(--gold)' : 'var(--ink)');
              ?>
              <span style="font-weight:700;color:<?= $kalanRenk ?>"><?= number_format($s['kalan_miktar'],2,',','.') ?></span>
              <span class="text-muted" style="font-size:.75rem"> <?= htmlspecialchars($s['birim']) ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight:700;border-top:2px solid var(--border2)">
              <td style="padding:.6rem .75rem;font-size:.82rem;color:var(--ink2)">TOPLAM</td>
              <td class="text-center" style="padding:.6rem .75rem;font-size:.82rem;color:var(--green)">
                ₺<?= number_format($toplamNakitCiro,2,',','.') ?>
              </td>
              <td class="text-center" style="padding:.6rem .75rem;font-size:.82rem;color:var(--blue)">
                ₺<?= number_format($toplamVadeliCiro,2,',','.') ?>
              </td>
              <td class="text-right" style="padding:.6rem .75rem">₺<?= number_format($toplamCiro,2,',','.') ?></td>
              <td class="text-right" style="padding:.6rem .75rem;color:var(--gold)">₺<?= number_format($toplamMaliyet,2,',','.') ?></td>
              <td class="text-right" style="padding:.6rem .75rem" class="<?= $toplamKar>=0?'kar-pozitif':'kar-negatif' ?>">
                <span class="<?= $toplamKar>=0?'kar-pozitif':'kar-negatif' ?>"><?= $toplamKar>=0?'+':'' ?>₺<?= number_format($toplamKar,2,',','.') ?></span>
              </td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>

      <?php elseif ($sekme === 'satis-kar'): ?>
      <!-- ── SATIŞ KÂR RAPORU ── -->

      <?php
        $karMarji = $satisOzet['toplam'] > 0 ? $satisOzet['kar'] / $satisOzet['toplam'] * 100 : 0;
      ?>

      <form method="get" action="/raporlar" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem">
        <input type="hidden" name="sekme" value="satis-kar">
        <div>
          <label class="form-label">Başlangıç</label>
          <input type="date" name="tarih_bas" class="form-input" value="<?= htmlspecialchars($tarihBas) ?>">
        </div>
        <div>
          <label class="form-label">Bitiş</label>
          <input type="date" name="tarih_bit" class="form-input" value="<?= htmlspecialchars($tarihBit) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
        <?php if ($tarihBas || $tarihBit): ?>
          <a href="/raporlar?sekme=satis-kar" class="btn btn-ghost btn-sm">Temizle</a>
          <span style="font-size:.78rem;color:var(--ink3);align-self:center">
            <?= $tarihBas ? date('d.m.Y', strtotime($tarihBas)) : '—' ?> → <?= $tarihBit ? date('d.m.Y', strtotime($tarihBit)) : '—' ?>
          </span>
        <?php endif; ?>
        <button type="button" id="satisKarPdfBtn" onclick="satisKarPdfKaydet()" class="btn btn-secondary btn-sm" style="margin-left:auto">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
          PDF Kaydet
        </button>
      </form>

      <!-- Özet kartlar -->
      <div id="satisKarOzet" class="stok-rapor-ozet" style="grid-template-columns:repeat(5,1fr)">
        <div class="ozet-kart">
          <div class="ok-lbl">Nakit Satış</div>
          <div class="ok-val" style="color:var(--green)">₺<?= number_format($satisOzet['nakit'],2,',','.') ?></div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Vadeli Satış</div>
          <div class="ok-val" style="color:var(--blue)">₺<?= number_format($satisOzet['vadeli'],2,',','.') ?></div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Toplam Satış</div>
          <div class="ok-val">₺<?= number_format($satisOzet['toplam'],2,',','.') ?></div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Satılan Mal Maliyeti</div>
          <div class="ok-val" style="color:var(--gold)">₺<?= number_format($satisOzet['maliyet'],2,',','.') ?></div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Net Kâr</div>
          <div class="ok-val <?= $satisOzet['kar'] >= 0 ? 'kar-pozitif' : 'kar-negatif' ?>">
            <?= $satisOzet['kar'] >= 0 ? '+' : '' ?>₺<?= number_format($satisOzet['kar'],2,',','.') ?>
          </div>
          <?php if ($satisOzet['toplam'] > 0): ?>
          <div style="font-size:.72rem;color:var(--ink3);margin-top:.2rem">Marj: %<?= number_format($karMarji,1,',','.') ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Detay tablo -->
      <div class="panel" id="satisKarPanel">
        <div class="panel-head">
          <div class="panel-title">Satış Detayları</div>
        </div>
        <?php if (empty($satisRaporu)): ?>
          <div style="padding:2rem;text-align:center;color:var(--ink3)">Seçili tarih aralığında satış kaydı bulunamadı.</div>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Tarih</th>
              <th>Tür</th>
              <th>Cari</th>
              <th>Ürün</th>
              <th class="text-right">Miktar</th>
              <th class="text-right">Satış Tutarı</th>
              <th class="text-right">Mal Maliyeti</th>
              <th class="text-right">Kâr (₺)</th>
              <th class="text-right">Kâr (%)</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $sToplam = ['satis'=>0,'maliyet'=>0,'kar'=>0];
            foreach ($satisRaporu as $r):
              $adet    = (float)($r['stok_miktar'] ?? 0);
              $maliyet = $adet * (float)($r['alis_fiyat'] ?? 0);
              $kar     = $r['tutar'] - $maliyet;
              $karYzd  = $r['tutar'] > 0 ? $kar / $r['tutar'] * 100 : 0;
              $sToplam['satis']   += $r['tutar'];
              $sToplam['maliyet'] += $maliyet;
              $sToplam['kar']     += $kar;
          ?>
          <tr>
            <td style="font-size:.78rem;white-space:nowrap;color:var(--ink3)"><?= date('d.m.Y', strtotime($r['tarih'])) ?></td>
            <td>
              <?php if ($r['tip'] === 'alacak'): ?>
                <span class="durum-badge db-nakit">Nakit</span>
              <?php else: ?>
                <span class="durum-badge db-vadeli">Vadeli</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.83rem;font-weight:600"><?= htmlspecialchars($r['cari_ad']) ?></td>
            <td style="font-size:.82rem">
              <strong><?= htmlspecialchars($r['stok_ad']) ?></strong>
            </td>
            <td class="text-right" style="font-size:.82rem;white-space:nowrap">
              <?= $adet > 0 ? number_format($adet,2,',','.') . ' ' . htmlspecialchars($r['stok_birim']??'') : '—' ?>
            </td>
            <td class="text-right" style="font-weight:700;white-space:nowrap">₺<?= number_format($r['tutar'],2,',','.') ?></td>
            <td class="text-right" style="font-size:.82rem;color:var(--gold);white-space:nowrap">
              <?= $maliyet > 0 ? '₺'.number_format($maliyet,2,',','.') : '<span class="text-muted">—</span>' ?>
            </td>
            <td class="text-right" style="white-space:nowrap">
              <span class="<?= $kar >= 0 ? 'kar-pozitif' : 'kar-negatif' ?>"><?= $kar >= 0 ? '+' : '' ?>₺<?= number_format($kar,2,',','.') ?></span>
            </td>
            <td class="text-right" style="font-size:.8rem;white-space:nowrap">
              <?php if ($r['tutar'] > 0): ?>
                <span style="color:<?= $karYzd >= 0 ? 'var(--green)' : 'var(--red)' ?>">%<?= number_format($karYzd,1,',','.') ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight:700;border-top:2px solid var(--border2)">
              <td colspan="5" style="padding:.6rem .75rem;font-size:.82rem;color:var(--ink2)">TOPLAM</td>
              <td class="text-right" style="padding:.6rem .75rem">₺<?= number_format($sToplam['satis'],2,',','.') ?></td>
              <td class="text-right" style="padding:.6rem .75rem;color:var(--gold)">₺<?= number_format($sToplam['maliyet'],2,',','.') ?></td>
              <td class="text-right" style="padding:.6rem .75rem">
                <span class="<?= $sToplam['kar']>=0?'kar-pozitif':'kar-negatif' ?>"><?= $sToplam['kar']>=0?'+':'' ?>₺<?= number_format($sToplam['kar'],2,',','.') ?></span>
              </td>
              <td class="text-right" style="padding:.6rem .75rem;font-size:.8rem">
                <?php if ($sToplam['satis'] > 0): ?>
                  <span style="color:<?= $sToplam['kar']>=0?'var(--green)':'var(--red)' ?>">%<?= number_format($sToplam['kar']/$sToplam['satis']*100,1,',','.') ?></span>
                <?php endif; ?>
              </td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>

      <?php endif; ?>

      <?php if ($sekme === 'kasa'): ?>
      <!-- ── KASA RAPORU ── -->

      <!-- Filtre formu -->
      <form method="get" action="/raporlar" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem">
        <input type="hidden" name="sekme" value="kasa">
        <div>
          <label class="form-label">Hesap</label>
          <select name="kasa_hesap" class="form-input form-select" style="min-width:180px">
            <option value="">— Tüm Hesaplar —</option>
            <?php foreach ($kasaHesaplari as $kh): ?>
              <option value="<?= $kh['id'] ?>" <?= $kh['id']==$kasaSecHesap ? 'selected' : '' ?>>
                <?= htmlspecialchars($kh['ad']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Başlangıç</label>
          <input type="date" name="tarih_bas" class="form-input" value="<?= htmlspecialchars($tarihBas) ?>">
        </div>
        <div>
          <label class="form-label">Bitiş</label>
          <input type="date" name="tarih_bit" class="form-input" value="<?= htmlspecialchars($tarihBit) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
        <?php if ($tarihBas || $tarihBit || $kasaSecHesap): ?>
          <a href="/raporlar?sekme=kasa" class="btn btn-ghost btn-sm">Temizle</a>
        <?php endif; ?>
        <button type="button" id="kasaPdfBtn" onclick="kasaPdfKaydet()" class="btn btn-secondary btn-sm" style="margin-left:auto">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
          PDF Kaydet
        </button>
      </form>

      <!-- Hesap bazlı özet kartlar -->
      <?php if (!empty($kasaHesapOzet)): ?>
      <div id="kasaHesapKartlar" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;margin-bottom:1.25rem">
        <?php foreach ($kasaHesapOzet as $kh):
          $khBakiye = $kh['giris'] - $kh['cikis'];
        ?>
        <a href="/raporlar?sekme=kasa&kasa_hesap=<?= $kh['id'] ?><?= $tarihBas ? '&tarih_bas='.$tarihBas : '' ?><?= $tarihBit ? '&tarih_bit='.$tarihBit : '' ?>"
           style="display:block;background:var(--surface);border:2px solid <?= htmlspecialchars($kh['renk']) ?>22;border-left:4px solid <?= htmlspecialchars($kh['renk']) ?>;border-radius:var(--r);padding:.85rem 1rem;text-decoration:none;transition:.12s <?= $kh['id']==$kasaSecHesap ? ';box-shadow:0 0 0 2px '.$kh['renk'] : '' ?>">
          <div style="font-size:.72rem;color:var(--ink3);margin-bottom:.3rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em"><?= htmlspecialchars($kh['ad']) ?></div>
          <div style="font-size:1.05rem;font-weight:800;font-variant-numeric:tabular-nums;color:<?= $khBakiye >= 0 ? 'var(--green)' : 'var(--red)' ?>">₺<?= number_format($khBakiye,2,',','.') ?></div>
          <div style="font-size:.72rem;color:var(--ink3);margin-top:.3rem">Giriş: ₺<?= number_format($kh['giris'],2,',','.') ?></div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Genel özet kartlar -->
      <?php
        $kasaBakiye = $kasaOzet['giris'] - $kasaOzet['cikis'];
      ?>
      <div id="kasaOzetKartlar" class="stok-rapor-ozet" style="grid-template-columns:repeat(3,1fr)">
        <div class="ozet-kart">
          <div class="ok-lbl">Toplam Giriş</div>
          <div class="ok-val" style="color:var(--green)">₺<?= number_format($kasaOzet['giris'],2,',','.') ?></div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Toplam Çıkış</div>
          <div class="ok-val" style="color:var(--red)">₺<?= number_format($kasaOzet['cikis'],2,',','.') ?></div>
        </div>
        <div class="ozet-kart">
          <div class="ok-lbl">Net Bakiye</div>
          <div class="ok-val" style="color:<?= $kasaBakiye >= 0 ? 'var(--green)' : 'var(--red)' ?>">₺<?= number_format($kasaBakiye,2,',','.') ?></div>
        </div>
      </div>

      <!-- Hareket tablosu -->
      <div class="panel" id="kasaRaporPanel">
        <div class="panel-head">
          <div class="panel-title">
            Kasa Hareketleri
            <?php if ($kasaSecHesap && !empty($kasaHesaplari)): ?>
              <?php foreach ($kasaHesaplari as $kh): if ($kh['id'] == $kasaSecHesap): ?>
                — <span style="color:<?= htmlspecialchars($kh['renk']) ?>"><?= htmlspecialchars($kh['ad']) ?></span>
              <?php endif; endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php if (empty($kasaRaporu)): ?>
          <div style="padding:2rem;text-align:center;color:var(--ink3)">Seçili kriterlere göre kasa hareketi bulunamadı.</div>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Tarih</th>
              <th>Hesap</th>
              <th>İşlem</th>
              <th>Açıklama</th>
              <th>Kaynak/Cari</th>
              <th class="text-right">Tutar</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($kasaRaporu as $kr): ?>
          <tr>
            <td style="font-size:.78rem;white-space:nowrap;color:var(--ink3)"><?= date('d.m.Y', strtotime($kr['tarih'])) ?></td>
            <td>
              <?php if ($kr['hesap_ad']): ?>
                <span style="display:inline-block;padding:.1rem .5rem;border-radius:4px;font-size:.72rem;font-weight:700;background:<?= htmlspecialchars($kr['hesap_renk'] ?? '#3B82F6') ?>22;color:<?= htmlspecialchars($kr['hesap_renk'] ?? '#3B82F6') ?>"><?= htmlspecialchars($kr['hesap_ad']) ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($kr['tip'] === 'giris'): ?>
                <span class="durum-badge db-nakit">Giriş</span>
              <?php else: ?>
                <span class="durum-badge" style="background:#FEE2E2;color:#DC2626">Çıkış</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.82rem"><?= htmlspecialchars($kr['aciklama'] ?? '') ?></td>
            <td style="font-size:.82rem">
              <?php if ($kr['cari_ad']): ?>
                <span style="font-weight:600"><?= htmlspecialchars($kr['cari_ad']) ?></span>
              <?php elseif ($kr['kaynak'] === 'manuel'): ?>
                <span class="text-muted">Manuel</span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-right" style="font-weight:700;white-space:nowrap;color:<?= $kr['tip']==='giris' ? 'var(--green)' : 'var(--red)' ?>">
              <?= $kr['tip']==='giris' ? '+' : '-' ?>₺<?= number_format($kr['tutar'],2,',','.') ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight:700;border-top:2px solid var(--border2)">
              <td colspan="5" style="padding:.6rem .75rem;font-size:.82rem;color:var(--ink2)">TOPLAM</td>
              <td class="text-right" style="padding:.6rem .75rem;color:<?= $kasaBakiye >= 0 ? 'var(--green)' : 'var(--red)' ?>">
                <?= $kasaBakiye >= 0 ? '+' : '' ?>₺<?= number_format($kasaBakiye,2,',','.') ?>
              </td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>

      <?php endif; ?>

    </div>
  </main>
</div>
<script>
function stokPdfKaydet() {
  var btn = document.getElementById('stokPdfBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Hazırlanıyor…'; }

  var ozet  = document.querySelector('.stok-rapor-ozet');
  var panel = document.querySelector('.panel');
  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
    + '<style>'
    + 'body{font-family:Arial,sans-serif;font-size:11px;color:#111;margin:20px}'
    + 'h2{margin:0 0 4px;font-size:15px}p{margin:0 0 14px;color:#666;font-size:10px}'
    + 'table{width:100%;border-collapse:collapse;font-size:10px}'
    + 'th{background:#f1f5f9;padding:5px 7px;text-align:left;border-bottom:2px solid #cbd5e0;font-weight:700;white-space:nowrap}'
    + 'td{padding:5px 7px;border-bottom:1px solid #e2e8f0;vertical-align:top}'
    + 'tfoot td{font-weight:700;border-top:2px solid #94a3b8;border-bottom:none}'
    + '.text-right{text-align:right}.text-center{text-align:center}'
    + '.stok-rapor-ozet{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}'
    + '.ozet-kart{border:1px solid #e2e8f0;border-radius:6px;padding:8px}'
    + '.ok-lbl{font-size:9px;color:#94a3b8;margin-bottom:2px}'
    + '.ok-val{font-size:13px;font-weight:800}'
    + '.kar-pozitif{color:#16a34a;font-weight:700}.kar-negatif{color:#dc2626;font-weight:700}'
    + '.text-muted{color:#94a3b8}'
    + '.panel-head{padding:10px 14px;border-bottom:1px solid #e2e8f0}'
    + '.panel-title{font-weight:700;font-size:12px}'
    + '@page{size:A4 landscape;margin:12mm}'
    + '</style></head><body>'
    + pdfMarkaHtml() + '<h2>Stok Satış &amp; Kâr Raporu</h2>'
    + '<p>Tarih: ' + new Date().toLocaleDateString("tr-TR") + '</p>'
    + (ozet ? ozet.outerHTML : '')
    + (panel ? panel.outerHTML : '')
    + '</body></html>';

  if (window.electronAPI && window.electronAPI.savePdf) {
    window.electronAPI.savePdf(html).then(function() {
      if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg> PDF Kaydet'; }
    });
  } else {
    window.print();
    if (btn) btn.disabled = false;
  }
}
function satisKarPdfKaydet() {
  var btn = document.getElementById('satisKarPdfBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Hazırlanıyor…'; }

  var ozet  = document.getElementById('satisKarOzet');
  var panel = document.getElementById('satisKarPanel');
  var tarihBas = <?= json_encode($tarihBas) ?>;
  var tarihBit = <?= json_encode($tarihBit) ?>;
  var aralik = (tarihBas || tarihBit) ? (' (' + (tarihBas ? tarihBas.split('-').reverse().join('.') : '—') + ' → ' + (tarihBit ? tarihBit.split('-').reverse().join('.') : '—') + ')') : '';

  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
    + '<style>'
    + 'body{font-family:Arial,sans-serif;font-size:11px;color:#111;margin:20px}'
    + 'h2{margin:0 0 4px;font-size:15px}p{margin:0 0 14px;color:#666;font-size:10px}'
    + 'table{width:100%;border-collapse:collapse;font-size:10px}'
    + 'th{background:#f1f5f9;padding:5px 7px;text-align:left;border-bottom:2px solid #cbd5e0;font-weight:700;white-space:nowrap}'
    + 'td{padding:5px 7px;border-bottom:1px solid #e2e8f0;vertical-align:top}'
    + 'tfoot td{font-weight:700;border-top:2px solid #94a3b8;border-bottom:none}'
    + '.text-right{text-align:right}.text-center{text-align:center}'
    + '.stok-rapor-ozet{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:14px}'
    + '.ozet-kart{border:1px solid #e2e8f0;border-radius:6px;padding:8px}'
    + '.ok-lbl{font-size:9px;color:#94a3b8;margin-bottom:2px}'
    + '.ok-val{font-size:12px;font-weight:800}'
    + '.kar-pozitif{color:#16a34a;font-weight:700}.kar-negatif{color:#dc2626;font-weight:700}'
    + '.text-muted{color:#94a3b8}.panel-head{padding:10px 14px;border-bottom:1px solid #e2e8f0}'
    + '.panel-title{font-weight:700;font-size:12px}'
    + '.durum-badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700}'
    + '.db-nakit{background:#dcfce7;color:#16a34a}.db-vadeli{background:#dbeafe;color:#2563eb}'
    + '@page{size:A4 landscape;margin:12mm}'
    + '</style></head><body>'
    + pdfMarkaHtml() + '<h2>Satış Kâr Raporu' + aralik + '</h2>'
    + '<p>Oluşturma tarihi: ' + new Date().toLocaleDateString("tr-TR") + '</p>'
    + (ozet ? ozet.outerHTML : '')
    + (panel ? panel.outerHTML : '')
    + '</body></html>';

  if (window.electronAPI && window.electronAPI.savePdf) {
    window.electronAPI.savePdf(html).then(function() {
      if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg> PDF Kaydet'; }
    });
  } else {
    window.print();
    if (btn) btn.disabled = false;
  }
}

function kasaPdfKaydet() {
  var btn = document.getElementById('kasaPdfBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Hazırlanıyor…'; }

  var ozet  = document.getElementById('kasaOzetKartlar');
  var hesapKartlar = document.getElementById('kasaHesapKartlar');
  var panel = document.getElementById('kasaRaporPanel');
  var tarihBas = <?= json_encode($tarihBas) ?>;
  var tarihBit = <?= json_encode($tarihBit) ?>;
  var aralik = (tarihBas || tarihBit) ? (' (' + (tarihBas ? tarihBas.split('-').reverse().join('.') : '—') + ' → ' + (tarihBit ? tarihBit.split('-').reverse().join('.') : '—') + ')') : '';

  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
    + '<style>'
    + 'body{font-family:Arial,sans-serif;font-size:11px;color:#111;margin:20px}'
    + 'h2{margin:0 0 4px;font-size:15px}p{margin:0 0 14px;color:#666;font-size:10px}'
    + 'table{width:100%;border-collapse:collapse;font-size:10px}'
    + 'th{background:#f1f5f9;padding:5px 7px;text-align:left;border-bottom:2px solid #cbd5e0;font-weight:700;white-space:nowrap}'
    + 'td{padding:5px 7px;border-bottom:1px solid #e2e8f0;vertical-align:top}'
    + 'tfoot td{font-weight:700;border-top:2px solid #94a3b8;border-bottom:none}'
    + '.text-right{text-align:right}'
    + '.stok-rapor-ozet{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}'
    + '.ozet-kart{border:1px solid #e2e8f0;border-radius:6px;padding:8px}'
    + '.ok-lbl{font-size:9px;color:#94a3b8;margin-bottom:2px}'
    + '.ok-val{font-size:13px;font-weight:800}'
    + '.text-muted{color:#94a3b8}'
    + '.panel-head{padding:10px 14px;border-bottom:1px solid #e2e8f0}'
    + '.panel-title{font-weight:700;font-size:12px}'
    + '.durum-badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700}'
    + '@page{size:A4 landscape;margin:12mm}'
    + '</style></head><body>'
    + pdfMarkaHtml() + '<h2>Kasa Raporu' + aralik + '</h2>'
    + '<p>Oluşturma tarihi: ' + new Date().toLocaleDateString("tr-TR") + '</p>'
    + (hesapKartlar ? hesapKartlar.outerHTML : '')
    + (ozet ? ozet.outerHTML : '')
    + (panel ? panel.outerHTML : '')
    + '</body></html>';

  if (window.electronAPI && window.electronAPI.savePdf) {
    window.electronAPI.savePdf(html).then(function() {
      if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg> PDF Kaydet'; }
    });
  } else {
    window.print();
    if (btn) btn.disabled = false;
  }
}

function ekstrePdfKaydet() {
  var btn = document.getElementById('pdfBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Hazırlanıyor…'; }

  // Sadece ekstre içeriğini al
  var cariAd  = <?= json_encode($secilenCari['ad'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
  var cariTel = <?= json_encode($secilenCari['telefon'] ?? '', JSON_UNESCAPED_UNICODE) ?>;

  // Mevcut sayfanın tablo HTML'ini klonla, sidebar/titlebar olmadan
  var tablo   = document.querySelector('.panel');
  var ozet    = document.querySelector('.ekstre-ozet');
  var tabloHtml = ozet ? ozet.outerHTML : '';
  tabloHtml  += tablo ? tablo.outerHTML : '';

  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
    + '<style>'
    + 'body{font-family:Arial,sans-serif;font-size:12px;color:#111;margin:20px}'
    + 'h2{margin:0 0 4px;font-size:16px}p{margin:0 0 16px;color:#555;font-size:11px}'
    + 'table{width:100%;border-collapse:collapse;font-size:11px}'
    + 'th{background:#f1f5f9;padding:6px 8px;text-align:left;border-bottom:2px solid #cbd5e0;font-weight:700}'
    + 'td{padding:5px 8px;border-bottom:1px solid #e2e8f0}'
    + 'tfoot td{font-weight:700;border-top:2px solid #94a3b8;border-bottom:none}'
    + '.text-right{text-align:right}.text-center{text-align:center}'
    + '.ekstre-ozet{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px}'
    + '.ozet-kart{border:1px solid #e2e8f0;border-radius:6px;padding:10px}'
    + '.ok-lbl{font-size:10px;color:#94a3b8;margin-bottom:3px}'
    + '.ok-val{font-size:14px;font-weight:800}'
    + '.durum-badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700}'
    + '.db-nakit{background:#dcfce7;color:#16a34a}.db-vadeli{background:#dbeafe;color:#2563eb}'
    + '.text-muted{color:#94a3b8}'
    + '.panel-head{padding:12px 16px;border-bottom:1px solid #e2e8f0}'
    + '.panel-title{font-weight:700;font-size:13px}'
    + '@page{size:A4;margin:15mm}'
    + '</style></head><body>'
    + pdfMarkaHtml() + '<h2>' + cariAd + ' — Cari Ekstre</h2>'
    + '<p>' + (cariTel ? 'Tel: ' + cariTel + ' · ' : '') + 'Tarih: ' + new Date().toLocaleDateString("tr-TR") + '</p>'
    + tabloHtml
    + '</body></html>';

  if (window.electronAPI && window.electronAPI.savePdf) {
    window.electronAPI.savePdf(html).then(function(res) {
      if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg> PDF Kaydet'; }
    });
  } else {
    window.print();
    if (btn) { btn.disabled = false; }
  }
}
</script>
</body>
</html>
