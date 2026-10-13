<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/bakiye.php';
girisGerekli();
$activePage = 'cariler';
$uid = userId();

$bit   = trim($_GET['bit'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bit)) $bit = '';
$durum = in_array($_GET['durum'] ?? '', ['hepsi', 'borclu', 'alacakli', 'hareketli']) ? $_GET['durum'] : 'hareketli';
$sira  = ($_GET['sira'] ?? '') === 'ad' ? 'ad' : 'bakiye';

$fRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='firma_adi'");
$fRow->execute([$uid]);
$firmaAdi = $fRow->fetchColumn() ?: 'Veresiyem';

$cStmt = $pdo->prepare("SELECT id, ad, telefon FROM cariler WHERE user_id = ? ORDER BY ad COLLATE NOCASE");
$cStmt->execute([$uid]);
$bakiyeler = cariBakiyeleri($pdo, $uid, $bit);

$liste = [];
$top = ['borc' => 0, 'alacak' => 0, 'borclu' => 0, 'alacakli' => 0, 'borclu_sayi' => 0, 'alacakli_sayi' => 0];
foreach ($cStmt->fetchAll() as $c) {
    $b = $bakiyeler[$c['id']] ?? ['borc' => 0, 'alacak' => 0, 'bakiye' => 0, 'son_islem' => null];
    if ($durum === 'borclu'    && $b['bakiye'] <= 0.005)  continue;
    if ($durum === 'alacakli'  && $b['bakiye'] >= -0.005) continue;
    if ($durum === 'hareketli' && $b['borc'] == 0 && $b['alacak'] == 0) continue;
    $liste[] = $c + $b;
    $top['borc']   += $b['borc'];
    $top['alacak'] += $b['alacak'];
    if ($b['bakiye'] > 0.005)  { $top['borclu']   += $b['bakiye']; $top['borclu_sayi']++; }
    if ($b['bakiye'] < -0.005) { $top['alacakli'] += -$b['bakiye']; $top['alacakli_sayi']++; }
}
if ($sira === 'bakiye') usort($liste, fn($a, $b) => $b['bakiye'] <=> $a['bakiye']);
$net = $top['borclu'] - $top['alacakli'];

$tl  = fn($v) => '₺' . number_format((float)$v, 2, ',', '.');
$trh = fn($d) => $d ? date('d.m.Y', strtotime($d)) : '—';

$pageTitle = 'Genel Borç-Alacak Raporu';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $pageTitle ?> — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.ek-bar { display:flex; gap:.6rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
.ek-paper { background:#fff; border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow); max-width:1000px; margin:0 auto; padding:28px 32px; }
.oz a { color:inherit; text-decoration:none; } .oz a:hover { text-decoration:underline; }
@media print {
  html,body { height:auto !important; overflow:visible !important; background:#fff !important; }
  .titlebar,.sidebar,.no-print { display:none !important; }
  .app-shell,.main-content,.page-body { display:block !important; padding:0 !important; margin:0 !important; height:auto !important; overflow:visible !important; }
  .ek-paper { border:none; box-shadow:none; padding:0; max-width:none; }
}
</style>
<style id="ozCss">
.oz { font-family:Arial,Helvetica,sans-serif; color:#111; font-size:12px; }
.oz-head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #111; padding-bottom:10px; margin-bottom:12px; }
.oz-firma { font-size:18px; font-weight:800; }
.oz-baslik { font-size:13px; font-weight:700; letter-spacing:.06em; color:#444; margin-top:2px; }
.oz-meta { text-align:right; font-size:11px; color:#444; line-height:1.6; }
.oz-ozet { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:14px; }
.oz-ozet div { border:1px solid #d4d4d8; border-radius:6px; padding:8px 10px; }
.oz-ozet span { display:block; font-size:10px; color:#666; text-transform:uppercase; letter-spacing:.04em; }
.oz-ozet strong { font-size:15px; }
.oz-ozet small { color:#666; }
.oz table { width:100%; border-collapse:collapse; }
.oz th { background:#f1f5f9; border-bottom:2px solid #94a3b8; padding:6px; text-align:left; font-size:11px; }
.oz td { border-bottom:1px solid #e2e8f0; padding:6px; }
.oz tr { page-break-inside:avoid; }
.oz .r { text-align:right; white-space:nowrap; }
.oz .no { width:32px; color:#888; }
.oz tfoot td { border-top:2px solid #111; border-bottom:none; font-weight:800; padding-top:8px; }
.oz .borc { color:#b91c1c; } .oz .alacak { color:#15803d; }
@page { size:A4; margin:12mm; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header no-print">
      <div class="page-header-left">
        <a href="/cariler" class="breadcrumb">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          Cariler
        </a>
        <div class="page-title">Genel Borç-Alacak Raporu</div>
      </div>
    </div>

    <div class="page-body">
      <form method="get" action="/cari-ozet" class="ek-bar no-print">
        <div>
          <label class="form-label">Tarih itibarıyla</label>
          <input type="date" name="bit" class="form-input" value="<?= htmlspecialchars($bit) ?>">
        </div>
        <div>
          <label class="form-label">Göster</label>
          <select name="durum" class="form-input form-select">
            <option value="hareketli" <?= $durum==='hareketli'?'selected':'' ?>>İşlemi olanlar</option>
            <option value="borclu"    <?= $durum==='borclu'?'selected':'' ?>>Sadece borçlular</option>
            <option value="alacakli"  <?= $durum==='alacakli'?'selected':'' ?>>Sadece alacaklılar</option>
            <option value="hepsi"     <?= $durum==='hepsi'?'selected':'' ?>>Tüm cariler</option>
          </select>
        </div>
        <div>
          <label class="form-label">Sıralama</label>
          <select name="sira" class="form-input form-select">
            <option value="bakiye" <?= $sira==='bakiye'?'selected':'' ?>>Bakiyeye göre</option>
            <option value="ad"     <?= $sira==='ad'?'selected':'' ?>>İsme göre</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Uygula</button>
        <div style="margin-left:auto;display:flex;gap:.5rem">
          <button type="button" class="btn btn-secondary btn-sm" onclick="excelIndir()">Excel</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">Yazdır</button>
          <button type="button" class="btn btn-primary btn-sm" id="pdfBtn" onclick="ozetPdf()">PDF Kaydet</button>
        </div>
      </form>

      <div class="ek-paper">
        <div class="oz" id="ozDoc">
          <div class="oz-head">
            <div>
              <div class="oz-firma"><?= htmlspecialchars($firmaAdi) ?></div>
              <div class="oz-baslik">GENEL BORÇ - ALACAK RAPORU</div>
            </div>
            <div class="oz-meta">
              <?= $bit ? $trh($bit) . ' tarihi itibarıyla' : 'Tüm zamanlar' ?><br>
              Düzenleme: <?= date('d.m.Y H:i') ?>
            </div>
          </div>

          <div class="oz-ozet">
            <div><span>Toplam Satış / Borç</span><strong><?= $tl($top['borc']) ?></strong></div>
            <div><span>Toplam Ödeme / Alacak</span><strong><?= $tl($top['alacak']) ?></strong></div>
            <div><span>Müşterilerden Alacak</span><strong class="borc"><?= $tl($top['borclu']) ?></strong> <small><?= $top['borclu_sayi'] ?> cari</small></div>
            <div><span>Müşterilere Borç</span><strong class="alacak"><?= $tl($top['alacakli']) ?></strong> <small><?= $top['alacakli_sayi'] ?> cari</small></div>
          </div>

          <table id="ozTbl">
            <thead>
              <tr>
                <th class="no">#</th>
                <th>Cari</th>
                <th>Telefon</th>
                <th class="r">Borç</th>
                <th class="r">Ödeme / Alacak</th>
                <th class="r">Bakiye</th>
                <th class="r">Son İşlem</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$liste): ?>
              <tr><td colspan="7" style="text-align:center;color:#888;padding:20px">Kayıt yok.</td></tr>
              <?php endif; ?>
              <?php foreach ($liste as $i => $r): ?>
              <tr>
                <td class="no"><?= $i + 1 ?></td>
                <td><a href="/cari-ekstre?id=<?= $r['id'] ?><?= $bit ? '&bit=' . $bit : '' ?>"><?= htmlspecialchars($r['ad']) ?></a></td>
                <td><?= htmlspecialchars($r['telefon'] ?? '') ?></td>
                <td class="r"><?= $tl($r['borc']) ?></td>
                <td class="r"><?= $tl($r['alacak']) ?></td>
                <td class="r <?= $r['bakiye'] > 0.005 ? 'borc' : ($r['bakiye'] < -0.005 ? 'alacak' : '') ?>" style="font-weight:700">
                  <?= $tl(abs($r['bakiye'])) ?><?= $r['bakiye'] > 0.005 ? ' (B)' : ($r['bakiye'] < -0.005 ? ' (A)' : '') ?>
                </td>
                <td class="r"><?= $trh($r['son_islem']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td></td>
                <td colspan="2">TOPLAM (<?= count($liste) ?> cari)</td>
                <td class="r"><?= $tl($top['borc']) ?></td>
                <td class="r"><?= $tl($top['alacak']) ?></td>
                <td class="r <?= $net > 0.005 ? 'borc' : 'alacak' ?>"><?= $tl(abs($net)) ?><?= $net > 0.005 ? ' (B)' : ($net < -0.005 ? ' (A)' : '') ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
          <div style="margin-top:14px;font-size:10.5px;color:#666">(B): Müşteri borçlu &nbsp;·&nbsp; (A): Müşteri alacaklı (fazla ödeme). Cari adına tıklayarak ekstresini açabilirsiniz.</div>
        </div>
      </div>
    </div>
  </main>
</div>
<script>
function ozetPdf() {
  var html = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><style>body{margin:0}'
    + document.getElementById('ozCss').textContent + '</style></head><body>'
    + document.getElementById('ozDoc').outerHTML + '</body></html>';
  if (window.electronAPI && window.electronAPI.savePdf) {
    var btn = document.getElementById('pdfBtn');
    btn.disabled = true; btn.textContent = 'Hazırlanıyor…';
    window.electronAPI.savePdf(html).finally(function(){ btn.disabled = false; btn.textContent = 'PDF Kaydet'; });
  } else {
    window.print();
  }
}
function excelIndir() {
  var rows = <?= json_encode(array_map(fn($r) => [$r['ad'], $r['telefon'] ?? '', $r['borc'], $r['alacak'], $r['bakiye'], $r['son_islem'] ?? ''], $liste), JSON_UNESCAPED_UNICODE) ?>;
  var sayi = function(v){ return String(v).replace('.', ','); };
  var csv = ['Cari;Telefon;Borç;Ödeme/Alacak;Bakiye;Son İşlem'];
  rows.forEach(function(r){
    csv.push([r[0], r[1], sayi(r[2]), sayi(r[3]), sayi(r[4]), r[5]].map(function(v){
      return '"' + String(v).replace(/"/g, '""') + '"';
    }).join(';'));
  });
  var blob = new Blob(['﻿' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'borc-alacak-raporu-<?= date('Y-m-d') ?>.csv';
  a.click();
}
</script>
</body>
</html>
