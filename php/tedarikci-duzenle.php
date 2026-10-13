<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'tedarikci-duzenle';
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM tedarikciler WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $uid]);
$t = $stmt->fetch();
if (!$t) { header('Location: /tedarikciler'); exit; }

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad    = trim($_POST['ad'] ?? '');
    $tel   = trim($_POST['telefon'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $adres = trim($_POST['adres'] ?? '');
    $not_  = trim($_POST['not_'] ?? '');

    if ($ad === '') {
        $hata = 'Tedarikçi adı zorunludur.';
    } else {
        $upd = $pdo->prepare("UPDATE tedarikciler SET ad=?, telefon=?, email=?, adres=?, not_=? WHERE id=? AND user_id=?");
        $upd->execute([$ad, $tel ?: null, $email ?: null, $adres ?: null, $not_ ?: null, $id, $uid]);
        header('Location: /tedarikci?id=' . $id);
        exit;
    }
    $t = array_merge($t, $_POST);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tedarikçi Düzenle — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="page-header">
      <div class="page-header-left">
        <a href="/tedarikci?id=<?= $id ?>" class="btn btn-ghost btn-sm" style="margin-right:.5rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <div class="page-title">Tedarikçi Düzenle</div>
      </div>
    </div>

    <div class="page-body" style="max-width:540px">
      <?php if ($hata): ?>
      <div class="alert alert-danger" style="margin-bottom:1rem"><?= htmlspecialchars($hata) ?></div>
      <?php endif; ?>

      <div class="panel" style="padding:1.5rem">
        <form method="post" action="/tedarikci-duzenle?id=<?= $id ?>">
          <div class="form-group">
            <label class="form-label">Tedarikçi Adı <span style="color:var(--red)">*</span></label>
            <input type="text" name="ad" class="form-input" required autofocus value="<?= htmlspecialchars($t['ad']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Telefon</label>
            <input type="text" name="telefon" class="form-input" value="<?= htmlspecialchars($t['telefon'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">E-posta</label>
            <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($t['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Adres</label>
            <textarea name="adres" class="form-input" rows="2"><?= htmlspecialchars($t['adres'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Not</label>
            <textarea name="not_" class="form-input" rows="2"><?= htmlspecialchars($t['not_'] ?? '') ?></textarea>
          </div>
          <div style="display:flex;gap:.75rem;justify-content:space-between;margin-top:1.5rem">
            <button type="button" class="btn btn-ghost" style="color:var(--red)" id="tedSilBtn">
              Sil
            </button>
            <div style="display:flex;gap:.5rem">
              <a href="/tedarikci?id=<?= $id ?>" class="btn btn-ghost">İptal</a>
              <button type="submit" class="btn btn-primary">Kaydet</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('tedSilBtn');
  if (btn) {
    btn.addEventListener('click', async function(){
      var ok = await window.veresiyemConfirm({
        title: 'Tedarikçi Sil',
        msg: 'Bu tedarikçiyi silmek istediğinizden emin misiniz? İşlem geri alınamaz.',
        okLabel: 'Evet, Sil',
        type: 'red'
      });
      if (ok) window.location.href = '/tedarikci-sil?id=<?= $id ?>';
    });
  }
});
</script>
</body>
</html>
