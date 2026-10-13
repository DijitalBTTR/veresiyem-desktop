<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'kasa';

$id  = (int)($_GET['id'] ?? 0);
$uid = userId();
if (!$id) { header('Location: /kasa'); exit; }

$stmt = $pdo->prepare("SELECT * FROM kasa_hesaplari WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $uid]);
$hesap = $stmt->fetch();
if (!$hesap) { header('Location: /kasa'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol();
    $ad         = trim($_POST['ad'] ?? '');
    $aciklama   = trim($_POST['aciklama'] ?? '');
    $renk       = trim($_POST['renk'] ?? '#3B82F6');
    $varsayilan = isset($_POST['varsayilan']) ? 1 : 0;
    if ($ad) {
        if ($varsayilan) {
            $pdo->prepare("UPDATE kasa_hesaplari SET varsayilan=0 WHERE user_id=?")->execute([$uid]);
        }
        $pdo->prepare("UPDATE kasa_hesaplari SET ad=?, aciklama=?, renk=?, varsayilan=? WHERE id=? AND user_id=?")
            ->execute([$ad, $aciklama ?: null, $renk, $varsayilan, $id, $uid]);
        if (!empty($hesap['api_id'])) {
            require_once __DIR__ . '/includes/sync.php';
            vpsYaz('put', '/kasa/hesaplar/' . $hesap['api_id'], [
                'ad' => $ad, 'aciklama' => $aciklama ?: null, 'renk' => $renk, 'varsayilan' => $varsayilan,
            ]);
        }
    }
    header('Location: /kasa'); exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hesap Düzenle — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <a href="/kasa" class="breadcrumb">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          Kasa
        </a>
        <div class="page-title">Hesap Düzenle</div>
      </div>
    </div>

    <div class="page-body">
      <div style="max-width:480px">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><?= htmlspecialchars($hesap['ad']) ?></div>
          </div>
          <div style="padding:1.5rem">
            <form method="post" action="/kasa-duzenle?id=<?= $id ?>">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">

              <div class="form-group">
                <label class="form-label">Hesap Adı <span style="color:var(--red)">*</span></label>
                <input type="text" name="ad" class="form-input" value="<?= htmlspecialchars($hesap['ad']) ?>" required autofocus>
              </div>

              <div class="form-group">
                <label class="form-label">Açıklama</label>
                <input type="text" name="aciklama" class="form-input" value="<?= htmlspecialchars($hesap['aciklama'] ?? '') ?>" placeholder="İsteğe bağlı">
              </div>

              <div class="form-group">
                <label class="form-label">Renk</label>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.25rem">
                  <?php foreach (['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#06B6D4','#6B7280'] as $r): ?>
                  <label style="cursor:pointer">
                    <input type="radio" name="renk" value="<?= $r ?>" <?= $hesap['renk']===$r ? 'checked' : '' ?> style="display:none">
                    <span style="display:block;width:28px;height:28px;border-radius:50%;background:<?= $r ?>;border:3px solid <?= $hesap['renk']===$r ? '#fff' : 'transparent' ?>;box-shadow:<?= $hesap['renk']===$r ? '0 0 0 2px '.$r : 'none' ?>" class="renk-dot"></span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="form-group" style="margin-bottom:.5rem">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.86rem">
                  <input type="checkbox" name="varsayilan" value="1" <?= !empty($hesap['varsayilan']) ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:var(--accent)">
                  <span>Varsayılan kasa hesabı olarak ayarla</span>
                </label>
                <div class="form-hint">Hızlı satışta otomatik seçilir</div>
              </div>
              <div style="display:flex;gap:.75rem;margin-top:1rem">
                <button type="submit" class="btn btn-primary">Kaydet</button>
                <a href="/kasa" class="btn btn-ghost">İptal</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<script>
// Renk seçiminde border güncelle
document.querySelectorAll('input[name="renk"]').forEach(function(r) {
  r.addEventListener('change', function() {
    document.querySelectorAll('input[name="renk"]').forEach(function(x) {
      var dot = x.nextElementSibling;
      dot.style.border = x.checked ? '3px solid #fff' : '3px solid transparent';
      dot.style.boxShadow = x.checked ? '0 0 0 2px '+x.value : 'none';
    });
  });
});
</script>
</body>
</html>
