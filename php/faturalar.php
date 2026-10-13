<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'faturalar';
$uid = userId();

$durum = $_GET['durum'] ?? '';
$q     = trim($_GET['q'] ?? '');

$where = "WHERE t.user_id = ?";
$params = [$uid];

if ($durum !== '' && in_array($durum, ['odendi','odenmedi'])) {
    $where .= " AND f.durum = ?";
    $params[] = $durum;
}
if ($q !== '') {
    $where .= " AND (t.ad LIKE ? OR f.fatura_no LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

$stmt = $pdo->prepare("
    SELECT f.*, t.ad as tedarikci_ad
    FROM faturalar f
    JOIN tedarikciler t ON t.id = f.tedarikci_id
    $where
    ORDER BY f.tarih DESC, f.id DESC
");
$stmt->execute($params);
$faturalar = $stmt->fetchAll();

$ozetStmt = $pdo->prepare("
    SELECT
        COUNT(*) as toplam,
        COALESCE(SUM(genel_toplam),0) as toplam_tutar,
        COALESCE(SUM(CASE WHEN f.durum='odenmedi' THEN genel_toplam ELSE 0 END),0) as bekleyen,
        COALESCE(SUM(CASE WHEN f.durum='odendi' THEN genel_toplam ELSE 0 END),0) as odenen
    FROM faturalar f JOIN tedarikciler t ON t.id=f.tedarikci_id WHERE t.user_id=?
");
$ozetStmt->execute([$uid]);
$ozet = $ozetStmt->fetch();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Faturalar — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Faturalar</div>
      </div>
      <div class="page-header-center">
        <form method="get" action="/faturalar">
          <?php if ($durum): ?><input type="hidden" name="durum" value="<?= htmlspecialchars($durum) ?>"><?php endif; ?>
          <div class="search-bar">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Tedarikçi veya fatura no..." class="search-input" autocomplete="off">
            <?php if ($q !== ''): ?>
            <a href="/faturalar<?= $durum ? '?durum='.htmlspecialchars($durum) : '' ?>" style="color:var(--ink3);line-height:0">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <div class="page-header-right">
        <a href="/fatura-ekle" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Fatura
        </a>
      </div>
    </div>

    <!-- Özet Kartları -->
    <div style="display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap">
      <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.65rem 1rem;min-width:120px">
        <div style="font-size:.68rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Toplam</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--ink)"><?= $ozet['toplam'] ?></div>
      </div>
      <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.65rem 1rem;min-width:160px">
        <div style="font-size:.68rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Bekleyen Tutar</div>
        <div style="font-size:1.3rem;font-weight:800;color:<?= $ozet['bekleyen'] > 0 ? 'var(--red,#dc2626)' : 'var(--ink3)' ?>">
          <?= $ozet['bekleyen'] > 0 ? '₺' . number_format($ozet['bekleyen'], 2, ',', '.') : '—' ?>
        </div>
      </div>
      <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.65rem 1rem;min-width:160px">
        <div style="font-size:.68rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Ödenen Tutar</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--green,#16a34a)">
          <?= $ozet['odenen'] > 0 ? '₺' . number_format($ozet['odenen'], 2, ',', '.') : '—' ?>
        </div>
      </div>
    </div>

    <!-- Durum Filtresi -->
    <div style="display:flex;gap:.5rem;margin-bottom:1rem">
      <a href="/faturalar<?= $q ? '?q='.urlencode($q) : '' ?>" class="btn btn-sm <?= $durum === '' ? 'btn-primary' : 'btn-ghost' ?>">Tümü</a>
      <a href="/faturalar?durum=odenmedi<?= $q ? '&q='.urlencode($q) : '' ?>" class="btn btn-sm <?= $durum === 'odenmedi' ? 'btn-primary' : 'btn-ghost' ?>">Bekliyor</a>
      <a href="/faturalar?durum=odendi<?= $q ? '&q='.urlencode($q) : '' ?>" class="btn btn-sm <?= $durum === 'odendi' ? 'btn-primary' : 'btn-ghost' ?>">Ödendi</a>
    </div>

    <div class="page-body">
      <?php if (empty($faturalar)): ?>
        <div class="empty-state">
          <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          <h3><?= ($q !== '' || $durum !== '') ? 'Kayıt bulunamadı' : 'Henüz fatura yok' ?></h3>
          <p><?= ($q !== '' || $durum !== '') ? 'Filtreye uygun fatura bulunamadı.' : 'İlk faturanızı ekleyerek başlayın.' ?></p>
          <?php if ($q === '' && $durum === ''): ?><a href="/fatura-ekle" class="btn btn-primary">İlk Faturayı Ekle</a><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="panel">
          <table class="data-table">
            <thead>
              <tr>
                <th>Fatura No</th>
                <th>Tedarikçi</th>
                <th>Tarih</th>
                <th>Vade</th>
                <th style="text-align:right">Genel Toplam</th>
                <th style="text-align:center">Durum</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($faturalar as $f): ?>
              <tr class="clickable" onclick="location.href='/fatura?id=<?= $f['id'] ?>'">
                <td style="font-weight:600;color:var(--ink)"><?= htmlspecialchars($f['fatura_no'] ?: '—') ?></td>
                <td style="font-size:.85rem;color:var(--ink2)"><?= htmlspecialchars($f['tedarikci_ad']) ?></td>
                <td class="text-muted" style="font-size:.82rem"><?= date('d.m.Y', strtotime($f['tarih'])) ?></td>
                <td class="text-muted" style="font-size:.82rem">
                  <?php if ($f['vade_tarihi']): ?>
                    <?php $vadeGecti = strtotime($f['vade_tarihi']) < time() && $f['durum'] !== 'odendi'; ?>
                    <span style="color:<?= $vadeGecti ? 'var(--red,#dc2626)' : 'var(--ink2)' ?>"><?= date('d.m.Y', strtotime($f['vade_tarihi'])) ?></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td style="text-align:right;font-family:var(--font-display);font-weight:800;font-variant-numeric:tabular-nums">
                  ₺<?= number_format($f['genel_toplam'], 2, ',', '.') ?>
                </td>
                <td style="text-align:center">
                  <?php if ($f['durum'] === 'odendi'): ?>
                    <span class="badge badge-green">Ödendi</span>
                  <?php elseif ($f['vade_tarihi'] && strtotime($f['vade_tarihi']) < time()): ?>
                    <span class="badge badge-red">Vadesi Geçti</span>
                  <?php else: ?>
                    <span class="badge badge-yellow">Bekliyor</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right" onclick="event.stopPropagation()">
                  <a href="/fatura?id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm">Detay</a>
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
</body>
</html>
