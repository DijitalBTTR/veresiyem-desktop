<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'gelir-gider';
$uid = userId();

$filtreTip   = $_GET['tip'] ?? '';
$filtreHesap = (int)($_GET['hesap_id'] ?? 0);
$filtreAy    = $_GET['ay'] ?? date('Y-m');
$filtreKat   = (int)($_GET['kategori_id'] ?? 0);

$hesaplar = $pdo->prepare("SELECT * FROM kasa_hesaplari WHERE user_id=? AND aktif=1 ORDER BY varsayilan DESC, ad");
$hesaplar->execute([$uid]);
$hesaplar = $hesaplar->fetchAll();

$kategoriler = $pdo->prepare("SELECT * FROM gelir_gider_kategoriler WHERE user_id=? ORDER BY tip, ad");
$kategoriler->execute([$uid]);
$kategoriler = $kategoriler->fetchAll();

$where  = ["gg.user_id = ?"];
$params = [$uid];
if ($filtreTip)   { $where[] = "gg.tip = ?";         $params[] = $filtreTip; }
if ($filtreHesap) { $where[] = "gg.hesap_id = ?";    $params[] = $filtreHesap; }
if ($filtreKat)   { $where[] = "gg.kategori_id = ?"; $params[] = $filtreKat; }
if ($filtreAy)    { $where[] = "strftime('%Y-%m', gg.tarih) = ?"; $params[] = $filtreAy; }

$sql = "SELECT gg.*, k.ad AS hesap_ad, k.renk AS hesap_renk, gk.ad AS kategori_ad, gk.renk AS kat_renk, c.ad AS cari_ad
        FROM gelir_gider gg
        LEFT JOIN kasa_hesaplari k ON k.id = gg.hesap_id
        LEFT JOIN gelir_gider_kategoriler gk ON gk.id = gg.kategori_id
        LEFT JOIN cariler c ON c.id = gg.cari_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY gg.tarih DESC, gg.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$kayitlar = $st->fetchAll();

$toplamSt = $pdo->prepare("SELECT
    SUM(CASE WHEN gg.tip='gelir' THEN gg.tutar ELSE 0 END) AS toplam_gelir,
    SUM(CASE WHEN gg.tip='gider' THEN gg.tutar ELSE 0 END) AS toplam_gider
FROM gelir_gider gg WHERE " . implode(' AND ', $where));
$toplamSt->execute($params);
$toplamlar    = $toplamSt->fetch();
$toplam_gelir = (float)($toplamlar['toplam_gelir'] ?? 0);
$toplam_gider = (float)($toplamlar['toplam_gider'] ?? 0);
$net = $toplam_gelir - $toplam_gider;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gelir / Gider — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Gelir / Gider</div>
        <div class="page-sub">Tüm gelir ve gider kayıtlarınız</div>
      </div>
      <a href="/gelir-gider-ekle" class="btn btn-primary btn-sm">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Yeni Kayıt
      </a>
    </div>

    <div class="page-body">

      <!-- Özet kartlar -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem">
        <div class="panel" style="padding:.75rem 1rem;border-left:3px solid var(--green)">
          <div class="stat-label">Toplam Gelir</div>
          <div class="stat-val" style="color:var(--green)">+<?= number_format($toplam_gelir,2,',','.') ?> ₺</div>
        </div>
        <div class="panel" style="padding:.75rem 1rem;border-left:3px solid var(--red)">
          <div class="stat-label">Toplam Gider</div>
          <div class="stat-val" style="color:var(--red)">-<?= number_format($toplam_gider,2,',','.') ?> ₺</div>
        </div>
        <div class="panel" style="padding:.75rem 1rem;border-left:3px solid <?= $net >= 0 ? 'var(--accent)' : 'var(--red)' ?>">
          <div class="stat-label">Net</div>
          <div class="stat-val" style="color:<?= $net >= 0 ? 'var(--accent)' : 'var(--red)' ?>"><?= ($net >= 0 ? '+' : '') . number_format($net,2,',','.') ?> ₺</div>
        </div>
      </div>

      <!-- Filtreler -->
      <form method="GET" action="/gelir-gider" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;align-items:flex-end">
        <div>
          <label class="form-label">Ay</label>
          <input type="month" name="ay" value="<?= htmlspecialchars($filtreAy) ?>" class="form-input" style="width:150px">
        </div>
        <div>
          <label class="form-label">Tür</label>
          <select name="tip" class="form-input" style="width:120px">
            <option value="">Tümü</option>
            <option value="gelir" <?= $filtreTip==='gelir' ? 'selected' : '' ?>>Gelir</option>
            <option value="gider" <?= $filtreTip==='gider' ? 'selected' : '' ?>>Gider</option>
          </select>
        </div>
        <div>
          <label class="form-label">Kasa</label>
          <select name="hesap_id" class="form-input" style="width:155px">
            <option value="">Tüm Kasalar</option>
            <?php
            $nakitHes = array_filter($hesaplar, fn($h) => ($h['tip'] ?? 'nakit') === 'nakit');
            $bankaHes = array_filter($hesaplar, fn($h) => ($h['tip'] ?? 'nakit') === 'banka');
            if ($nakitHes): ?><optgroup label="💵 Nakit"><?php foreach ($nakitHes as $h): ?><option value="<?= $h['id'] ?>" <?= $filtreHesap==$h['id'] ? 'selected' : '' ?>><?= htmlspecialchars($h['ad']) ?></option><?php endforeach; ?></optgroup><?php endif;
            if ($bankaHes): ?><optgroup label="🏦 Banka"><?php foreach ($bankaHes as $h): ?><option value="<?= $h['id'] ?>" <?= $filtreHesap==$h['id'] ? 'selected' : '' ?>><?= htmlspecialchars($h['ad']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Kategori</label>
          <select name="kategori_id" class="form-input" style="width:155px">
            <option value="">Tüm Kategoriler</option>
            <?php foreach ($kategoriler as $k): ?>
            <option value="<?= $k['id'] ?>" <?= $filtreKat==$k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['ad']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrele</button>
        <a href="/gelir-gider" class="btn btn-ghost btn-sm">Temizle</a>
      </form>

      <!-- Tablo -->
      <div class="panel">
        <?php if (empty($kayitlar)): ?>
        <div class="empty-state">
          <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          <h3>Kayıt bulunamadı</h3>
          <p>Filtrelerinizi değiştirin veya yeni kayıt ekleyin.</p>
        </div>
        <?php else: ?>
        <table class="data-table">
          <thead><tr>
            <th>Tarih</th><th>Tür</th><th>Kategori</th><th>Açıklama</th><th>Kasa</th><th style="text-align:right">Tutar</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($kayitlar as $r): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.8rem;color:var(--ink3)"><?= date('d.m.Y', strtotime($r['tarih'])) ?></td>
            <td>
              <?php if ($r['tip']==='gelir'): ?>
              <span class="badge" style="background:var(--green-lt);color:var(--green)">↑ Gelir</span>
              <?php else: ?>
              <span class="badge" style="background:var(--red-lt);color:var(--red)">↓ Gider</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['kategori_ad']): ?>
              <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem">
                <span style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($r['kat_renk'] ?? '#999') ?>;flex-shrink:0"></span>
                <?= htmlspecialchars($r['kategori_ad']) ?>
              </span>
              <?php else: ?><span style="color:var(--ink3);font-size:.78rem">—</span><?php endif; ?>
            </td>
            <td style="font-size:.84rem">
              <?= $r['aciklama'] ? htmlspecialchars($r['aciklama']) : '' ?>
              <?php if ($r['cari_ad']): ?>
              <span style="display:block;font-size:.74rem;color:var(--ink3);margin-top:.1rem">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                <?= htmlspecialchars($r['cari_ad']) ?>
              </span>
              <?php elseif (!$r['aciklama']): ?>
              <span style="color:var(--ink3)">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['hesap_ad']): ?>
              <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem">
                <span style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($r['hesap_renk'] ?? '#3B82F6') ?>;flex-shrink:0"></span>
                <?= htmlspecialchars($r['hesap_ad']) ?>
              </span>
              <?php else: ?><span style="color:var(--ink3);font-size:.78rem">—</span><?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;color:<?= $r['tip']==='gelir' ? 'var(--green)' : 'var(--red)' ?>">
              <?= ($r['tip']==='gelir' ? '+' : '-') . number_format($r['tutar'],2,',','.') ?> ₺
            </td>
            <td>
              <div style="display:flex;gap:.25rem;justify-content:flex-end">
                <a href="/gelir-gider-duzenle?id=<?= $r['id'] ?>" class="btn-icon" title="Düzenle">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
                <button type="button" class="btn-icon" style="color:var(--red)" title="Sil"
                  data-sil-href="/gelir-gider-sil?id=<?= $r['id'] ?>">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('[data-sil-href]').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var ok = await window.veresiyemConfirm({
        title: 'Kaydı Sil',
        msg: 'Bu gelir/gider kaydı silinsin mi? İşlem geri alınamaz.',
        okLabel: 'Evet, Sil',
        type: 'red'
      });
      if (ok) window.location.href = btn.dataset.silHref;
    });
  });
});
</script>
</body>
</html>
