<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'cari-ekle';

$error = '';
$form  = ['ad' => '', 'telefon' => '', 'not_' => '', 'varsayilan' => 0,
          'musteri_kodu' => '', 'il' => '', 'ilce' => '', 'adres' => '', 'vergi_tc' => '', 'vergi_dairesi' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol();
    $form['ad']           = trim($_POST['ad'] ?? '');
    $form['telefon']      = trim($_POST['telefon'] ?? '');
    $form['not_']         = trim($_POST['not_'] ?? '');
    $form['varsayilan']   = isset($_POST['varsayilan']) ? 1 : 0;
    $form['musteri_kodu'] = trim($_POST['musteri_kodu'] ?? '');
    $form['il']           = trim($_POST['il'] ?? '');
    $form['ilce']         = trim($_POST['ilce'] ?? '');
    $form['adres']        = trim($_POST['adres'] ?? '');
    $form['vergi_tc']       = trim($_POST['vergi_tc'] ?? '');
    $form['vergi_dairesi']  = trim($_POST['vergi_dairesi'] ?? '');

    if ($form['ad'] === '') {
        $error = 'Cari adı zorunludur.';
    } else {
        $uid = userId();
        if ($form['varsayilan']) {
            $pdo->prepare("UPDATE cariler SET varsayilan=0 WHERE user_id=?")->execute([$uid]);
        }
        // Müşteri kodu otomatik oluştur (il prefix + sıra)
        if ($form['musteri_kodu'] === '') {
            $ilKod = $form['il'] ? mb_strtoupper(mb_substr(preg_replace('/[^a-zA-ZğüşıöçĞÜŞİÖÇ]/u', '', $form['il']), 0, 3, 'UTF-8'), 'UTF-8') : 'MUS';
            $sayRow = $pdo->prepare("SELECT COUNT(*)+1 AS sira FROM cariler WHERE user_id=?");
            $sayRow->execute([$uid]);
            $sira = str_pad($sayRow->fetchColumn(), 3, '0', STR_PAD_LEFT);
            $form['musteri_kodu'] = $ilKod . '-' . $sira;
        }
        $payload = [
            'ad' => $form['ad'], 'telefon' => $form['telefon'] ?: null,
            'not_' => $form['not_'] ?: null, 'varsayilan' => $form['varsayilan'],
            'musteri_kodu' => $form['musteri_kodu'] ?: null, 'il' => $form['il'] ?: null,
            'ilce' => $form['ilce'] ?: null, 'adres' => $form['adres'] ?: null,
            'vergi_tc' => $form['vergi_tc'] ?: null, 'vergi_dairesi' => $form['vergi_dairesi'] ?: null,
        ];
        // Önce VPS'e yaz
        require_once __DIR__ . '/includes/sync.php';
        $apiRes = vpsYaz('post', '/cariler', $payload);
        $apiId  = $apiRes['cari']['id'] ?? $apiRes['data']['id'] ?? null;
        // Sonra SQLite'a kaydet
        $ins = $pdo->prepare("INSERT INTO cariler (user_id, ad, telefon, not_, varsayilan, musteri_kodu, il, ilce, adres, vergi_tc, vergi_dairesi, api_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$uid, $form['ad'], $form['telefon'] ?: null, $form['not_'] ?: null, $form['varsayilan'],
                       $form['musteri_kodu'] ?: null, $form['il'] ?: null, $form['ilce'] ?: null,
                       $form['adres'] ?: null, $form['vergi_tc'] ?: null, $form['vergi_dairesi'] ?: null, $apiId]);
        $localId = $pdo->lastInsertId();
        header('Location: /cari?id=' . $localId);
        exit;
    }
}
$pageTitle = 'Yeni Cari';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yeni Cari — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <a href="/cariler" class="breadcrumb">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          Cariler
        </a>
        <div class="page-title">Yeni Cari Ekle</div>
      </div>
    </div>

    <div class="page-body">
      <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div style="max-width:580px">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--green)"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
              Cari Bilgileri
            </div>
          </div>
          <div style="padding:1.5rem">
            <form method="post" action="/cari-ekle">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group" style="grid-column:1/-1">
                  <label class="form-label">Ad Soyad / Firma <span style="color:var(--red)">*</span></label>
                  <input type="text" name="ad" class="form-input" value="<?= htmlspecialchars($form['ad']) ?>" required autofocus placeholder="Müşteri veya firma adı">
                </div>

                <div class="form-group">
                  <label class="form-label">Telefon</label>
                  <input type="text" name="telefon" class="form-input" value="<?= htmlspecialchars($form['telefon']) ?>" placeholder="0500 000 00 00">
                  <div class="form-hint">WhatsApp hatırlatma için kullanılır</div>
                </div>

                <div class="form-group">
                  <label class="form-label">Müşteri Kodu</label>
                  <input type="text" name="musteri_kodu" class="form-input" value="<?= htmlspecialchars($form['musteri_kodu']) ?>" placeholder="Boş bırakılırsa otomatik oluşur">
                </div>

                <div class="form-group">
                  <label class="form-label">İl</label>
                  <input type="text" name="il" class="form-input" value="<?= htmlspecialchars($form['il']) ?>" placeholder="İstanbul">
                </div>

                <div class="form-group">
                  <label class="form-label">İlçe</label>
                  <input type="text" name="ilce" class="form-input" value="<?= htmlspecialchars($form['ilce']) ?>" placeholder="Kadıköy">
                </div>

                <div class="form-group" style="grid-column:1/-1">
                  <label class="form-label">Adres</label>
                  <textarea name="adres" class="form-input form-textarea" rows="2" placeholder="Açık adres..."><?= htmlspecialchars($form['adres']) ?></textarea>
                </div>

                <div class="form-group">
                  <label class="form-label">Vergi No / TC No</label>
                  <input type="text" name="vergi_tc" class="form-input" value="<?= htmlspecialchars($form['vergi_tc']) ?>" placeholder="Vergi veya TC kimlik no">
                </div>

                <div class="form-group">
                  <label class="form-label">Vergi Dairesi</label>
                  <input type="text" name="vergi_dairesi" class="form-input" value="<?= htmlspecialchars($form['vergi_dairesi']) ?>" placeholder="Örn: Kadıköy VD">
                </div>

                <div class="form-group">
                  <label class="form-label">Not</label>
                  <input type="text" name="not_" class="form-input" value="<?= htmlspecialchars($form['not_']) ?>" placeholder="İsteğe bağlı not">
                </div>
              </div>

              <div class="form-group" style="margin-bottom:.5rem">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.86rem">
                  <input type="checkbox" name="varsayilan" value="1" <?= $form['varsayilan'] ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:var(--accent)">
                  <span>Varsayılan cari olarak ayarla</span>
                </label>
                <div class="form-hint">Hızlı satış ekranında otomatik seçilir</div>
              </div>

              <div style="display:flex;gap:.75rem;margin-top:.75rem">
                <button type="submit" class="btn btn-primary">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Cari Ekle
                </button>
                <a href="/cariler" class="btn btn-ghost">İptal</a>
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
