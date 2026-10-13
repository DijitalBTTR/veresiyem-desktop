<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
girisGerekli();
$uid = userId();
$id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if (!$id) { header('Location: /hatirlaticilar'); exit; }

$row = $pdo->prepare("SELECT * FROM hatirlaticilar WHERE id=? AND user_id=?");
$row->execute([$id, $uid]);
$hat = $row->fetch();
if (!$hat) { header('Location: /hatirlaticilar'); exit; }

$hata = '';
$basari = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['duzenle'])) {
    $baslik        = trim($_POST['baslik'] ?? '');
    $aciklama      = trim($_POST['aciklama'] ?? '');
    $tarih         = $_POST['tarih'] ?? '';
    $saat          = $_POST['saat'] ?? '';
    $hatirlatma_dk = (int)($_POST['hatirlatma_dk'] ?? 0);
    $tutar         = $_POST['tutar'] !== '' ? (float)str_replace(',', '.', $_POST['tutar']) : null;
    $odeme_tip     = in_array($_POST['odeme_tip'] ?? '', ['odeyecek','alacak']) ? $_POST['odeme_tip'] : null;
    if ($tutar !== null && $tutar <= 0) { $tutar = null; $odeme_tip = null; }
    if ($tutar === null) $odeme_tip = null;

    if (!$baslik || !$tarih || !$saat) {
        $hata = 'Başlık, tarih ve saat zorunludur.';
    } else {
        require_once 'includes/sync.php';
        if (!empty($hat['api_id'])) {
            vpsYaz('put', '/hatirlaticilar/' . $hat['api_id'], [
                'baslik' => $baslik, 'aciklama' => $aciklama ?: null,
                'tarih' => $tarih, 'saat' => $saat,
                'hatirlatma_dk' => $hatirlatma_dk, 'tutar' => $tutar, 'odeme_tip' => $odeme_tip,
            ]);
        }
        $pdo->prepare("UPDATE hatirlaticilar SET baslik=?,aciklama=?,tarih=?,saat=?,hatirlatma_dk=?,tutar=?,odeme_tip=? WHERE id=? AND user_id=?")
            ->execute([$baslik, $aciklama ?: null, $tarih, $saat, $hatirlatma_dk, $tutar, $odeme_tip, $id, $uid]);
        header('Location: /hatirlaticilar?basari=duzenlendi');
        exit;
    }
    // hata varsa formu dolu göster
    $hat = array_merge($hat, compact('baslik','aciklama','tarih','saat','hatirlatma_dk','tutar','odeme_tip'));
}

$oncelikler = [0=>'Yok',5=>'5 dakika',10=>'10 dakika',15=>'15 dakika',30=>'30 dakika',60=>'1 saat',120=>'2 saat',1440=>'1 gün önce'];
$activePage = 'hatirlaticilar';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hatırlatıcı Düzenle — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require 'includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require 'includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Hatırlatıcı Düzenle</div>
        <div class="page-sub"><?= htmlspecialchars($hat['baslik']) ?></div>
      </div>
    </div>
    <div class="page-body">
      <?php if ($hata): ?>
      <div class="alert alert-error" style="margin-bottom:1rem">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($hata) ?>
      </div>
      <?php endif; ?>

      <div class="panel" style="max-width:640px;padding:1.5rem">
        <form method="post">
          <input type="hidden" name="duzenle" value="1">
          <input type="hidden" name="id" value="<?= $hat['id'] ?>">
          <div style="display:grid;gap:.85rem">

            <div class="form-group">
              <label class="form-label">Başlık <span style="color:var(--red)">*</span></label>
              <input type="text" name="baslik" class="form-input" value="<?= htmlspecialchars($hat['baslik']) ?>" required>
            </div>

            <div class="form-group">
              <label class="form-label">Açıklama</label>
              <textarea name="aciklama" class="form-input" rows="2"><?= htmlspecialchars($hat['aciklama'] ?? '') ?></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
              <div class="form-group">
                <label class="form-label">Tarih <span style="color:var(--red)">*</span></label>
                <input type="date" name="tarih" class="form-input" value="<?= htmlspecialchars($hat['tarih']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Saat <span style="color:var(--red)">*</span></label>
                <input type="time" name="saat" class="form-input" value="<?= substr($hat['saat'],0,5) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Hatırlatma</label>
                <select name="hatirlatma_dk" class="form-input">
                  <?php foreach ($oncelikler as $dk => $lbl): ?>
                  <option value="<?= $dk ?>" <?= (int)$hat['hatirlatma_dk']===$dk?'selected':'' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Ödeme alanı -->
            <div style="border:1.5px solid var(--border);border-radius:var(--r);padding:1rem;display:grid;gap:.75rem;background:var(--surface2)">
              <div style="font-size:.8rem;font-weight:700;color:var(--ink2)">Ödeme (İsteğe Bağlı)</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Tutar (₺)</label>
                  <input type="number" name="tutar" class="form-input" step="0.01" min="0"
                    value="<?= $hat['tutar'] !== null ? number_format((float)$hat['tutar'],2,'.','') : '' ?>"
                    placeholder="0,00 — boş bırakılabilir">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Ödeme Tipi</label>
                  <select name="odeme_tip" class="form-input">
                    <option value="">— Seçiniz —</option>
                    <option value="odeyecek" <?= ($hat['odeme_tip']??'')==='odeyecek'?'selected':'' ?>>Ödeme Yapacağım (Borç)</option>
                    <option value="alacak"   <?= ($hat['odeme_tip']??'')==='alacak'  ?'selected':'' ?>>Ödeme Alacağım (Alacak)</option>
                  </select>
                </div>
              </div>
            </div>

            <div style="display:flex;gap:.6rem;justify-content:flex-end">
              <a href="/hatirlaticilar" class="btn btn-ghost btn-sm">İptal</a>
              <button type="submit" class="btn btn-primary btn-sm">Kaydet</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>
