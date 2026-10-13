<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'tedarikci-ekle';
$uid = userId();

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad     = trim($_POST['ad'] ?? '');
    $tel    = trim($_POST['telefon'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $adres  = trim($_POST['adres'] ?? '');
    $not_   = trim($_POST['not_'] ?? '');

    if ($ad === '') {
        $hata = 'Tedarikçi adı zorunludur.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO tedarikciler (user_id, ad, telefon, email, adres, not_) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$uid, $ad, $tel ?: null, $email ?: null, $adres ?: null, $not_ ?: null]);
        header('Location: /tedarikciler');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yeni Tedarikçi — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="page-header">
      <div class="page-header-left">
        <a href="/tedarikciler" class="btn btn-ghost btn-sm" style="margin-right:.5rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <div class="page-title">Yeni Tedarikçi</div>
      </div>
    </div>

    <div class="page-body" style="max-width:540px">
      <?php if ($hata): ?>
      <div class="alert alert-danger" style="margin-bottom:1rem"><?= htmlspecialchars($hata) ?></div>
      <?php endif; ?>

      <div class="panel" style="padding:1.5rem">
        <form method="post" action="/tedarikci-ekle">
          <div class="form-group">
            <label class="form-label">Tedarikçi Adı <span style="color:var(--red)">*</span></label>
            <input type="text" name="ad" class="form-input" required autofocus placeholder="Firma veya kişi adı" value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Telefon</label>
            <input type="text" name="telefon" class="form-input" placeholder="0555 123 45 67" value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">E-posta</label>
            <input type="email" name="email" class="form-input" placeholder="ornek@firma.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Adres</label>
            <textarea name="adres" class="form-input" rows="2" placeholder="Firma adresi..."><?= htmlspecialchars($_POST['adres'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Not</label>
            <textarea name="not_" class="form-input" rows="2" placeholder="Ek notlar..."><?= htmlspecialchars($_POST['not_'] ?? '') ?></textarea>
          </div>
          <div style="display:flex;gap:.75rem;margin-top:1.5rem">
            <a href="/tedarikciler" class="btn btn-ghost">İptal</a>
            <button type="submit" class="btn btn-primary">Tedarikçi Ekle</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>
