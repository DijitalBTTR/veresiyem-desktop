<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/sync.php';
girisGerekli();
$activePage = 'cariler';

$uid = userId();
// VPS'ten carileri çekip SQLite'ı güncelle (sayfa açılışında)
syncCariler($pdo, $uid);

$q   = trim($_GET['q'] ?? '');

if ($q !== '') {
    $stmt = $pdo->prepare("SELECT id, ad, telefon FROM cariler WHERE user_id = ? AND ad LIKE ? ORDER BY ad ASC");
    $stmt->execute([$uid, '%' . $q . '%']);
} else {
    $stmt = $pdo->prepare("SELECT id, ad, telefon FROM cariler WHERE user_id = ? ORDER BY ad ASC");
    $stmt->execute([$uid]);
}
$cariler = $stmt->fetchAll();

$bakiyeler = [];
foreach ($cariler as $c) {
    $bStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN tip='borc' THEN tutar ELSE 0 END),0) AS vadeli
         FROM hareketler WHERE cari_id = ?"
    );
    $bStmt->execute([$c['id']]);
    $row = $bStmt->fetch();
    $tStmt = $pdo->prepare("SELECT COALESCE(SUM(tutar),0) AS tahsilat FROM tahsilatlar WHERE cari_id = ?");
    $tStmt->execute([$c['id']]);
    $tRow = $tStmt->fetch();
    // Kalan borç: vadeli satışlar - tahsilatlar (nakit satışlar zaten ödenmiş)
    $bakiyeler[$c['id']] = max(0, $row['vadeli'] - $tRow['tahsilat']);
}

$ozet = $pdo->prepare("
    SELECT COUNT(DISTINCT c.id) AS toplam_cari,
      COALESCE(SUM(
        MAX(0,
          COALESCE((SELECT SUM(h.tutar) FROM hareketler h WHERE h.cari_id=c.id AND h.tip='borc'),0) -
          COALESCE((SELECT SUM(t.tutar) FROM tahsilatlar t WHERE t.cari_id=c.id),0)
        )
      ),0) AS toplam_kalan_borc
    FROM cariler c WHERE c.user_id = ?
");
$ozet->execute([$uid]);
$ozet = $ozet->fetch();

$avatarRenkler = ['#DCFCE7:#16A34A','#DBEAFE:#2563EB','#FEF3C7:#D97706','#FEE2E2:#DC2626','#F3E8FF:#7C3AED','#FFEDD5:#EA580C'];

$pageTitle = 'Cariler';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cariler — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <?php $toplamKalan = max(0, $ozet['toplam_kalan_borc']); ?>
        <div class="page-title">Cariler</div>
      </div>
      <div class="page-header-center">
        <form method="get" action="/cariler">
          <div class="search-bar">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Cari adı ara..." class="search-input" autocomplete="off">
            <?php if ($q !== ''): ?>
            <a href="/cariler" style="color:var(--ink3);line-height:0">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <div class="page-header-right">
        <a href="/cari-ozet" class="btn btn-secondary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
          Borç-Alacak Raporu
        </a>
        <a href="/cari-ekle" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Cari
        </a>
      </div>
    </div>

    <!-- Özet Kartları -->
    <div style="display:flex;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap">
      <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.75rem 1.25rem;min-width:140px;display:flex;flex-direction:column;gap:.2rem">
        <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Toplam Cari</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--ink);line-height:1"><?= $ozet['toplam_cari'] ?></div>
      </div>
      <div style="background:var(--surface);border:1.5px solid <?= $toplamKalan > 0 ? 'rgba(220,38,38,.25)' : 'var(--border)' ?>;border-radius:var(--r);padding:.75rem 1.25rem;min-width:200px;display:flex;flex-direction:column;gap:.2rem">
        <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Toplam Kalan Borç</div>
        <div style="font-size:1.5rem;font-weight:800;color:<?= $toplamKalan > 0 ? 'var(--red)' : 'var(--green)' ?>;line-height:1"><?= $toplamKalan > 0 ? '₺' . number_format($toplamKalan, 2, ',', '.') : 'Borç yok' ?></div>
      </div>
    </div>

    <div class="page-body">
      <?php if (empty($cariler)): ?>
        <div class="empty-state">
          <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <h3><?= $q !== '' ? 'Sonuç bulunamadı' : 'Henüz cari yok' ?></h3>
          <p><?= $q !== '' ? '"' . htmlspecialchars($q) . '" için kayıt bulunamadı.' : 'İlk carinizi ekleyerek başlayın.' ?></p>
          <?php if ($q === ''): ?><a href="/cari-ekle" class="btn btn-primary">İlk Cariyi Ekle</a><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="panel">
          <table class="data-table">
            <thead>
              <tr>
                <th>Cari</th>
                <th>Telefon</th>
                <th style="text-align:right">Kalan Borç</th>
                <th style="text-align:center">Durum</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cariler as $i => $c):
                $bakiye = $bakiyeler[$c['id']] ?? 0;
                $words  = explode(' ', trim($c['ad']));
                $harf   = '';
                foreach (array_slice($words, 0, 2) as $w) $harf .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
                $rk = explode(':', $avatarRenkler[$i % count($avatarRenkler)]);
              ?>
              <tr class="clickable" onclick="location.href='/cari?id=<?= $c['id'] ?>'">
                <td>
                  <div style="display:flex;align-items:center;gap:.75rem">
                    <div style="width:36px;height:36px;border-radius:10px;background:<?= $rk[0] ?>;color:<?= $rk[1] ?>;display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:900;font-size:.75rem;flex-shrink:0">
                      <?= htmlspecialchars($harf) ?>
                    </div>
                    <div>
                      <div style="font-weight:600;color:var(--ink);font-size:.87rem"><?= htmlspecialchars($c['ad']) ?></div>
                      <?php if ($c['telefon']): ?><div style="font-size:.7rem;color:var(--ink3)"><?= htmlspecialchars($c['telefon']) ?></div><?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($c['telefon'] ?? '—') ?></td>
                <td style="text-align:right">
                  <span style="font-family:var(--font-display);font-weight:800;font-size:.93rem;font-variant-numeric:tabular-nums" class="<?= $bakiye > 0 ? 'text-red' : 'text-muted' ?>">
                    <?= $bakiye > 0 ? '₺' . number_format($bakiye, 2, ',', '.') : '—' ?>
                  </span>
                </td>
                <td style="text-align:center">
                  <?php if ($bakiye > 0): ?>
                    <span class="badge badge-red">Borcu Var</span>
                  <?php else: ?>
                    <span class="badge badge-green">Borcu Yok</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right" onclick="event.stopPropagation()">
                  <button onclick="hizliAc(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['ad'])) ?>', '<?= htmlspecialchars(addslashes($c['telefon'] ?? '')) ?>')" class="btn btn-primary btn-sm" title="Hızlı hareket ekle">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  </button>
                  <a href="/cari-duzenle?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">Düzenle</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Hızlı Hareket Modalı -->
<div class="vrm-overlay" id="hizliModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="vrm-dialog" style="max-width:380px">
    <div style="padding:1.25rem 1.5rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div>
        <div style="font-family:var(--font-display);font-weight:800;font-size:.95rem" id="modalCariAd">Hızlı Hareket</div>
        <div style="font-size:.72rem;color:var(--ink3);margin-top:.1rem" id="modalCariTel"></div>
      </div>
      <button class="btn-icon" onclick="document.getElementById('hizliModal').classList.remove('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="padding:1.25rem 1.5rem">
      <form method="post" id="hizliForm" action="">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="ekle-hareket">
        <input type="hidden" name="tarih" value="<?= date('Y-m-d') ?>">
        <div class="form-group">
          <label class="form-label">İşlem Tipi</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <label class="tip-radio-lbl active-alacak" id="lbl-alacak">
              <input type="radio" name="tip" value="alacak" checked onchange="tipDeg(this)"> <span>↑ Nakit Satış</span>
            </label>
            <label class="tip-radio-lbl" id="lbl-borc">
              <input type="radio" name="tip" value="borc" onchange="tipDeg(this)"> <span>↓ Vadeli Satış</span>
            </label>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Tutar (₺)</label>
          <input type="number" name="tutar" id="hizliTutar" class="form-input" step="0.01" min="0.01" required placeholder="0,00" style="font-size:1.1rem;font-weight:700;text-align:right">
        </div>
        <div class="form-group">
          <label class="form-label">Açıklama <span class="text-muted">(opsiyonel)</span></label>
          <input type="text" name="aciklama" class="form-input" placeholder="Not ekleyin...">
        </div>
      </form>
    </div>
    <div class="vrm-dialog-foot">
      <button class="btn btn-ghost" onclick="document.getElementById('hizliModal').classList.remove('open')">İptal</button>
      <button class="btn btn-primary" onclick="document.getElementById('hizliForm').submit()">Kaydet</button>
    </div>
  </div>
</div>

<style>
.tip-radio-lbl {
  display:flex; align-items:center; gap:.45rem;
  padding:.5rem .75rem; border:1.5px solid var(--border);
  border-radius:var(--r); cursor:pointer; font-size:.84rem; font-weight:600;
  transition:.12s; color:var(--ink2);
}
.tip-radio-lbl input { display:none; }
.tip-radio-lbl.active-alacak { border-color:var(--green); background:var(--green-lt); color:var(--green); }
.tip-radio-lbl.active-borc   { border-color:var(--red);   background:var(--red-lt);   color:var(--red); }
</style>
<script>
function tipDeg(radio) {
  document.getElementById('lbl-alacak').className = 'tip-radio-lbl' + (radio.value === 'alacak' ? ' active-alacak' : '');
  document.getElementById('lbl-borc').className   = 'tip-radio-lbl' + (radio.value === 'borc'   ? ' active-borc'   : '');
}
function hizliAc(id, ad, tel) {
  document.getElementById('hizliForm').action = '/cari?id=' + id;
  document.getElementById('modalCariAd').textContent = ad;
  document.getElementById('modalCariTel').textContent = tel || '';
  document.getElementById('hizliModal').classList.add('open');
  setTimeout(function(){ document.getElementById('hizliTutar').focus(); }, 150);
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') document.getElementById('hizliModal').classList.remove('open');
});
</script>
</body>
</html>
