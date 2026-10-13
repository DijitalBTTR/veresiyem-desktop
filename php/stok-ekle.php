<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'stok';

$error = '';
$form  = ['ad' => '', 'birim' => 'adet', 'miktar' => '', 'alis_fiyat' => '', 'nakit_fiyat' => '', 'vadeli_fiyat' => '', 'aciklama' => '', 'barkod' => ''];
$birimler = ['adet', 'kg', 'litre', 'metre', 'çuval', 'koli', 'paket', 'ton'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol();
    $form['ad']           = trim($_POST['ad'] ?? '');
    $form['birim']        = trim($_POST['birim'] ?? 'adet');
    $form['miktar']       = trim($_POST['miktar'] ?? '0');
    $form['alis_fiyat']   = trim($_POST['alis_fiyat'] ?? '');
    $form['nakit_fiyat']  = trim($_POST['nakit_fiyat'] ?? '');
    $form['vadeli_fiyat'] = trim($_POST['vadeli_fiyat'] ?? '');
    $form['aciklama']     = trim($_POST['aciklama'] ?? '');
    $form['barkod']       = trim($_POST['barkod'] ?? '');

    $miktar       = (float)str_replace(',', '.', $form['miktar']);
    $alis_fiyat   = $form['alis_fiyat']   !== '' ? (float)str_replace(',', '.', $form['alis_fiyat'])   : null;
    $nakit_fiyat  = $form['nakit_fiyat']  !== '' ? (float)str_replace(',', '.', $form['nakit_fiyat'])  : null;
    $vadeli_fiyat = $form['vadeli_fiyat'] !== '' ? (float)str_replace(',', '.', $form['vadeli_fiyat']) : null;

    if ($form['ad'] === '') {
        $error = 'Stok adı zorunludur.';
    } elseif (!in_array($form['birim'], $birimler)) {
        $error = 'Geçersiz birim.';
    } else {
        require_once __DIR__ . '/includes/sync.php';
        $payload = [
            'ad' => $form['ad'], 'birim' => $form['birim'], 'miktar' => $miktar,
            'alis_fiyat' => $alis_fiyat, 'nakit_fiyat' => $nakit_fiyat,
            'vadeli_fiyat' => $vadeli_fiyat, 'aciklama' => $form['aciklama'] ?: null,
            'barkod' => $form['barkod'] ?: null,
        ];
        $sRes  = vpsYaz('post', '/stok', $payload);
        $sApiId = $sRes['data']['id'] ?? $sRes['stok']['id'] ?? null;
        $pdo->prepare("INSERT INTO stoklar (user_id, ad, birim, miktar, fiyat, alis_fiyat, nakit_fiyat, vadeli_fiyat, aciklama, barkod, api_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([userId(), $form['ad'], $form['birim'], $miktar, $alis_fiyat, $alis_fiyat, $nakit_fiyat, $vadeli_fiyat, $form['aciklama'] ?: null, $form['barkod'] ?: null, $sApiId]);
        header('Location: /stok');
        exit;
    }
}
$pageTitle = 'Yeni Stok';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yeni Stok — Veresiyem</title>
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
        <div class="page-title">Yeni Stok Ekle</div>
      </div>
    </div>

    <div class="page-body">
      <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div style="max-width:520px">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--gold)"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
              Stok Bilgileri
            </div>
          </div>
          <div style="padding:1.5rem">
            <form method="post" action="/stok-ekle">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">

              <div class="form-group">
                <label class="form-label">Barkod <span style="font-size:.78rem;font-weight:400;color:var(--ink3)">(opsiyonel)</span></label>
                <input type="text" name="barkod" id="barkodInput" class="form-input" value="<?= htmlspecialchars($form['barkod']) ?>" placeholder="Barkod okutun veya yazın..." style="font-family:monospace;letter-spacing:.05em">
                <div style="font-size:.75rem;color:var(--ink3);margin-top:.3rem">Barkod okuyucu cihazınızla direkt okutabilirsiniz.</div>
              </div>

              <div class="form-group">
                <label class="form-label">Stok Adı <span style="color:var(--red)">*</span></label>
                <input type="text" name="ad" id="stokAdInput" class="form-input" value="<?= htmlspecialchars($form['ad']) ?>" required placeholder="Ürün / stok adı">
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                  <label class="form-label">Birim <span style="color:var(--red)">*</span></label>
                  <select name="birim" class="form-input form-select" required>
                    <?php foreach ($birimler as $b): ?>
                      <option value="<?= $b ?>" <?= $form['birim'] === $b ? 'selected' : '' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Başlangıç Miktarı</label>
                  <input type="number" name="miktar" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($form['miktar']) ?>" placeholder="0">
                </div>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div class="form-group">
                  <label class="form-label">Alış Fiyatı (₺)</label>
                  <input type="number" name="alis_fiyat" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($form['alis_fiyat']) ?>" placeholder="0,00">
                </div>
                <div class="form-group">
                  <label class="form-label">Nakit Satış (₺)</label>
                  <input type="number" name="nakit_fiyat" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($form['nakit_fiyat']) ?>" placeholder="0,00">
                </div>
                <div class="form-group">
                  <label class="form-label">Vadeli Satış (₺)</label>
                  <input type="number" name="vadeli_fiyat" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($form['vadeli_fiyat']) ?>" placeholder="0,00">
                </div>
              </div>

              <div class="form-group">
                <label class="form-label">Açıklama</label>
                <textarea name="aciklama" class="form-input form-textarea" rows="3" placeholder="İsteğe bağlı açıklama..."><?= htmlspecialchars($form['aciklama']) ?></textarea>
              </div>

              <div style="display:flex;gap:.75rem;margin-top:.5rem">
                <button type="submit" class="btn btn-primary">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Stok Ekle
                </button>
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
// Barkod okuyucu Enter gönderiyor — her durumda engelle, stok adına focus at
var barkodInp = document.getElementById('barkodInput');
['keydown','keypress','keyup'].forEach(function(ev) {
  barkodInp.addEventListener(ev, function(e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
      e.preventDefault();
      e.stopPropagation();
      if (ev === 'keydown') document.getElementById('stokAdInput').focus();
    }
  });
});
// Form submit — barkod input focus'tayken gönderilmesin
barkodInp.form && barkodInp.form.addEventListener('submit', function(e) {
  if (document.activeElement === barkodInp) {
    e.preventDefault();
    document.getElementById('stokAdInput').focus();
  }
});
</script>
</body>
</html>
