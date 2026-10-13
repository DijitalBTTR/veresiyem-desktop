<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'tedarikciler';
$uid = userId();

$q = trim($_GET['q'] ?? '');

if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM tedarikciler WHERE user_id = ? AND (ad LIKE ? OR telefon LIKE ? OR email LIKE ?) ORDER BY ad ASC");
    $stmt->execute([$uid, "%$q%", "%$q%", "%$q%"]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM tedarikciler WHERE user_id = ? ORDER BY ad ASC");
    $stmt->execute([$uid]);
}
$tedarikciler = $stmt->fetchAll();

// Her tedarikçi için fatura özeti
$faturaSayilari = [];
$faturaToplam   = [];
foreach ($tedarikciler as $t) {
    $fs = $pdo->prepare("SELECT COUNT(*) as sayi, COALESCE(SUM(genel_toplam),0) as toplam FROM faturalar WHERE tedarikci_id = ?");
    $fs->execute([$t['id']]);
    $row = $fs->fetch();
    $faturaSayilari[$t['id']] = $row['sayi'];
    $faturaToplam[$t['id']]   = $row['toplam'];
}

$ozet = $pdo->prepare("SELECT COUNT(*) as toplam_tedarikci FROM tedarikciler WHERE user_id = ?");
$ozet->execute([$uid]);
$ozet = $ozet->fetch();

$toplamFatura = $pdo->prepare("SELECT COUNT(*) as sayi, COALESCE(SUM(genel_toplam),0) as toplam FROM faturalar f JOIN tedarikciler t ON t.id=f.tedarikci_id WHERE t.user_id=?");
$toplamFatura->execute([$uid]);
$toplamFatura = $toplamFatura->fetch();

$avatarRenkler = ['#DCFCE7:#16A34A','#DBEAFE:#2563EB','#FEF3C7:#D97706','#FEE2E2:#DC2626','#F3E8FF:#7C3AED','#FFEDD5:#EA580C'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tedarikçiler — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Tedarikçiler</div>
      </div>
      <div class="page-header-center">
        <form method="get" action="/tedarikciler">
          <div class="search-bar">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Tedarikçi adı ara..." class="search-input" autocomplete="off">
            <?php if ($q !== ''): ?>
            <a href="/tedarikciler" style="color:var(--ink3);line-height:0">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <div class="page-header-right">
        <a href="/tedarikci-ekle" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Tedarikçi
        </a>
      </div>
    </div>

    <!-- Özet Kartları -->
    <div style="display:flex;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap">
      <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.75rem 1.25rem;min-width:140px">
        <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Toplam Tedarikçi</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--ink);line-height:1.3"><?= $ozet['toplam_tedarikci'] ?></div>
      </div>
      <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.75rem 1.25rem;min-width:140px">
        <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Toplam Fatura</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--ink);line-height:1.3"><?= $toplamFatura['sayi'] ?></div>
      </div>
      <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:.75rem 1.25rem;min-width:200px">
        <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Toplam Fatura Tutarı</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--blue,#2563EB);line-height:1.3">₺<?= number_format($toplamFatura['toplam'], 2, ',', '.') ?></div>
      </div>
    </div>

    <div class="page-body">
      <?php if (empty($tedarikciler)): ?>
        <div class="empty-state">
          <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          <h3><?= $q !== '' ? 'Sonuç bulunamadı' : 'Henüz tedarikçi yok' ?></h3>
          <p><?= $q !== '' ? '"' . htmlspecialchars($q) . '" için kayıt bulunamadı.' : 'İlk tedarikçinizi ekleyerek başlayın.' ?></p>
          <?php if ($q === ''): ?><a href="/tedarikci-ekle" class="btn btn-primary">İlk Tedarikçiyi Ekle</a><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="panel">
          <table class="data-table">
            <thead>
              <tr>
                <th>Tedarikçi</th>
                <th>Telefon</th>
                <th>E-posta</th>
                <th style="text-align:center">Fatura Sayısı</th>
                <th style="text-align:right">Toplam Tutar</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tedarikciler as $i => $t):
                $words = explode(' ', trim($t['ad']));
                $harf  = '';
                foreach (array_slice($words, 0, 2) as $w) $harf .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
                $rk = explode(':', $avatarRenkler[$i % count($avatarRenkler)]);
                $sayi    = $faturaSayilari[$t['id']] ?? 0;
                $toplam  = $faturaToplam[$t['id']] ?? 0;
              ?>
              <tr class="clickable" onclick="location.href='/tedarikci?id=<?= $t['id'] ?>'">
                <td>
                  <div style="display:flex;align-items:center;gap:.75rem">
                    <div style="width:36px;height:36px;border-radius:10px;background:<?= $rk[0] ?>;color:<?= $rk[1] ?>;display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:900;font-size:.75rem;flex-shrink:0">
                      <?= htmlspecialchars($harf) ?>
                    </div>
                    <div>
                      <div style="font-weight:600;color:var(--ink);font-size:.87rem"><?= htmlspecialchars($t['ad']) ?></div>
                      <?php if ($t['adres']): ?><div style="font-size:.7rem;color:var(--ink3)"><?= htmlspecialchars(mb_substr($t['adres'], 0, 40, 'UTF-8')) . (mb_strlen($t['adres'], 'UTF-8') > 40 ? '…' : '') ?></div><?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($t['telefon'] ?? '—') ?></td>
                <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($t['email'] ?? '—') ?></td>
                <td style="text-align:center">
                  <span class="badge badge-blue"><?= $sayi ?> fatura</span>
                </td>
                <td style="text-align:right">
                  <span style="font-family:var(--font-display);font-weight:800;font-size:.93rem;font-variant-numeric:tabular-nums;color:var(--blue,#2563EB)">
                    <?= $toplam > 0 ? '₺' . number_format($toplam, 2, ',', '.') : '—' ?>
                  </span>
                </td>
                <td style="text-align:right" onclick="event.stopPropagation()">
                  <a href="/fatura-ekle?tedarikci_id=<?= $t['id'] ?>" class="btn btn-primary btn-sm" title="Fatura Ekle">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  </a>
                  <a href="/tedarikci-duzenle?id=<?= $t['id'] ?>" class="btn btn-ghost btn-sm">Düzenle</a>
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
