<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'cariler';

$id  = (int)($_GET['id'] ?? 0);
$uid = userId();
if (!$id) { header('Location: /cariler'); exit; }

$stmt = $pdo->prepare("SELECT * FROM cariler WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $uid]);
$cari = $stmt->fetch();
if (!$cari) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol();
    $ad           = trim($_POST['ad'] ?? '');
    $telefon      = trim($_POST['telefon'] ?? '');
    $not_         = trim($_POST['not_'] ?? '');
    $varsayilan   = isset($_POST['varsayilan']) ? 1 : 0;
    $musteri_kodu = trim($_POST['musteri_kodu'] ?? '');
    $il           = trim($_POST['il'] ?? '');
    $ilce         = trim($_POST['ilce'] ?? '');
    $adres        = trim($_POST['adres'] ?? '');
    $vergi_tc       = trim($_POST['vergi_tc'] ?? '');
    $vergi_dairesi  = trim($_POST['vergi_dairesi'] ?? '');

    if ($ad === '') {
        $error = 'Cari adı zorunludur.';
    } else {
        if ($varsayilan) {
            $pdo->prepare("UPDATE cariler SET varsayilan=0 WHERE user_id=?")->execute([$uid]);
        }
        $pdo->prepare("UPDATE cariler SET ad=?, telefon=?, not_=?, varsayilan=?, musteri_kodu=?, il=?, ilce=?, adres=?, vergi_tc=?, vergi_dairesi=? WHERE id=? AND user_id=?")
            ->execute([$ad, $telefon ?: null, $not_ ?: null, $varsayilan,
                       $musteri_kodu ?: null, $il ?: null, $ilce ?: null, $adres ?: null, $vergi_tc ?: null, $vergi_dairesi ?: null,
                       $id, $uid]);
        $cari = array_merge($cari, compact('ad','telefon','not_','varsayilan','musteri_kodu','il','ilce','adres','vergi_tc','vergi_dairesi'));
        if (!empty($cari['api_id'])) {
            apiSync('put', '/cariler/' . $cari['api_id'], [
                'ad' => $ad, 'telefon' => $telefon ?: null, 'not_' => $not_ ?: null,
                'varsayilan' => $varsayilan, 'musteri_kodu' => $musteri_kodu ?: null,
                'il' => $il ?: null, 'ilce' => $ilce ?: null, 'adres' => $adres ?: null,
                'vergi_tc' => $vergi_tc ?: null, 'vergi_dairesi' => $vergi_dairesi ?: null,
            ]);
        }
        $success = 'Cari bilgileri güncellendi.';
    }
}
$pageTitle = 'Cari Düzenle';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cari Düzenle — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <a href="/cari?id=<?= $id ?>" class="breadcrumb">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          <?= htmlspecialchars($cari['ad']) ?>
        </a>
        <div class="page-title">Cari Düzenle</div>
      </div>
    </div>

    <div class="page-body">
      <?php if ($error):   ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <div style="max-width:580px">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">Cari Bilgileri</div>
          </div>
          <div style="padding:1.5rem">
            <form method="post" action="/cari-duzenle?id=<?= $id ?>">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group" style="grid-column:1/-1">
                  <label class="form-label">Ad Soyad / Firma <span style="color:var(--red)">*</span></label>
                  <input type="text" name="ad" class="form-input" value="<?= htmlspecialchars($cari['ad']) ?>" required autofocus>
                </div>

                <div class="form-group">
                  <label class="form-label">Telefon</label>
                  <input type="text" name="telefon" class="form-input" value="<?= htmlspecialchars($cari['telefon'] ?? '') ?>" placeholder="0500 000 00 00">
                </div>

                <div class="form-group">
                  <label class="form-label">Müşteri Kodu</label>
                  <input type="text" name="musteri_kodu" class="form-input" value="<?= htmlspecialchars($cari['musteri_kodu'] ?? '') ?>">
                </div>

                <div class="form-group">
                  <label class="form-label">İl</label>
                  <input type="text" name="il" class="form-input" value="<?= htmlspecialchars($cari['il'] ?? '') ?>" placeholder="İstanbul">
                </div>

                <div class="form-group">
                  <label class="form-label">İlçe</label>
                  <input type="text" name="ilce" class="form-input" value="<?= htmlspecialchars($cari['ilce'] ?? '') ?>" placeholder="Kadıköy">
                </div>

                <div class="form-group" style="grid-column:1/-1">
                  <label class="form-label">Adres</label>
                  <textarea name="adres" class="form-input form-textarea" rows="2"><?= htmlspecialchars($cari['adres'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                  <label class="form-label">Vergi No / TC No</label>
                  <input type="text" name="vergi_tc" class="form-input" value="<?= htmlspecialchars($cari['vergi_tc'] ?? '') ?>" placeholder="Vergi veya TC kimlik no">
                </div>

                <div class="form-group">
                  <label class="form-label">Vergi Dairesi</label>
                  <input type="text" name="vergi_dairesi" class="form-input" value="<?= htmlspecialchars($cari['vergi_dairesi'] ?? '') ?>" placeholder="Örn: Kadıköy VD">
                </div>

                <div class="form-group">
                  <label class="form-label">Not</label>
                  <input type="text" name="not_" class="form-input" value="<?= htmlspecialchars($cari['not_'] ?? '') ?>">
                </div>
              </div>

              <div class="form-group" style="margin-bottom:.5rem">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.86rem">
                  <input type="checkbox" name="varsayilan" value="1" <?= !empty($cari['varsayilan']) ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:var(--accent)">
                  <span>Varsayılan cari olarak ayarla</span>
                </label>
                <div class="form-hint">Hızlı satış ekranında otomatik seçilir</div>
              </div>

              <div style="display:flex;gap:.75rem;margin-top:.75rem">
                <button type="submit" class="btn btn-primary">Kaydet</button>
                <a href="/cari?id=<?= $id ?>" class="btn btn-ghost">İptal</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
</body>
</html>
