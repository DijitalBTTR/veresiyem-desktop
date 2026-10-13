<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'stok';

$id  = (int)($_GET['id'] ?? 0);
$uid = userId();
if (!$id) { header('Location: /stok'); exit; }

$stmt = $pdo->prepare("SELECT * FROM stoklar WHERE id=? AND user_id=?");
$stmt->execute([$id, $uid]);
$stok = $stmt->fetch();
if (!$stok) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$error = $success = '';
$birimler = ['adet', 'kg', 'litre', 'metre', 'çuval', 'koli', 'paket', 'ton'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol();
    $ad           = trim($_POST['ad'] ?? '');
    $birim        = trim($_POST['birim'] ?? 'adet');
    $miktar       = (float)str_replace(',', '.', $_POST['miktar'] ?? '0');
    $aciklama     = trim($_POST['aciklama'] ?? '');
    $alis_fiyat   = trim($_POST['alis_fiyat']   ?? '') !== '' ? (float)str_replace(',', '.', $_POST['alis_fiyat'])   : null;
    $nakit_fiyat  = trim($_POST['nakit_fiyat']  ?? '') !== '' ? (float)str_replace(',', '.', $_POST['nakit_fiyat'])  : null;
    $vadeli_fiyat = trim($_POST['vadeli_fiyat'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['vadeli_fiyat']) : null;
    $barkod       = trim($_POST['barkod'] ?? '') ?: null;

    if ($ad === '') {
        $error = 'Stok adı zorunludur.';
    } elseif (!in_array($birim, $birimler)) {
        $error = 'Geçersiz birim.';
    } else {
        require_once __DIR__ . '/includes/sync.php';
        if (!empty($stok['api_id'])) {
            vpsYaz('put', '/stok/' . $stok['api_id'], [
                'ad' => $ad, 'birim' => $birim, 'miktar' => $miktar,
                'alis_fiyat' => $alis_fiyat, 'nakit_fiyat' => $nakit_fiyat,
                'vadeli_fiyat' => $vadeli_fiyat, 'aciklama' => $aciklama ?: null, 'barkod' => $barkod,
            ]);
        }
        $pdo->prepare("UPDATE stoklar SET ad=?, birim=?, miktar=?, fiyat=?, alis_fiyat=?, nakit_fiyat=?, vadeli_fiyat=?, aciklama=?, barkod=? WHERE id=? AND user_id=?")
            ->execute([$ad, $birim, $miktar, $alis_fiyat, $alis_fiyat, $nakit_fiyat, $vadeli_fiyat, $aciklama ?: null, $barkod, $id, $uid]);
        $stok = array_merge($stok, compact('ad', 'birim', 'miktar', 'alis_fiyat', 'nakit_fiyat', 'vadeli_fiyat', 'aciklama', 'barkod'));
        $success = 'Stok güncellendi.';
    }
}
$pageTitle = 'Stok Düzenle';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stok Düzenle — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <a href="/stok" class="breadcrumb">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          Stok
        </a>
        <div class="page-title">Stok Düzenle</div>
      </div>
    </div>

    <div class="page-body">
      <?php if ($error):   ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <div style="max-width:520px">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">Stok Bilgileri</div>
            <span class="badge <?= $stok['miktar'] <= 5 ? 'badge-gold' : 'badge-gray' ?>"><?= number_format($stok['miktar'], 2, ',', '.') ?> <?= htmlspecialchars($stok['birim']) ?></span>
          </div>
          <div style="padding:1.5rem">
            <form method="post" action="/stok-duzenle?id=<?= $id ?>">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">

              <div class="form-group">
                <label class="form-label">Barkod <span style="font-size:.78rem;font-weight:400;color:var(--ink3)">(opsiyonel)</span></label>
                <input type="text" name="barkod" id="barkodInput" class="form-input" value="<?= htmlspecialchars($stok['barkod'] ?? '') ?>" placeholder="Barkod okutun veya yazın..." style="font-family:monospace;letter-spacing:.05em">
                <div style="font-size:.75rem;color:var(--ink3);margin-top:.3rem">Barkod okuyucu cihazınızla direkt okutabilirsiniz.</div>
              </div>

              <div class="form-group">
                <label class="form-label">Stok Adı <span style="color:var(--red)">*</span></label>
                <input type="text" name="ad" id="stokAdInput" class="form-input" value="<?= htmlspecialchars($stok['ad']) ?>" required autofocus>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                  <label class="form-label">Birim <span style="color:var(--red)">*</span></label>
                  <select name="birim" class="form-input form-select" required>
                    <?php foreach ($birimler as $b): ?>
                      <option value="<?= $b ?>" <?= $stok['birim'] === $b ? 'selected' : '' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Mevcut Miktar</label>
                  <input type="number" name="miktar" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($stok['miktar']) ?>">
                </div>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div class="form-group">
                  <label class="form-label">Alış Fiyatı (₺)</label>
                  <input type="number" name="alis_fiyat" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($stok['alis_fiyat'] ?? '') ?>" placeholder="0,00">
                </div>
                <div class="form-group">
                  <label class="form-label">Nakit Satış (₺)</label>
                  <input type="number" name="nakit_fiyat" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($stok['nakit_fiyat'] ?? '') ?>" placeholder="0,00">
                </div>
                <div class="form-group">
                  <label class="form-label">Vadeli Satış (₺)</label>
                  <input type="number" name="vadeli_fiyat" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($stok['vadeli_fiyat'] ?? '') ?>" placeholder="0,00">
                </div>
              </div>

              <div class="form-group">
                <label class="form-label">Açıklama</label>
                <textarea name="aciklama" class="form-input form-textarea" rows="3"><?= htmlspecialchars($stok['aciklama'] ?? '') ?></textarea>
              </div>

              <div style="display:flex;gap:.75rem;margin-top:.5rem">
                <button type="submit" class="btn btn-primary">Kaydet</button>
                <a href="/stok" class="btn btn-ghost">İptal</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<script>
document.getElementById('barkodInput').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('stokAdInput').focus(); }
});
</script>
</body>
</html>
