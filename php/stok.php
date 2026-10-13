<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/sync.php';
girisGerekli();
$activePage = 'stok';

$uid = userId();
syncStoklar($pdo, $uid);
$q   = trim($_GET['q'] ?? '');

if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM stoklar WHERE user_id=? AND (ad LIKE ? OR barkod LIKE ? OR aciklama LIKE ?) ORDER BY ad ASC");
    $stmt->execute([$uid, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%']);
} else {
    $stmt = $pdo->prepare("SELECT * FROM stoklar WHERE user_id=? ORDER BY ad ASC");
    $stmt->execute([$uid]);
}
$stoklar = $stmt->fetchAll();

$ozet = $pdo->prepare("SELECT COUNT(*) AS adet, SUM(CASE WHEN miktar<=5 THEN 1 ELSE 0 END) AS kritik FROM stoklar WHERE user_id=?");
$ozet->execute([$uid]);
$ozet = $ozet->fetch();

$pageTitle = 'Stok';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stok — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Stok Yönetimi</div>
        <div class="page-sub"><?= $ozet['adet'] ?> ürün<?php if ($ozet['kritik'] > 0): ?> &nbsp;·&nbsp; <span style="color:var(--gold)"><?= $ozet['kritik'] ?> kritik</span><?php endif; ?></div>
      </div>
      <div class="page-header-center">
        <form method="get" action="/stok">
          <div class="search-bar">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Stok adı ara..." class="search-input" autocomplete="off">
            <?php if ($q !== ''): ?>
            <a href="/stok" style="color:var(--ink3);line-height:0">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <div class="page-header-right">
        <a href="/stok-ekle" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Stok
        </a>
      </div>
    </div>

    <div class="page-body">
      <?php if (empty($stoklar)): ?>
        <div class="empty-state">
          <svg width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.3" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
          <h3><?= $q !== '' ? 'Arama sonucu bulunamadı' : 'Henüz stok eklenmemiş' ?></h3>
          <?php if ($q === ''): ?><a href="/stok-ekle" class="btn btn-primary">İlk Stoku Ekle</a><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="panel">
          <table class="data-table">
            <thead>
              <tr>
                <th>Stok Adı</th>
                <th>Birim</th>
                <th style="text-align:right">Miktar</th>
                <th style="text-align:right">Birim Fiyat</th>
                <th>Barkod</th>
                <th>Açıklama</th>
                <th style="text-align:center">İşlem</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($stoklar as $s): ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:.6rem">
                    <div style="width:32px;height:32px;border-radius:8px;background:<?= $s['miktar'] <= 5 ? 'var(--gold-lt)' : 'var(--surface2)' ?>;color:<?= $s['miktar'] <= 5 ? 'var(--gold)' : 'var(--ink3)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    </div>
                    <strong style="font-size:.87rem"><?= htmlspecialchars($s['ad']) ?></strong>
                    <?php if ($s['miktar'] <= 5): ?><span class="badge badge-gold">Kritik</span><?php endif; ?>
                  </div>
                </td>
                <td><span class="badge badge-gray"><?= htmlspecialchars($s['birim']) ?></span></td>
                <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:700" class="<?= $s['miktar'] <= 0 ? 'text-red' : ($s['miktar'] <= 5 ? 'text-muted' : '') ?>">
                  <?= number_format($s['miktar'], 2, ',', '.') ?>
                </td>
                <td style="text-align:right;font-variant-numeric:tabular-nums">
                  <?php if ($s['nakit_fiyat'] !== null || $s['vadeli_fiyat'] !== null): ?>
                    <?php if ($s['nakit_fiyat'] !== null): ?><span style="font-size:.72rem;color:var(--ink3)">Nakit:</span> ₺<?= number_format($s['nakit_fiyat'], 2, ',', '.') ?><?php endif; ?>
                    <?php if ($s['vadeli_fiyat'] !== null): ?> &nbsp;<span style="font-size:.72rem;color:var(--ink3)">Vadeli:</span> ₺<?= number_format($s['vadeli_fiyat'], 2, ',', '.') ?><?php endif; ?>
                  <?php elseif ($s['fiyat'] !== null): ?>
                    ₺<?= number_format($s['fiyat'], 2, ',', '.') ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:.78rem;font-family:monospace;color:var(--ink3)"><?= htmlspecialchars($s['barkod'] ?? '') ?: '<span style="color:var(--ink4)">—</span>' ?></td>
                <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($s['aciklama'] ?? '') ?: '—' ?></td>
                <td style="text-align:center;white-space:nowrap">
                  <a href="/stok-duzenle?id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">Düzenle</a>
                  <form method="post" action="/stok-sil" data-confirm-sil style="display:inline">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:var(--red-lt);color:var(--red)">Sil</button>
                  </form>
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
