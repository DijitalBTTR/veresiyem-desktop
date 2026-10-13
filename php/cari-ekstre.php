<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/bakiye.php';
girisGerekli();
$activePage = 'cariler';
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
$cStmt = $pdo->prepare("SELECT * FROM cariler WHERE id = ? AND user_id = ?");
$cStmt->execute([$id, $uid]);
$cari = $cStmt->fetch();
if (!$cari) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$bas = trim($_GET['bas'] ?? '');
$bit = trim($_GET['bit'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bas)) $bas = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bit)) $bit = '';

$fRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='firma_adi'");
$fRow->execute([$uid]);
$firmaAdi = $fRow->fetchColumn() ?: 'Veresiyem';

// Satırlar + devreden bakiye + yürüyen bakiye
$devreden = 0.0;
$satirlar = [];
foreach (cariEkstreSatirlari($pdo, $id) as $s) {
    if ($bas && $s['tarih'] < $bas) { $devreden += $s['borc'] - $s['alacak']; continue; }
    if ($bit && $s['tarih'] > $bit) continue;
    $satirlar[] = $s;
}
$bakiye = $devreden;
$donemBorc = $donemAlacak = 0.0;
foreach ($satirlar as &$s) {
    $bakiye      += $s['borc'] - $s['alacak'];
    $s['bakiye']  = $bakiye;
    $donemBorc   += $s['borc'];
    $donemAlacak += $s['alacak'];
}
unset($s);

$tl  = fn($v) => '₺' . number_format((float)$v, 2, ',', '.');
$bk  = fn($v) => abs($v) < 0.005 ? '₺0,00' : $tl(abs($v)) . ($v > 0 ? ' (B)' : ' (A)');
$trh = fn($d) => $d ? date('d.m.Y', strtotime($d)) : '';

$pageTitle = $cari['ad'] . ' — Ekstre';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.ek-bar { display:flex; gap:.6rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
.ek-bar .form-label { margin-bottom:.2rem; }
.ek-paper { background:#fff; border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow); max-width:1000px; margin:0 auto; padding:28px 32px; }
@media print {
  html,body { height:auto !important; overflow:visible !important; background:#fff !important; }
  .titlebar,.sidebar,.no-print { display:none !important; }
  .app-shell,.main-content,.page-body { display:block !important; padding:0 !important; margin:0 !important; height:auto !important; overflow:visible !important; }
  .ek-paper { border:none; box-shadow:none; padding:0; max-width:none; }
}
</style>
<style id="ekCss">
.ek { font-family:Arial,Helvetica,sans-serif; color:#111; font-size:12px; }
.ek-head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #111; padding-bottom:10px; margin-bottom:12px; }
.ek-firma { font-size:18px; font-weight:800; }
.ek-baslik { font-size:13px; font-weight:700; letter-spacing:.06em; color:#444; margin-top:2px; }
.ek-meta { text-align:right; font-size:11px; color:#444; line-height:1.6; }
.ek-musteri { display:flex; justify-content:space-between; gap:16px; margin-bottom:12px; font-size:12px; line-height:1.6; }
.ek-musteri b { font-size:14px; }
.ek-ozet { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:14px; }
.ek-ozet div { border:1px solid #d4d4d8; border-radius:6px; padding:8px 10px; }
.ek-ozet span { display:block; font-size:10px; color:#666; text-transform:uppercase; letter-spacing:.04em; }
.ek-ozet strong { font-size:15px; }
.ek table { width:100%; border-collapse:collapse; table-layout:fixed; }
.ek th { background:#f1f5f9; border-bottom:2px solid #94a3b8; padding:6px 6px; text-align:left; font-size:11px; }
.ek td { border-bottom:1px solid #e2e8f0; padding:6px 6px; vertical-align:top; word-wrap:break-word; }
.ek tr { page-break-inside:avoid; }
.ek .c-tarih { width:78px; white-space:nowrap; }
.ek .c-tur { width:92px; }
.ek .c-tutar { width:96px; text-align:right; white-space:nowrap; }
.ek .c-bakiye { width:118px; text-align:right; white-space:nowrap; font-weight:700; }
.ek .ack { font-weight:600; }
.ek .detay { color:#444; font-size:11px; margin-top:2px; }
.ek .devreden td { background:#fafafa; font-style:italic; }
.ek tfoot td { border-top:2px solid #111; border-bottom:none; font-weight:800; padding-top:8px; }
.ek .borc { color:#b91c1c; } .ek .alacak { color:#15803d; }
.ek-not { margin-top:14px; font-size:10.5px; color:#666; }
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
        <a href="/cari?id=<?= $id ?>" class="breadcrumb">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          <?= htmlspecialchars($cari['ad']) ?>
        </a>
        <div class="page-title">Cari Hesap Ekstresi</div>
      </div>
    </div>

    <div class="page-body">
      <form method="get" action="/cari-ekstre" class="ek-bar no-print">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div>
          <label class="form-label">Başlangıç</label>
          <input type="date" name="bas" class="form-input" value="<?= htmlspecialchars($bas) ?>">
        </div>
        <div>
          <label class="form-label">Bitiş</label>
          <input type="date" name="bit" class="form-input" value="<?= htmlspecialchars($bit) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
        <?php if ($bas || $bit): ?><a href="/cari-ekstre?id=<?= $id ?>" class="btn btn-ghost btn-sm">Tümü</a><?php endif; ?>
        <div style="margin-left:auto;display:flex;gap:.5rem">
          <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">Yazdır</button>
          <button type="button" class="btn btn-primary btn-sm" id="pdfBtn" onclick="ekstrePdf()">PDF Kaydet</button>
        </div>
      </form>

      <div class="ek-paper">
        <div class="ek" id="ekDoc">
          <div class="ek-head">
            <div>
              <div class="ek-firma"><?= htmlspecialchars($firmaAdi) ?></div>
              <div class="ek-baslik">CARİ HESAP EKSTRESİ</div>
            </div>
            <div class="ek-meta">
              Dönem: <?= $bas ? $trh($bas) : 'Başlangıç' ?> – <?= $bit ? $trh($bit) : 'Bugün' ?><br>
              Düzenleme: <?= date('d.m.Y H:i') ?>
            </div>
          </div>

          <div class="ek-musteri">
            <div>
              <b><?= htmlspecialchars($cari['ad']) ?></b><br>
              <?php if (!empty($cari['yetkili'])): ?>Yetkili: <?= htmlspecialchars($cari['yetkili']) ?><br><?php endif; ?>
              <?php if (!empty($cari['telefon'])): ?>Tel: <?= htmlspecialchars($cari['telefon']) ?><br><?php endif; ?>
              <?php if (!empty($cari['adres'])): ?><?= nl2br(htmlspecialchars($cari['adres'])) ?><br><?php endif; ?>
              <?php $ilIlce = trim(($cari['ilce'] ?? '') . ' / ' . ($cari['il'] ?? ''), ' /'); if ($ilIlce): ?><?= htmlspecialchars($ilIlce) ?><?php endif; ?>
            </div>
            <div style="text-align:right">
              <?php if (!empty($cari['musteri_kodu'])): ?>Müşteri Kodu: <?= htmlspecialchars($cari['musteri_kodu']) ?><br><?php endif; ?>
              <?php if (!empty($cari['vergi_dairesi'])): ?>V.D.: <?= htmlspecialchars($cari['vergi_dairesi']) ?><br><?php endif; ?>
              <?php $vNo = $cari['vergi_tc'] ?? ($cari['vergi_no'] ?? ''); if ($vNo): ?>VKN/TC: <?= htmlspecialchars($vNo) ?><?php endif; ?>
            </div>
          </div>

          <div class="ek-ozet">
            <div><span>Dönem Borç</span><strong class="borc"><?= $tl($donemBorc) ?></strong></div>
            <div><span>Dönem Ödeme / Alacak</span><strong class="alacak"><?= $tl($donemAlacak) ?></strong></div>
            <div><span><?= $bakiye > 0.005 ? 'Kalan Borç' : ($bakiye < -0.005 ? 'Alacak (Fazla Ödeme)' : 'Bakiye') ?></span>
              <strong class="<?= $bakiye > 0.005 ? 'borc' : 'alacak' ?>"><?= $tl(abs($bakiye)) ?></strong></div>
          </div>

          <table>
            <thead>
              <tr>
                <th class="c-tarih">Tarih</th>
                <th class="c-tur">İşlem</th>
                <th>Açıklama</th>
                <th class="c-tutar">Borç</th>
                <th class="c-tutar">Alacak</th>
                <th class="c-bakiye">Bakiye</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($bas): ?>
              <tr class="devreden">
                <td class="c-tarih"><?= $trh($bas) ?></td>
                <td class="c-tur">Devir</td>
                <td>Önceki dönemden devreden bakiye</td>
                <td class="c-tutar"></td>
                <td class="c-tutar"></td>
                <td class="c-bakiye"><?= $bk($devreden) ?></td>
              </tr>
              <?php endif; ?>
              <?php if (!$satirlar): ?>
              <tr><td colspan="6" style="text-align:center;color:#888;padding:20px">Bu dönemde işlem yok.</td></tr>
              <?php endif; ?>
              <?php foreach ($satirlar as $s): ?>
              <tr>
                <td class="c-tarih"><?= $trh($s['tarih']) ?></td>
                <td class="c-tur"><?= htmlspecialchars($s['tur']) ?></td>
                <td>
                  <div class="ack"><?= htmlspecialchars($s['aciklama']) ?></div>
                  <?php foreach ($s['detay'] as $d): ?><div class="detay"><?= htmlspecialchars($d) ?></div><?php endforeach; ?>
                </td>
                <td class="c-tutar borc"><?= $s['borc'] ? $tl($s['borc']) : '' ?></td>
                <td class="c-tutar alacak"><?= $s['alacak'] ? $tl($s['alacak']) : '' ?></td>
                <td class="c-bakiye"><?= $bk($s['bakiye']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3">TOPLAM</td>
                <td class="c-tutar borc"><?= $tl($donemBorc) ?></td>
                <td class="c-tutar alacak"><?= $tl($donemAlacak) ?></td>
                <td class="c-bakiye"><?= $bk($bakiye) ?></td>
              </tr>
            </tfoot>
          </table>
          <div class="ek-not">(B): Müşteri borçlu &nbsp;·&nbsp; (A): Müşteri alacaklı. Nakit satışlar hem borç hem alacak olarak yazılır, bakiyeyi etkilemez.</div>
        </div>
      </div>
    </div>
  </main>
</div>
<script>
function ekstrePdf() {
  var html = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><style>body{margin:0}'
    + document.getElementById('ekCss').textContent + '</style></head><body>'
    + document.getElementById('ekDoc').outerHTML + '</body></html>';
  if (window.electronAPI && window.electronAPI.savePdf) {
    var btn = document.getElementById('pdfBtn');
    btn.disabled = true; btn.textContent = 'Hazırlanıyor…';
    window.electronAPI.savePdf(html).finally(function(){ btn.disabled = false; btn.textContent = 'PDF Kaydet'; });
  } else {
    window.print();
  }
}
</script>
</body>
</html>
